<?php

function app_sqlite_path(): string
{
    return '/home/sysadmin/.local/share/odooexport/app.sqlite';
}

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = app_sqlite_path();
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Settings database directory is not writable: ' . $directory);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    return $pdo;
}

function setting(string $key, string $default = ''): string
{
    $stmt = app_db()->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return $default;
    }
    return (string) $value;
}

function setting_put(string $key, string $value): void
{
    $stmt = app_db()->prepare("INSERT INTO settings (key, value, updated_at)
        VALUES (:key, :value, datetime('now'))
        ON CONFLICT(key) DO UPDATE SET
            value = excluded.value,
            updated_at = datetime('now')");
    $stmt->execute([
        'key' => $key,
        'value' => $value,
    ]);
}
