<?php
// routes/auth.php

function validateCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    // Reject known invalid patterns (all same digit)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    // Validate check digits
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += $cpf[$i] * (($t + 1) - $i);
        }
        $digit = ((10 * $sum) % 11) % 10;
        if ($cpf[$t] != $digit) return false;
    }
    return true;
}

function register($pdo, $body, $jwtSecret) {
    if (empty($body['username']) || empty($body['password']) || empty($body['cpf']) || empty($body['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Preencha todos os campos obrigatórios (Usuário, Senha, CPF, Email).']);
        return;
    }

    if (strlen(trim($body['password'])) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'A senha deve ter no mínimo 6 caracteres.']);
        return;
    }

    // Validate CPF format and check digits
    if (!validateCPF($body['cpf'])) {
        http_response_code(400);
        echo json_encode(['error' => 'CPF inválido. Verifique e tente novamente.']);
        return;
    }

    $username = trim($body['username']);
    $email = trim($body['email']);
    $cpf = trim($body['cpf']);
    $phone = trim($body['phone'] ?? '');
    $invitedBy = trim($body['invitedBy'] ?? '');
    
    // Hash password
    $hashedPassword = password_hash(trim($body['password']), PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? OR cpf = ?');
        $stmt->execute([$username, $email, $cpf]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuário, email ou CPF já cadastrados.']);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO users (username, password, email, phone, cpf, invitedBy, balance, bonusBalance) VALUES (?, ?, ?, ?, ?, ?, 0, 0)');
        $stmt->execute([$username, $hashedPassword, $email, $phone, $cpf, $invitedBy ?: null]);
        $userId = $pdo->lastInsertId();

        if ($invitedBy) {
            $stmtRef = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmtRef->execute([$invitedBy]);
            $referrer = $stmtRef->fetch();
            if ($referrer) {
                $stmtInst = $pdo->prepare('INSERT INTO referrals (referrer_id, referred_user_id, status) VALUES (?, ?, "PENDING")');
                $stmtInst->execute([$referrer['id'], $userId]);
            }
        }

        $tokenPayload = [
            'id' => $userId,
            'username' => $username,
            'exp' => time() + 86400
        ];
        $token = JWT::encode($tokenPayload, $jwtSecret);

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'balance' => 0,
                'bonusBalance' => 0,
                'email' => $email,
                'phone' => $phone,
                'cpf' => $cpf,
                'invitedBy' => $invitedBy ?: null
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao registrar usuário.']);
    }
}

function login($pdo, $body, $jwtSecret) {
    if (empty($body['username']) || empty($body['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Credenciais não podem ser vazias.']);
        return;
    }

    $username = trim($body['username']);
    $password = trim($body['password']);

    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Usuário não encontrado.']);
            return;
        }

        if (!password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Senha incorreta.']);
            return;
        }

        $tokenPayload = [
            'id' => $user['id'],
            'username' => $user['username'],
            'exp' => time() + 86400
        ];
        $token = JWT::encode($tokenPayload, $jwtSecret);

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'balance' => (float)$user['balance'],
                'bonusBalance' => (float)$user['bonusBalance'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'cpf' => $user['cpf'],
                'invitedBy' => $user['invitedBy'],
                'affiliateEarnings' => [
                    'cpa' => (float)($user['cpa_earnings'] ?? 0),
                    'revShare' => (float)($user['revshare_earnings'] ?? 0)
                ]
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao fazer login.']);
    }
}
?>
