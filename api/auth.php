<?php
require_once __DIR__ . '/../lib/bootstrap.php';
start_session();

$action = $_GET['action'] ?? '';
$in = json_input();

if ($action === 'me') {
    $u = current_user();
    if (!$u) json_error('Not authenticated', 401);
    json_out(['user' => serialize_user($u), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'register') {
    $email = strtolower(trim($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $name = trim($in['name'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Please enter a valid email');
    if (strlen($password) < 6) json_error('Password must be at least 6 characters');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 24) json_error('Name must be 2-24 characters');
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) json_error('Email already registered');
    $st = db()->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)');
    $st->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)db()->lastInsertId();
    json_out(['user' => serialize_user(current_user()), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'login') {
    $email = strtolower(trim($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) json_error('Invalid email or password', 401);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    json_out(['user' => serialize_user($u), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'claim_bonus') {
    $u = current_user();
    if (!$u) json_error('Not authenticated', 401);
    require_csrf();
    if ((int)$u['chips'] >= DAILY_BONUS_MIN) json_error('Daily chips are only available when your balance is under ' . number_format(DAILY_BONUS_MIN));
    if ($u['last_bonus_at'] && strtotime($u['last_bonus_at']) > time() - 86400) {
        $wait = strtotime($u['last_bonus_at']) + 86400 - time();
        json_error('Next free chips in ' . floor($wait / 3600) . 'h ' . floor(($wait % 3600) / 60) . 'm');
    }
    db()->prepare('UPDATE users SET chips = chips + ?, last_bonus_at = NOW() WHERE id = ?')->execute([DAILY_BONUS_AMOUNT, $u['id']]);
    json_out(['user' => serialize_user(current_user()), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

json_error('Unknown action', 404);
