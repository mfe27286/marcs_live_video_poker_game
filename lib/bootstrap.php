<?php
// Shared bootstrap: config, DB, session, helpers
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config.php is missing. Copy config.sample.php to config.php and fill in your database details.']);
    exit;
}
$CONFIG = require $configFile;

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    $msg = $e->getMessage();
    if ($e instanceof PDOException && (strpos($msg, 'SQLSTATE[HY000] [') === 0 || stripos($msg, 'Access denied') !== false || stripos($msg, 'Unknown database') !== false)) {
        $msg = "Database connection failed: $msg. Check db_host, db_name, db_user and db_pass in config.php (your host's MySQL server may not be 'localhost').";
    } elseif ($e instanceof PDOException && stripos($msg, "doesn't exist") !== false) {
        $msg = "Database tables are missing: $msg. Run install.php or import schema.sql.";
    }
    echo json_encode(['error' => $msg]);
    exit;
});

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'PHP 7.4 or newer is required (this server runs ' . PHP_VERSION . '). Change it in cPanel -> Select PHP Version / MultiPHP Manager.']);
    exit;
}

require_once __DIR__ . '/Poker.php';
require_once __DIR__ . '/Room.php';

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 7, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('rh_session');
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function json_error(string $msg, int $status = 400): void { json_out(['error' => $msg], $status); }

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'], $token)) json_error('Invalid CSRF token', 403);
}

const DAILY_BONUS_AMOUNT = 1000;
const DAILY_BONUS_MIN = 1000;

function serialize_user(array $u): array {
    $last = !empty($u['last_bonus_at']) ? strtotime($u['last_bonus_at']) : 0;
    $nextAt = max(0, $last + 86400 - time());
    return [
        'id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'avatar' => $u['avatar'],
        'chips' => (int)$u['chips'],
        'bonus' => ['amount' => DAILY_BONUS_AMOUNT, 'eligible' => (int)$u['chips'] < DAILY_BONUS_MIN && $nextAt === 0, 'next_in' => $nextAt, 'min_balance' => DAILY_BONUS_MIN],
        'stats' => [
            'hands_played' => (int)$u['hands_played'], 'hands_won' => (int)$u['hands_won'],
            'best_hand' => $u['best_hand'], 'biggest_pot' => (int)$u['biggest_pot'], 'total_winnings' => (int)$u['total_winnings'],
        ],
    ];
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    return $u ?: null;
}

function require_user(): array {
    $u = current_user();
    if (!$u) json_error('Not authenticated', 401);
    require_csrf();
    return $u;
}

function now_ms(): int { return (int)floor(microtime(true) * 1000); }

