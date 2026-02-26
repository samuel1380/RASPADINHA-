<?php
// routes/admin.php

function getPublicConfig($pdo) {
    try {
        $stmt = $pdo->query('SELECT * FROM settings');
        $settings = $stmt->fetchAll();
        $config = [];

        $publicKeys = ['minDeposit', 'minWithdraw', 'prices', 'cpaValue', 'cpaMinDeposit', 'realRevShare', 'fakeRevShare', 'autoWithdrawEnabled', 'autoWithdrawLimit'];

        foreach ($settings as $row) {
            if (in_array($row['setting_key'], $publicKeys)) {
                $decoded = json_decode($row['setting_value'], true);
                $config[$row['setting_key']] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $row['setting_value'];
            }
        }

        echo json_encode($config);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar configurações públicas.']);
    }
}

function adminLogin($body, $jwtSecret) {
    $password = $body['password'] ?? '';
    // Fixed password as user requested
    $adminPassword = getenv('ADMIN_PASSWORD') ?: 'admin123';

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha não fornecida.']);
        return;
    }
    if ($password !== $adminPassword) {
        http_response_code(401);
        echo json_encode(['error' => 'Senha de administrador incorreta.']);
        return;
    }

    $tokenPayload = [
        'role' => 'admin',
        'username' => getenv('ADMIN_USERNAME') ?: 'admin',
        'exp' => time() + 86400
    ];
    $token = JWT::encode($tokenPayload, $jwtSecret);

    echo json_encode(['token' => $token]);
}

function getAdminConfig($pdo, $auth) {
    if (!$auth || empty($auth['role']) || $auth['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        $stmt = $pdo->query('SELECT * FROM settings');
        $settings = $stmt->fetchAll();
        $config = [];

        foreach ($settings as $row) {
            $decoded = json_decode($row['setting_value'], true);
            $config[$row['setting_key']] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $row['setting_value'];
        }

        echo json_encode($config);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar configurações.']);
    }
}

function saveAdminConfig($pdo, $body, $auth) {
    if (!$auth || empty($auth['role']) || $auth['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        if (!empty($body) && is_array($body)) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
            
            foreach ($body as $key => $value) {
                $stringValue = is_array($value) ? json_encode($value) : (string)$value;
                $stmt->execute([$key, $stringValue, $stringValue]);
            }
            $pdo->commit();
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar configurações.', 'details' => $e->getMessage()]);
    }
}

function getAdminUsers($pdo, $auth) {
    if (!$auth || empty($auth['role']) || $auth['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        $stmt = $pdo->query('SELECT * FROM users ORDER BY created_at DESC');
        $users = $stmt->fetchAll();
        $enhancedUsers = [];

        foreach ($users as $u) {
            $stmtRef = $pdo->prepare('SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ?');
            $stmtRef->execute([$u['id']]);
            $refCount = $stmtRef->fetchColumn();

            $stmtTx = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC');
            $stmtTx->execute([$u['id']]);
            $transactions = $stmtTx->fetchAll();

            $txs = [];
            foreach ($transactions as $t) {
                $txs[] = [
                    'id' => (string)$t['id'],
                    'type' => $t['type'],
                    'amount' => (float)$t['amount'],
                    'status' => $t['status'],
                    'timestamp' => strtotime($t['created_at']) * 1000,
                    'details' => json_decode($t['details'], true)
                ];
            }

            $enhancedUsers[] = [
                'id' => $u['id'],
                'username' => $u['username'] ?: "User_{$u['id']}",
                'email' => $u['email'],
                'cpf' => $u['cpf'],
                'phone' => $u['phone'],
                'invitedBy' => $u['invitedBy'],
                'referralCount' => (int)$refCount,
                'balance' => (float)$u['balance'],
                'bonusBalance' => (float)$u['bonusBalance'],
                'totalDeposited' => (float)($u['totalDeposited'] ?? 0),
                'isVip' => (bool)$u['isVip'],
                'vipExpiry' => $u['vipExpiry'],
                'inventory' => is_string($u['inventory']) ? json_decode($u['inventory'], true) : ($u['inventory'] ?: []),
                'transactions' => $txs,
                'created_at' => $u['created_at'],
                'accepts_pix' => (bool)($u['accepts_pix'] ?? 1),
                'is_demo' => (bool)($u['is_demo'] ?? 0),
                'demoBalance' => (float)($u['demoBalance'] ?? 0),
                'custom_revshare' => ($u['custom_revshare'] ?? null) !== null ? (float)$u['custom_revshare'] : null,
                'custom_cpa' => ($u['custom_cpa'] ?? null) !== null ? (float)$u['custom_cpa'] : null
            ];
        }

        echo json_encode($enhancedUsers);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar usuários.']);
    }
}

function getAdminStats($pdo, $auth) {
    if (!$auth || empty($auth['role']) || $auth['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        $totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $totalDeposits = $pdo->query('SELECT SUM(amount) FROM transactions WHERE type = "DEPOSIT" AND status = "COMPLETED"')->fetchColumn();
        $totalWithdrawals = $pdo->query('SELECT SUM(amount) FROM transactions WHERE type = "WITHDRAW" AND status = "COMPLETED"')->fetchColumn();

        echo json_encode([
            'totalUsers' => (int)$totalUsers,
            'totalDeposited' => (float)($totalDeposits ?: 0),
            'totalWithdrawn' => (float)($totalWithdrawals ?: 0)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar estatísticas.']);
    }
}

function updateUser($pdo, $body, $auth, $userId) {
    if (!$auth || empty($auth['role']) || $auth['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        $balance = $body['balance'] ?? 0;
        $bonusBalance = $body['bonusBalance'] ?? 0;
        $isVip = !empty($body['isVip']) ? 1 : 0;
        $vipExpiry = !empty($body['vipExpiry']) ? $body['vipExpiry'] : null;
        $inventory = json_encode($body['inventory'] ?? []);
        $acceptsPix = isset($body['accepts_pix']) ? ($body['accepts_pix'] ? 1 : 0) : 1;
        $customRevshare = (isset($body['custom_revshare']) && $body['custom_revshare'] !== '' && $body['custom_revshare'] !== null) ? (float)$body['custom_revshare'] : null;
        $customCpa = (isset($body['custom_cpa']) && $body['custom_cpa'] !== '' && $body['custom_cpa'] !== null) ? (float)$body['custom_cpa'] : null;
        $isDemoUser = !empty($body['is_demo']) ? 1 : 0;
        $demoBalance = (float)($body['demoBalance'] ?? 0);

        $stmt = $pdo->prepare('UPDATE users SET balance = ?, bonusBalance = ?, isVip = ?, vipExpiry = ?, inventory = ?, accepts_pix = ?, custom_revshare = ?, custom_cpa = ?, is_demo = ?, demoBalance = ? WHERE id = ?');
        $stmt->execute([$balance, $bonusBalance, $isVip, $vipExpiry, $inventory, $acceptsPix, $customRevshare, $customCpa, $isDemoUser, $demoBalance, $userId]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar usuário.']);
    }
}

function deleteUser($pdo, $auth, $userId) {
    if (!$auth || empty($auth['role']) || $auth['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('DELETE FROM referrals WHERE referrer_id = ? OR referred_user_id = ?');
        $stmt->execute([$userId, $userId]);
        
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE user_id = ?');
        $stmt->execute([$userId]);
        
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir usuário.']);
    }
}
?>
