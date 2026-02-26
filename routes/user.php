<?php
// routes/user.php

function getUserMe($pdo, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido ou não fornecido.']);
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$auth['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado.']);
        return;
    }

    $inventory = json_decode($user['inventory'], true);
    if (!is_array($inventory)) {
        $inventory = ['shields' => 0, 'magnets' => 0, 'extraLives' => 0];
    }

    echo json_encode([
        'id' => $user['id'],
        'username' => $user['username'],
        'balance' => (float)$user['balance'],
        'bonusBalance' => (float)$user['bonusBalance'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'cpf' => $user['cpf'],
        'invitedBy' => $user['invitedBy'],
        'totalDeposited' => (float)($user['totalDeposited'] ?? 0),
        'isVip' => (bool)$user['isVip'],
        'vipExpiry' => $user['vipExpiry'] ? strtotime($user['vipExpiry']) * 1000 : 0,
        'inventory' => $inventory,
        'is_demo' => (bool)($user['is_demo'] ?? 0),
        'demoBalance' => (float)($user['demoBalance'] ?? 0),
        'affiliateEarnings' => [
            'cpa' => (float)($user['cpa_earnings'] ?? 0),
            'revShare' => (float)($user['revshare_earnings'] ?? 0)
        ]
    ]);
}

function getAffiliatesStats($pdo, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido ou não fornecido.']);
        return;
    }

    $stmt = $pdo->prepare('SELECT count(*) as count FROM referrals WHERE referrer_id = ?');
    $stmt->execute([$auth['id']]);
    $referralsCount = $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT cpa_earnings, revshare_earnings, username FROM users WHERE id = ?');
    $stmt->execute([$auth['id']]);
    $earnings = $stmt->fetch();

    $stmt = $pdo->prepare('
        SELECT u.username, r.created_at as date, 
        (SELECT SUM(amount) FROM transactions t WHERE t.user_id = r.referred_user_id AND t.type = "DEPOSIT" AND t.status = "COMPLETED") as depositAmount 
        FROM referrals r 
        JOIN users u ON r.referred_user_id = u.id 
        WHERE r.referrer_id = ? 
        ORDER BY r.created_at DESC LIMIT 10
    ');
    $stmt->execute([$auth['id']]);
    $referralsList = $stmt->fetchAll();

    $recentReferrals = array_map(function($r) {
        return [
            'username' => $r['username'],
            'date' => $r['date'],
            'depositAmount' => (float)($r['depositAmount'] ?? 0)
        ];
    }, $referralsList);

    echo json_encode([
        'referrals' => (int)$referralsCount,
        'earnings' => [
            'cpa' => (float)($earnings['cpa_earnings'] ?? 0),
            'revShare' => (float)($earnings['revshare_earnings'] ?? 0)
        ],
        'recentReferrals' => $recentReferrals,
        'referralCode' => $earnings['username']
    ]);
}

function claimAffiliateEarnings($pdo, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso negado.']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT cpa_earnings, revshare_earnings, balance FROM users WHERE id = ?');
        $stmt->execute([$auth['id']]);
        $user = $stmt->fetch();

        $totalEarnings = (float)($user['cpa_earnings'] ?? 0) + (float)($user['revshare_earnings'] ?? 0);

        if ($totalEarnings <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Sem saldo de afiliados para resgatar.']);
            return;
        }

        $pdo->beginTransaction();

        $stmtUpd = $pdo->prepare('UPDATE users SET balance = balance + ?, cpa_earnings = 0, revshare_earnings = 0 WHERE id = ?');
        $stmtUpd->execute([$totalEarnings, $auth['id']]);

        $details = json_encode(['source' => 'CPA + RevShare']);
        $stmtTx = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "AFFILIATE_CLAIM", ?, "COMPLETED", ?, NOW())');
        $stmtTx->execute([$auth['id'], $totalEarnings, $details]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'newBalance' => (float)$user['balance'] + $totalEarnings,
            'claimedAmount' => $totalEarnings
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao resgatar saldo.']);
    }
}

function updateUserCpf($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido ou não fornecido.']);
        return;
    }

    $cpf = trim($body['cpf'] ?? '');
    if (empty($cpf)) {
        http_response_code(400);
        echo json_encode(['error' => 'CPF não fornecido.']);
        return;
    }

    // Reuse validateCPF from auth.php
    $cleanCpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cleanCpf) !== 11) {
        http_response_code(400);
        echo json_encode(['error' => 'CPF inválido. Deve conter 11 dígitos.']);
        return;
    }

    try {
        $stmt = $pdo->prepare('UPDATE users SET cpf = ? WHERE id = ?');
        $stmt->execute([$cleanCpf, $auth['id']]);
        echo json_encode(['success' => true, 'cpf' => $cleanCpf]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(400);
            echo json_encode(['error' => 'Este CPF já está sendo usado por outra conta.']);
            return;
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar CPF.']);
    }
}

function getReferralInfo($code) {
    echo json_encode([
        'redirect' => '/register',
        'invitedBy' => $code,
        'message' => 'Redirecionar usuário para cadastro com código de indicação.'
    ]);
}
?>
