<?php
// routes/finance.php

// ============================================================
// HELPER: credit a deposit atomically (used by both confirmDeposit AND webhook)
// Only ONE of them will succeed because of FOR UPDATE lock.
// ============================================================
function _creditDeposit($pdo, $txId, $userId, $fallbackAmount = 0) {
    $searchTerm = '%"txId":"' . $txId . '"%';

    $pdo->beginTransaction();

    // Check if already COMPLETED (race condition guard)
    $stmtDone = $pdo->prepare('SELECT id, amount FROM transactions WHERE details LIKE ? AND type = "DEPOSIT" AND status = "COMPLETED" FOR UPDATE');
    $stmtDone->execute([$searchTerm]);
    $done = $stmtDone->fetch();
    if ($done) {
        $pdo->commit();
        return ['already_done' => true, 'amount' => (float)$done['amount']];
    }

    // Find PENDING
    $stmtPend = $pdo->prepare('SELECT * FROM transactions WHERE details LIKE ? AND type = "DEPOSIT" AND status = "PENDING" FOR UPDATE');
    $stmtPend->execute([$searchTerm]);
    $pending = $stmtPend->fetch();

    if (!$pending) {
        $pdo->rollBack();
        return ['not_found' => true];
    }

    $amount = $fallbackAmount > 0 ? $fallbackAmount : (float)$pending['amount'];

    // Mark COMPLETED
    $pdo->prepare('UPDATE transactions SET status = "COMPLETED", amount = ? WHERE id = ?')
        ->execute([$amount, $pending['id']]);

    // Credit user
    $pdo->prepare('UPDATE users SET balance = balance + ?, totalDeposited = COALESCE(totalDeposited, 0) + ? WHERE id = ?')
        ->execute([$amount, $amount, $pending['user_id']]);

    $pdo->commit();
    return ['credited' => true, 'amount' => $amount, 'userId' => $pending['user_id']];
}

// ============================================================
// HELPER: pay affiliate commissions (CPA + RevShare) after a deposit
// ============================================================
function _payAffiliateCommissions($pdo, $userId, $depositAmount) {
    try {
        $stmtUser = $pdo->prepare('SELECT invitedBy, totalDeposited, username FROM users WHERE id = ?');
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch();

        if (!$user || !$user['invitedBy']) return;

        $settingsStmt = $pdo->query('SELECT * FROM settings');
        $config = [];
        foreach ($settingsStmt->fetchAll() as $s) {
            $config[$s['setting_key']] = $s['setting_value'];
        }

        $cpaValue       = (float)($config['cpaValue'] ?? 10);
        $cpaMinDeposit  = (float)($config['cpaMinDeposit'] ?? 20);
        $revSharePct    = (float)($config['realRevShare'] ?? 20);

        // Only pay CPA if user deposited enough total
        if ($user['totalDeposited'] < $cpaMinDeposit) return;

        $stmtRef = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmtRef->execute([$user['invitedBy']]);
        $referrer = $stmtRef->fetch();
        if (!$referrer) return;

        // Use individual affiliate overrides if set
        $stmtCustom = $pdo->prepare('SELECT custom_cpa, custom_revshare FROM users WHERE id = ?');
        $stmtCustom->execute([$referrer['id']]);
        $custom = $stmtCustom->fetch();
        if ($custom && $custom['custom_cpa'] !== null) $cpaValue = (float)$custom['custom_cpa'];
        $effectiveRevShare = ($custom && $custom['custom_revshare'] !== null) ? (float)$custom['custom_revshare'] : $revSharePct;

        // CPA: one-time, only if not paid yet
        $refCheck = $pdo->prepare('SELECT cpa_paid FROM referrals WHERE referrer_id = ? AND referred_user_id = ?');
        $refCheck->execute([$referrer['id'], $userId]);
        $referral = $refCheck->fetch();
        if ($referral && !$referral['cpa_paid']) {
            $pdo->prepare('UPDATE users SET cpa_earnings = cpa_earnings + ? WHERE id = ?')->execute([$cpaValue, $referrer['id']]);
            $pdo->prepare('UPDATE referrals SET cpa_paid = 1 WHERE referrer_id = ? AND referred_user_id = ?')->execute([$referrer['id'], $userId]);
            $cpaDetails = json_encode(['fromUser' => $user['username']]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "CPA_REWARD", ?, "COMPLETED", ?, NOW())')->execute([$referrer['id'], $cpaValue, $cpaDetails]);
        }

        // RevShare: every deposit
        if ($effectiveRevShare > 0) {
            $revAmt = $depositAmount * ($effectiveRevShare / 100);
            $pdo->prepare('UPDATE users SET revshare_earnings = revshare_earnings + ? WHERE id = ?')->execute([$revAmt, $referrer['id']]);
            $revDetails = json_encode(['fromUser' => $user['username'], 'depositAmount' => $depositAmount, 'percent' => $effectiveRevShare]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "REVSHARE", ?, "COMPLETED", ?, NOW())')->execute([$referrer['id'], $revAmt, $revDetails]);
        }
    } catch (Exception $e) {
        error_log("_payAffiliateCommissions error: " . $e->getMessage());
    }
}

// ============================================================
// CREATE DEPOSIT — gera QR Code no PagViva e salva PENDING
// ============================================================
function createDeposit($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido.']);
        return;
    }

    $amount = (float)($body['amount'] ?? 0);
    $cpf    = trim($body['cpf'] ?? '');

    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$auth['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado.']);
            return;
        }

        $depositCpf = $cpf ?: ($user['cpf'] ?: '');

        if ($cpf && $cpf !== ($user['cpf'] ?? '')) {
            $stmtUpd = $pdo->prepare('UPDATE users SET cpf = ? WHERE id = ?');
            try {
                $stmtUpd->execute([$cpf, $auth['id']]);
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
        $pagVivaConfig = null;
        $settingRows = $stmtSet->fetchAll();
        if (count($settingRows) > 0) $pagVivaConfig = json_decode($settingRows[0]['setting_value'], true);

        if (!$pagVivaConfig || empty($pagVivaConfig['token']) || empty($pagVivaConfig['secret'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Credenciais PagVIVA não configuradas no servidor.']);
            return;
        }

        $postbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/callback';
        $payload = json_encode([
            'postback'               => $postbackUrl,
            'amount'                 => $amount,
            'debtor_name'            => $user['username'],
            'email'                  => !empty($user['email']) ? $user['email'] : 'user@example.com',
            'debtor_document_number' => !empty($depositCpf) ? $depositCpf : '00000000000',
            'phone'                  => !empty($user['phone']) ? $user['phone'] : '00000000000',
            'method_pay'             => 'pix'
        ]);

        $authString = base64_encode($pagVivaConfig['token'] . ':' . $pagVivaConfig['secret']);
        $apiKey     = $pagVivaConfig['apiKey'] ?? '';

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

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("PagVIVA Deposit Request - Amount: $amount, CPF: $depositCpf, HTTP: $httpCode");
        if ($curlError) error_log("PagVIVA CURL Error: $curlError");
        if ($httpCode < 200 || $httpCode >= 300) error_log("PagVIVA Error Response: $response");

        if ($curlError) {
            http_response_code(502);
            echo json_encode(['error' => 'Falha na comunicação com gateway de pagamento. Tente novamente.']);
            return;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $jsonResponse = json_decode($response, true);
            $txId = $jsonResponse['idTransaction'] ?? $jsonResponse['id'] ?? $jsonResponse['transactionId'] ?? $jsonResponse['transaction_id'] ?? null;
            if ($txId) {
                // Save PENDING transaction (only one, only here)
                $details = json_encode(['txId' => $txId, 'method' => 'PIX_PENDING']);
                $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "DEPOSIT", ?, "PENDING", ?, NOW())')
                    ->execute([$auth['id'], $amount, $details]);
            }
            echo $response;
        } else {
            $errorJson    = json_decode($response, true);
            $errorMessage = $errorJson['message'] ?? $errorJson['error'] ?? $errorJson['msg'] ?? 'Erro no gateway de pagamento';
            $errorDetail  = $errorJson['detail'] ?? $errorJson['details'] ?? $errorJson['description'] ?? '';
            http_response_code($httpCode ?: 500);
            $errorResponse = ['error' => $errorMessage];
            if ($errorDetail) $errorResponse['detail'] = $errorDetail;
            echo json_encode($errorResponse);
        }
    } catch (Exception $e) {
        error_log("Deposit Exception: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar depósito. Tente novamente.']);
    }
}

// ============================================================
// CONFIRM DEPOSIT — chamado pelo frontend via polling.
// Verifica o status no PagViva e, se pago, credita via _creditDeposit (com lock).
// O Webhook faz a mesma coisa. APENAS UM dos dois vai critar.
// ============================================================
function confirmDeposit($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso negado']);
        return;
    }

    $txId = trim($body['txId'] ?? '');
    if (!$txId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de transação não fornecido']);
        return;
    }

    try {
        $searchTerm = '%"txId":"' . $txId . '"%';

        // Fast path: already done (no DB lock needed)
        $stmtDone = $pdo->prepare('SELECT id, amount FROM transactions WHERE details LIKE ? AND type = "DEPOSIT" AND status = "COMPLETED"');
        $stmtDone->execute([$searchTerm]);
        $done = $stmtDone->fetch();
        if ($done) {
            echo json_encode(['success' => true, 'message' => 'Depósito já processado.', 'amount' => (float)$done['amount']]);
            return;
        }

        // Check status at PagViva
        $stmtSet     = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "pagViva"');
        $settingRows = $stmtSet->fetchAll();
        $pagVivaConfig = null;
        if (count($settingRows) > 0) $pagVivaConfig = json_decode($settingRows[0]['setting_value'], true);

        if (!$pagVivaConfig || empty($pagVivaConfig['token']) || empty($pagVivaConfig['secret'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Credenciais PagVIVA não configuradas.']);
            return;
        }

        $authString = base64_encode($pagVivaConfig['token'] . ':' . $pagVivaConfig['secret']);
        $apiKey     = $pagVivaConfig['apiKey'] ?? '';

        $ch = curl_init('https://pagviva.com/api/transaction/' . urlencode($txId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $authString,
            'X-API-KEY: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            echo json_encode(['success' => false, 'message' => 'Pagamento ainda não confirmado.']);
            return;
        }

        $jsonResponse = json_decode($response, true);
        $statusUpper  = strtoupper((string)($jsonResponse['status'] ?? $jsonResponse['transactionStatus'] ?? ''));

        if (!in_array($statusUpper, ['APPROVED', 'PAID', 'COMPLETED', 'CONCLUIDO', 'PAGO', '1', 'SUCESSO'])) {
            echo json_encode(['success' => false, 'message' => 'Pagamento ainda não confirmado.']);
            return;
        }

        // Payment confirmed at gateway — credit via atomic helper
        $gatewayAmount = (float)($jsonResponse['amount'] ?? $jsonResponse['payment_value'] ?? $jsonResponse['value'] ?? 0);
        $result = _creditDeposit($pdo, $txId, $auth['id'], $gatewayAmount);

        if (!empty($result['already_done'])) {
            echo json_encode(['success' => true, 'message' => 'Depósito já processado.', 'amount' => $result['amount']]);
            return;
        }

        if (!empty($result['not_found'])) {
            // Webhook already processed everything, or timing issue — return success anyway
            echo json_encode(['success' => true, 'message' => 'Processado pelo sistema.']);
            return;
        }

        // Freshly credited — pay affiliate commissions
        _payAffiliateCommissions($pdo, $auth['id'], $result['amount']);

        echo json_encode(['success' => true, 'amount' => $result['amount']]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("confirmDeposit error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao confirmar depósito.']);
    }
}

// ============================================================
// CHECK DEPOSIT STATUS — polling do frontend, retorna status do PagViva
// ============================================================
function checkDepositStatus($pdo, $id, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido.']);
        return;
    }

    $stmtSet     = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "pagViva"');
    $settingRows = $stmtSet->fetchAll();
    $pagVivaConfig = null;
    if (count($settingRows) > 0) $pagVivaConfig = json_decode($settingRows[0]['setting_value'], true);

    if (!$pagVivaConfig || empty($pagVivaConfig['token']) || empty($pagVivaConfig['secret'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Credenciais PagVIVA não configuradas.']);
        return;
    }

    $authString = base64_encode($pagVivaConfig['token'] . ':' . $pagVivaConfig['secret']);
    $apiKey     = $pagVivaConfig['apiKey'] ?? '';

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

// ============================================================
// REQUEST WITHDRAW — deduz saldo e salva PENDING
// ============================================================
function requestWithdraw($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido ou inválido.']);
        return;
    }

    // Anti-duplicate: block if PENDING withdraw within last 30 seconds
    $stmtDup = $pdo->prepare('SELECT id FROM transactions WHERE user_id = ? AND type = "WITHDRAW" AND status = "PENDING" AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)');
    $stmtDup->execute([$auth['id']]);
    if ($stmtDup->fetch()) {
        http_response_code(429);
        echo json_encode(['error' => 'Aguarde 30 segundos antes de solicitar outro saque.']);
        return;
    }

    $amount  = (float)($body['amount'] ?? 0);
    $pixKey  = trim($body['pixKey'] ?? '');
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

        $stmtSet        = $pdo->query('SELECT setting_value FROM settings WHERE setting_key = "minWithdraw"');
        $minWithdraw    = (float)($stmtSet->fetchColumn() ?: 50.0);

        if ($amount < $minWithdraw) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => "O valor mínimo para saque é R$ {$minWithdraw}."]);
            return;
        }

        $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')->execute([$amount, $auth['id']]);

        $details  = json_encode(['pixKey' => $pixKey, 'pixType' => $pixType]);
        $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "WITHDRAW", ?, "PENDING", ?, NOW())')
            ->execute([$auth['id'], $amount, $details]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Solicitação de saque realizada com sucesso. Em breve será processada.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar solicitação de saque.']);
    }
}

// ============================================================
// PAGVIVA CALLBACK (Webhook) — chamado pelo PagViva quando PIX é pago
// ============================================================
function pagVivaCallback($pdo) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }

    $txId        = $body['idTransaction'] ?? $body['id'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? null;
    $status      = $body['status'] ?? $body['statusTransaction'] ?? '';
    $statusUpper = strtoupper((string)$status);

    if ($txId && in_array($statusUpper, ['APPROVED', 'PAID', 'COMPLETED', 'CONCLUIDO', 'PAGO', '1', 'SUCESSO'])) {
        // Use the same atomic helper — safely ignores if already credited by confirmDeposit
        $gatewayAmount = (float)($body['amount'] ?? $body['payment_value'] ?? $body['value'] ?? 0);
        $result = _creditDeposit($pdo, $txId, 0, $gatewayAmount);

        if (!empty($result['credited'])) {
            // Pay affiliate commissions after webhook credits
            _payAffiliateCommissions($pdo, $result['userId'], $result['amount']);
        }
    }

    echo json_encode(['received' => true]);
}

// ============================================================
// WITHDRAW CALLBACK — chamado pelo PagViva quando saque é processado
// ============================================================
function withdrawCallback($pdo, $body) {
    try {
        $txId   = $body['idTransaction'] ?? $body['id'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? null;
        $status = strtoupper($body['status'] ?? '');

        if ($txId) {
            $searchTerm = '%' . $txId . '%';
            if (in_array($status, ['PAID', 'COMPLETED', 'APPROVED'])) {
                $pdo->prepare('UPDATE transactions SET status = "COMPLETED" WHERE details LIKE ? AND type = "WITHDRAW"')->execute([$searchTerm]);
            } elseif (in_array($status, ['ERROR', 'FAILED', 'CANCELED'])) {
                $pdo->beginTransaction();
                $stmtTx = $pdo->prepare('SELECT * FROM transactions WHERE details LIKE ? AND type = "WITHDRAW" AND status = "PENDING"');
                $stmtTx->execute([$searchTerm]);
                $tx = $stmtTx->fetch();
                if ($tx) {
                    $pdo->prepare('UPDATE transactions SET status = ? WHERE id = ?')->execute([$status, $tx['id']]);
                    $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$tx['amount'], $tx['user_id']]);
                }
                $pdo->commit();
            }
        }
        echo "OK";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo "Error";
    }
}
?>
