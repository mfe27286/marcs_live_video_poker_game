<?php
// One-time installer: creates the database tables from schema.sql. DELETE THIS FILE after running it.
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
$msgs = [];
$ok = false;
if (!file_exists($configFile)) {
    $msgs[] = ['err', 'config.php not found. Copy config.sample.php to config.php and fill in your database details first.'];
} else {
    $c = require $configFile;
    try {
        $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $msgs[] = ['ok', "Connected to database '{$c['db_name']}' as '{$c['db_user']}'."];
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (stripos($stmt, 'CREATE') === false) continue;
            $pdo->exec($stmt);
            preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $stmt, $m);
            $msgs[] = ['ok', 'Table ready: ' . ($m[1] ?? '?')];
        }
        // upgrades for databases created by older versions
        $col = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        foreach ([['users', 'last_bonus_at', 'ALTER TABLE users ADD COLUMN last_bonus_at DATETIME DEFAULT NULL']] as [$table, $column, $ddl]) {
            $col->execute([$table, $column]);
            if ((int)$col->fetchColumn() === 0) { $pdo->exec($ddl); $msgs[] = ['ok', "Upgraded: added $table.$column"]; }
        }
        $ok = true;
    } catch (Throwable $e) {
        $msgs[] = ['err', 'Error: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Royal Hold'em installer</title>
<style>body{font-family:system-ui;background:#0B0E14;color:#e2e8f0;max-width:640px;margin:60px auto;padding:0 20px}
.ok{color:#10B981}.err{color:#EF4444}li{margin:6px 0}a{color:#F59E0B}.warn{background:#F59E0B22;border:1px solid #F59E0B66;padding:12px;border-radius:8px;margin-top:20px}</style></head><body>
<h1>Royal Hold'em – Installer</h1>
<ul><?php foreach ($msgs as [$k, $m]) echo "<li class='$k'>" . htmlspecialchars($m) . "</li>"; ?></ul>
<?php if ($ok): ?>
<p class="ok"><b>Installation complete.</b> <a href="index.php">Open the app →</a></p>
<div class="warn"><b>Important:</b> delete <code>install.php</code> from your server now.</div>
<?php endif; ?>
</body></html>
