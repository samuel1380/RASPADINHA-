<?php
// routes/finance.php

function createDeposit($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido.']);
        return;
    }

    $amount = (float)($body['amount'] ?? 0);
    $cpf = trim($body['cpf'] ?? '');

    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$auth['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado.']);
            return;
        }

        // ALWAYS use the CPF from the deposit form first, fallback to saved CPF
        $depositCpf = $cpf ?: ($user['cpf'] ?: '');
        
        // Also save/update the CPF in the database if user provided a new one
        if ($cpf && $cpf !== ($user['cpf'] ?? '')) {
            $stmtUpdate = $pdo->prepare('UPDATE users SET cpf = ? WHERE id = ?');
            try {
                $stmtUpdate->execute([$cpf, $auth['id']]);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Este CPF já está sendo usado por outra conta.']);
                    return;
                }
                throw $e;
            }
        }

        $stmtSet = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "pagViva"');
        $settingRows = $stmtSet->fetchAll();
        $pagVivaConfig = null;
        if (count($settingRows) > 0) {
            $pagVivaConfig = json_decode($settingRows[0]['setting_value'], true);
        }

        if (!$pagVivaConfig || empty($pagVivaConfig['token']) || empty($pagVivaConfig['secret'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Credenciais PagVIVA não configuradas no servidor.']);
            return;
        }

        $postbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/callback';
        $payload = json_encode([
            'postback' => $postbackUrl,
            'amount' => $amount,
            'debtor_name' => $user['username'],
            'email' => !empty($user['email']) ? $user['email'] : 'user@example.com',
            'debtor_document_number' => !empty($depositCpf) ? $depositCpf : '00000000000',
            'phone' => !empty($user['phone']) ? $user['phone'] : '00000000000',
            'method_pay' => 'pix'
        ]);

        $authString = base64_encode($pagVivaConfig['token'] . ':' . $pagVivaConfig['secret']);
        $apiKey = $pagVivaConfig['apiKey'] ?? '';

        $ch = curl_init('https://pagviva.com/api/transaction/deposit');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $authString,
            'X-API-KEY: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Log for debugging
        error_log("PagVIVA Deposit Request - Amount: $amount, CPF: $depositCpf, HTTP: $httpCode");
        if ($curlError) {
            error_log("PagVIVA CURL Error: $curlError");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("PagVIVA Error Response: $response");
        }

        if ($curlError) {
            http_response_code(502);
            echo json_encode(['error' => 'Falha na comunicação com gateway de pagamento. Tente novamente.']);
            return;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $jsonResponse = json_decode($response, true);
            $txId = $jsonResponse['idTransaction'] ?? $jsonResponse['id'] ?? $jsonResponse['transactionId'] ?? $jsonResponse['transaction_id'] ?? null;
            if ($txId) {
                $details = json_encode(['txId' => $txId, 'method' => 'PIX_PENDING']);
                $stmtTrans = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "DEPOSIT", ?, "PENDING", ?, NOW())');
                $stmtTrans->execute([$auth['id'], $amount, $details]);
            }
            echo $response;
        } else {
            $errorJson = json_decode($response, true);
            $errorMessage = $errorJson['message'] ?? $errorJson['error'] ?? $errorJson['msg'] ?? 'Erro no gateway de pagamento';
            // Pass through PagVIVA's detailed error for debugging
            $errorDetail = $errorJson['detail'] ?? $errorJson['details'] ?? $errorJson['description'] ?? '';
            http_response_code($httpCode ?: 500);
            $errorResponse = ['error' => $errorMessage];
            if ($errorDetail) {
                $errorResponse['detail'] = $errorDetail;
            }
            echo json_encode($errorResponse);
        }
    } catch (Exception $e) {
        error_log("Deposit Exception: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar depósito. Tente novamente.']);
    }
}

function confirmDeposit($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso negado']);
        return;
    }

    $txId = $body['txId'] ?? '';
    if (!$txId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de transação não fornecido']);
        return;
    }

    try {
        $searchTerm = '%' . $txId . '%';

        // Check if already completed (by webhook or previous confirm call)
        $stmtCheck = $pdo->prepare('SELECT id FROM transactions WHERE details LIKE ? AND type = "DEPOSIT" AND status = "COMPLETED"');
        $stmtCheck->execute([$searchTerm]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['success' => true, 'message' => 'Depósito já processado.']);
            return;
        }

        // Get PagViva config to verify the transaction
        $stmtSet = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "pagViva"');
        $settingRows = $stmtSet->fetchAll();
        $pagVivaConfig = null;
        if (count($settingRows) > 0) {
            $pagVivaConfig = json_decode($settingRows[0]['setting_value'], true);
        }

        if (!$pagVivaConfig || empty($pagVivaConfig['token']) || empty($pagVivaConfig['secret'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Credenciais PagVIVA não configuradas.']);
            return;
        }

        $authString = base64_encode($pagVivaConfig['token'] . ':' . $pagVivaConfig['secret']);
        $apiKey = $pagVivaConfig['apiKey'] ?? '';

        $ch = curl_init('https://pagviva.com/api/transaction/' . urlencode($txId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $authString,
            'X-API-KEY: ' . $apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            http_response_code($httpCode ?: 500);
            echo json_encode(['error' => 'Erro na API PagViva.']);
            return;
        }

        $jsonResponse = json_decode($response, true);
        $status = strtoupper($jsonResponse['status'] ?? $jsonResponse['transactionStatus'] ?? '');

        if ($status !== 'PAID' && $status !== 'COMPLETED') {
            echo json_encode(['success' => false, 'message' => 'Pagamento ainda não confirmado.']);
            return;
        }

        $amount = (float)($jsonResponse['amount'] ?? $jsonResponse['payment_value'] ?? $jsonResponse['value'] ?? 0);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Valor inválido na resposta do PagViva.']);
            return;
        }

        // USE FOR UPDATE to lock the row and prevent race condition with webhook
        $pdo->beginTransaction();

        // Double-check: maybe webhook processed it while we were verifying
        $stmtCheck2 = $pdo->prepare('SELECT id FROM transactions WHERE details LIKE ? AND type = "DEPOSIT" AND status = "COMPLETED" FOR UPDATE');
        $stmtCheck2->execute([$searchTerm]);
        if ($stmtCheck2->fetch()) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Depósito já processado.']);
            return;
        }

        // Find the EXISTING PENDING transaction (created by createDeposit) and UPDATE it
        $stmtPending = $pdo->prepare('SELECT * FROM transactions WHERE details LIKE ? AND type = "DEPOSIT" AND status = "PENDING" FOR UPDATE');
        $stmtPending->execute([$searchTerm]);
        $pendingTx = $stmtPending->fetch();

        if ($pendingTx) {
            // Update existing transaction to COMPLETED (same approach as webhook)
            $stmtUpd = $pdo->prepare('UPDATE transactions SET status = "COMPLETED", amount = ? WHERE id = ?');
            $stmtUpd->execute([$amount, $pendingTx['id']]);

            // Add balance to user
            $stmtBalance = $pdo->prepare('UPDATE users SET balance = balance + ?, totalDeposited = COALESCE(totalDeposited, 0) + ? WHERE id = ?');
            $stmtBalance->execute([$amount, $amount, $auth['id']]);
        } else {
            // No pending transaction found - this shouldn't normally happen
            // DO NOT create a new transaction to avoid double-credit from webhook
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Transação não encontrada, aguardando webhook.']);
            return;
        }

        // CPA logic (affiliate commission on first deposit)
        $stmtUser = $pdo->prepare('SELECT invitedBy, totalDeposited, username FROM users WHERE id = ?');
        $stmtUser->execute([$auth['id']]);
        $user = $stmtUser->fetch();

        $settingsStmt = $pdo->query('SELECT * FROM settings');
        $config = [];
        foreach ($settingsStmt->fetchAll() as $s) {
            $config[$s['setting_key']] = $s['setting_value'];
        }

        $cpaValue = (float)($config['cpaValue'] ?? 10);
        $cpaMinDeposit = (float)($config['cpaMinDeposit'] ?? 20);
        // RevShare global value
        $revSharePercent = (float)($config['realRevShare'] ?? 20);

        if ($user['invitedBy'] && $user['totalDeposited'] >= $cpaMinDeposit) {
            $stmtRef = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmtRef->execute([$user['invitedBy']]);
            $referrer = $stmtRef->fetch();

            if ($referrer) {
                // Check for individual CPA override on the referrer
                $stmtCustomCpa = $pdo->prepare('SELECT custom_cpa, custom_revshare FROM users WHERE id = ?');
                $stmtCustomCpa->execute([$referrer['id']]);
                $referrerCustom = $stmtCustomCpa->fetch();
                if ($referrerCustom && $referrerCustom['custom_cpa'] !== null) {
                    $cpaValue = (float)$referrerCustom['custom_cpa'];
                }
                $effectiveRevShare = ($referrerCustom && $referrerCustom['custom_revshare'] !== null)
                    ? (float)$referrerCustom['custom_revshare']
                    : $revSharePercent;

                $refCheck = $pdo->prepare('SELECT cpa_paid FROM referrals WHERE referrer_id = ? AND referred_user_id = ?');
                $refCheck->execute([$referrer['id'], $auth['id']]);
                $referral = $refCheck->fetch();

                if ($referral && !$referral['cpa_paid']) {
                    $pdo->prepare('UPDATE users SET cpa_earnings = cpa_earnings + ? WHERE id = ?')
                        ->execute([$cpaValue, $referrer['id']]);
                    $pdo->prepare('UPDATE referrals SET cpa_paid = 1 WHERE referrer_id = ? AND referred_user_id = ?')
                        ->execute([$referrer['id'], $auth['id']]);
                    $cpaDetails = json_encode(['fromUser' => $user['username']]);
                    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "CPA_REWARD", ?, "COMPLETED", ?, NOW())')
                        ->execute([$referrer['id'], $cpaValue, $cpaDetails]);
                }

                // RevShare: credit percentage of deposit amount
                if ($effectiveRevShare > 0) {
                    $revShareAmount = $amount * ($effectiveRevShare / 100);
                    $pdo->prepare('UPDATE users SET revshare_earnings = revshare_earnings + ? WHERE id = ?')
                        ->execute([$revShareAmount, $referrer['id']]);
                    $revDetails = json_encode(['fromUser' => $user['username'], 'depositAmount' => $amount, 'percent' => $effectiveRevShare]);
                    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "REVSHARE", ?, "COMPLETED", ?, NOW())')
                        ->execute([$referrer['id'], $revShareAmount, $revDetails]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao confirmar depósito.']);
    }
}

function checkDepositStatus($pdo, $id, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido.']);
        return;
    }

    $stmtSet = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "pagViva"');
    $settingRows = $stmtSet->fetchAll();
    $pagVivaConfig = null;
    if (count($settingRows) > 0) {
        $pagVivaConfig = json_decode($settingRows[0]['setting_value'], true);
    }

    if (!$pagVivaConfig || empty($pagVivaConfig['token']) || empty($pagVivaConfig['secret'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Credenciais PagVIVA não configuradas.']);
        return;
    }

    $authString = base64_encode($pagVivaConfig['token'] . ':' . $pagVivaConfig['secret']);
    $apiKey = $pagVivaConfig['apiKey'] ?? '';

    $ch = curl_init('https://pagviva.com/api/transaction/' . urlencode($id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $authString,
        'X-API-KEY: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo $response;
    } else {
        echo json_encode(['status' => 'PENDING']);
    }
}

function requestWithdraw($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido ou inválido.']);
        return;
    }

    // Anti-duplicate: block if a PENDING withdraw exists in last 30 seconds
    $stmtDup = $pdo->prepare('SELECT id FROM transactions WHERE user_id = ? AND type = "WITHDRAW" AND status = "PENDING" AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)');
    $stmtDup->execute([$auth['id']]);
    if ($stmtDup->fetch()) {
        http_response_code(429);
        echo json_encode(['error' => 'Aguarde 30 segundos antes de solicitar outro saque.']);
        return;
    }

    $amount = (float)($body['amount'] ?? 0);
    $pixKey = trim($body['pixKey'] ?? '');
    $pixType = trim($body['pixKeyType'] ?? $body['pixType'] ?? '');

    if ($amount <= 0 || empty($pixKey) || empty($pixType)) {
        http_response_code(400);
        echo json_encode(['error' => 'Preencha todos os campos obrigatórios (valor, chave PIX, tipo de chave).']);
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$auth['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado.']);
            return;
        }

        if ((float)$user['balance'] < $amount) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Saldo insuficiente para saque.']);
            return;
        }

        // Consultar minWithdraw na tabela settings
        $stmtSet = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "minWithdraw"');
        $minWithdrawSetting = $stmtSet->fetchColumn();
        $minWithdraw = $minWithdrawSetting ? (float)$minWithdrawSetting : 50.0;

        if ($amount < $minWithdraw) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => "O valor mínimo para saque é R$ {$minWithdraw}."]);
            return;
        }

        // Descontar do balance
        $stmtUpdate = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
        $stmtUpdate->execute([$amount, $auth['id']]);

        // Registrar transação como PENDING para admin aprovar ou sistema cron disparar
        $details = json_encode(['pixKey' => $pixKey, 'pixType' => $pixType]);
        $stmtTrans = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "WITHDRAW", ?, "PENDING", ?, NOW())');
        $stmtTrans->execute([$auth['id'], $amount, $details]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Solicitação de saque realizada com sucesso. Em breve será processada.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar solicitação de saque.']);
    }
}

function pagVivaCallback($pdo) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }

    $txId = $body['idTransaction'] ?? $body['id'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? null;
    $status = $body['status'] ?? $body['statusTransaction'] ?? '';
    $statusUpper = strtoupper((string)$status);

    if ($txId && in_array($statusUpper, ['APPROVED', 'PAID', 'COMPLETED', 'CONCLUIDO', 'PAGO', '1', 'SUCESSO'])) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE details LIKE ? AND type = 'DEPOSIT' AND status = 'PENDING' FOR UPDATE");
            $stmt->execute(['%"txId":"' . $txId . '"%']);
            $transaction = $stmt->fetch();

            if ($transaction) {
                $updateTx = $pdo->prepare("UPDATE transactions SET status = 'COMPLETED' WHERE id = ?");
                $updateTx->execute([$transaction['id']]);

                $amount = (float)$transaction['amount'];
                $updateUser = $pdo->prepare("UPDATE users SET balance = balance + ?, totalDeposited = COALESCE(totalDeposited, 0) + ? WHERE id = ?");
                $updateUser->execute([$amount, $amount, $transaction['user_id']]);

                $pdo->commit();
                echo json_encode(['success' => true]);
                return;
            }
            $pdo->rollBack();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
            return;
        }
    }

    echo json_encode(['received' => true]);
}

function withdrawCallback($pdo, $body) {
    try {
        $txId = $body['idTransaction'] ?? $body['id'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? null;
        $status = strtoupper($body['status'] ?? '');

        if ($txId) {
            $searchTerm = '%' . $txId . '%';
            if ($status === 'PAID' || $status === 'COMPLETED') {
                $stmt = $pdo->prepare('UPDATE transactions SET status = "COMPLETED" WHERE details LIKE ? AND type = "WITHDRAW"');
                $stmt->execute([$searchTerm]);
            } else if ($status === 'ERROR' || $status === 'FAILED' || $status === 'CANCELED') {
                $pdo->beginTransaction();
                $stmtTx = $pdo->prepare('SELECT * FROM transactions WHERE details LIKE ? AND type = "WITHDRAW" AND status = "PENDING"');
                $stmtTx->execute([$searchTerm]);
                $tx = $stmtTx->fetch();
                if ($tx) {
                    $stmtUpdTx = $pdo->prepare('UPDATE transactions SET status = ? WHERE id = ?');
                    $stmtUpdTx->execute([$status, $tx['id']]);
                    $stmtUpdUser = $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
                    $stmtUpdUser->execute([$tx['amount'], $tx['user_id']]);
                }
                $pdo->commit();
            }
        }
        echo "OK";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo "Error";
    }
}
?>
