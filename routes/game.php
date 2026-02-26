<?php
// routes/game.php

function startGame($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido ou inválido.']);
        return;
    }

    $betAmount = (float)($body['betAmount'] ?? 0);
    if ($betAmount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valor da aposta inválido.']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT balance, is_demo, demoBalance FROM users WHERE id = ?');
        $stmt->execute([$auth['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado.']);
            return;
        }

        $isDemo = (bool)$user['is_demo'];
        $availableBalance = $isDemo ? (float)$user['demoBalance'] : (float)$user['balance'];

        if ($availableBalance < $betAmount) {
            http_response_code(400);
            echo json_encode(['error' => $isDemo ? 'Saldo demo insuficiente.' : 'Saldo insuficiente.']);
            return;
        }

        // Generate Game ID
        $gameId = 'GAME-' . time() . '-' . substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"), 0, 7);

        $pdo->beginTransaction();

        if ($isDemo) {
            $stmtUpdate = $pdo->prepare('UPDATE users SET demoBalance = demoBalance - ? WHERE id = ?');
            $stmtUpdate->execute([$betAmount, $auth['id']]);
        } else {
            $stmtUpdate = $pdo->prepare('UPDATE users SET balance = balance - ?, totalWagered = COALESCE(totalWagered, 0) + ? WHERE id = ?');
            $stmtUpdate->execute([$betAmount, $betAmount, $auth['id']]);
        }

        $stmtSession = $pdo->prepare('INSERT INTO game_sessions (id, user_id, bet_amount, status) VALUES (?, ?, ?, "ACTIVE")');
        $stmtSession->execute([$gameId, $auth['id'], $betAmount]);

        $details = json_encode(['gameId' => $gameId, 'demo' => $isDemo]);
        $stmtTrans = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "BET", ?, "COMPLETED", ?, NOW())');
        $stmtTrans->execute([$auth['id'], $betAmount, $details]);

        $pdo->commit();

        echo json_encode(['success' => true, 'gameId' => $gameId, 'newBalance' => $availableBalance - $betAmount, 'isDemo' => $isDemo]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao iniciar jogo.']);
    }
}

function endGame($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido ou inválido.']);
        return;
    }

    $gameId = $body['gameId'] ?? null;
    $multiplier = (float)($body['multiplier'] ?? 0);

    if (!$gameId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID do jogo não fornecido.']);
        return;
    }
    if ($multiplier < 0 || $multiplier > 1000) {
        http_response_code(400);
        echo json_encode(['error' => 'Multiplicador inválido.']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM game_sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$gameId, $auth['id']]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Sessão de jogo não encontrada.']);
            return;
        }

        if ($session['status'] !== 'ACTIVE') {
            http_response_code(400);
            echo json_encode(['error' => 'Jogo já finalizado.']);
            return;
        }

        $betAmount = (float)$session['bet_amount'];
        $winAmount = $betAmount * $multiplier;
        $status = $winAmount > 0 ? 'COMPLETED' : 'CRASHED';

        // Check if user is demo
        $stmtUsr = $pdo->prepare('SELECT is_demo FROM users WHERE id = ?');
        $stmtUsr->execute([$auth['id']]);
        $usr = $stmtUsr->fetch();
        $isDemo = (bool)($usr['is_demo'] ?? 0);

        $pdo->beginTransaction();

        $stmtUpdate = $pdo->prepare('UPDATE game_sessions SET multiplier = ?, win_amount = ?, status = ? WHERE id = ?');
        $stmtUpdate->execute([$multiplier, $winAmount, $status, $gameId]);

        if ($winAmount > 0) {
            if ($isDemo) {
                $stmtUser = $pdo->prepare('UPDATE users SET demoBalance = demoBalance + ? WHERE id = ?');
            } else {
                $stmtUser = $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
            }
            $stmtUser->execute([$winAmount, $auth['id']]);

            $details = json_encode(['gameId' => $gameId, 'multiplier' => $multiplier, 'demo' => $isDemo]);
            $stmtTrans = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, "WIN", ?, "COMPLETED", ?, NOW())');
            $stmtTrans->execute([$auth['id'], $winAmount, $details]);
        }

        $pdo->commit();

        $balanceField = $isDemo ? 'demoBalance' : 'balance';
        $stmtBal = $pdo->prepare("SELECT $balanceField as currentBalance FROM users WHERE id = ?");
        $stmtBal->execute([$auth['id']]);
        $newBalance = $stmtBal->fetchColumn();

        echo json_encode(['success' => true, 'winAmount' => $winAmount, 'newBalance' => (float)$newBalance, 'isDemo' => $isDemo]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao finalizar jogo.']);
    }
}

function createTransaction($pdo, $body, $auth) {
    if (!$auth || empty($auth['id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido ou inválido.']);
        return;
    }

    $type = $body['type'] ?? 'UNKNOWN';
    $amount = (float)($body['amount'] ?? 0);
    $status = $body['status'] ?? 'PENDING';
    $details = json_encode($body['details'] ?? []);

    try {
        $stmt = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$auth['id'], $type, $amount, $status, $details]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar transação.']);
    }
}
?>
