<?php

declare(strict_types=1);

function mergeConfig(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = mergeConfig($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}

function appConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $basePath = __DIR__ . '/config.php';
    if (!is_file($basePath)) {
        throw new RuntimeException('Missing config.php');
    }

    $loaded = require $basePath;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an array');
    }

    $localPath = __DIR__ . '/config.local.php';
    if (is_file($localPath)) {
        $local = require $localPath;
        if (is_array($local)) {
            $loaded = mergeConfig($loaded, $local);
        }
    }

    $config = $loaded;
    return $config;
}

$config = appConfig();
$timezone = (string) ($config['app']['timezone'] ?? 'UTC');
if ($timezone !== '') {
    date_default_timezone_set($timezone);
}

$sessionName = trim((string) ($config['security']['session_name'] ?? 'dt_manhunt_session'));
if ($sessionName !== '') {
    session_name($sessionName);
}
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = appConfig();
    $dbConfig = is_array($config['database'] ?? null) ? $config['database'] : [];

    $dsn = trim((string) ($dbConfig['dsn'] ?? ''));
    $dbUser = (string) ($dbConfig['user'] ?? '');
    $dbPass = (string) ($dbConfig['pass'] ?? '');

    if ($dsn === '') {
        $dsn = 'sqlite:' . __DIR__ . '/manhunt.sqlite';
    }

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if (str_starts_with($dsn, 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys = ON');
        bootstrapSqlite($pdo);
    }

    return $pdo;
}

function sqliteHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if ((string) ($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function bootstrapSqlite(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT NOT NULL UNIQUE,
            pin_hash TEXT NOT NULL,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            full_name TEXT NOT NULL,
            graduation_year TEXT NOT NULL,
            concentration TEXT NOT NULL,
            mode TEXT,
            registration_step TEXT NOT NULL DEFAULT "profile",
            teammate_user_id INTEGER,
            payment_status TEXT NOT NULL DEFAULT "pending",
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (teammate_user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inviter_user_id INTEGER NOT NULL,
            invitee_user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (inviter_user_id) REFERENCES users(id),
            FOREIGN KEY (invitee_user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    if (!sqliteHasColumn($pdo, 'users', 'is_admin')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_inviter_status ON invites(inviter_user_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_invitee_status ON invites(invitee_user_id, status)');
}

function nowUtc(): string
{
    return gmdate('c');
}

function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function isValidPhone(string $phoneDigits): bool
{
    return (bool) preg_match('/^\d{10,15}$/', $phoneDigits);
}

function registrationOptions(): array
{
    $config = appConfig();
    $registration = is_array($config['registration'] ?? null) ? $config['registration'] : [];

    $yearOptions = [];
    foreach (($registration['graduation_year_options'] ?? []) as $year) {
        $value = trim((string) $year);
        if (preg_match('/^\d{4}$/', $value)) {
            $yearOptions[$value] = $value;
        }
    }

    $concentrationOptions = [];
    foreach (($registration['concentration_options'] ?? []) as $option) {
        $value = trim((string) $option);
        if ($value !== '') {
            $concentrationOptions[$value] = $value;
        }
    }

    return [
        'graduation_year_options' => array_values($yearOptions),
        'concentration_options' => array_values($concentrationOptions),
    ];
}

function configuredAdminPhones(): array
{
    $config = appConfig();
    $adminConfig = is_array($config['admin'] ?? null) ? $config['admin'] : [];
    $phones = [];
    foreach (($adminConfig['allowed_phone_numbers'] ?? []) as $rawPhone) {
        $phone = normalizePhone((string) $rawPhone);
        if (isValidPhone($phone)) {
            $phones[$phone] = $phone;
        }
    }

    return array_values($phones);
}

function userIsAdmin(array $user): bool
{
    if ((int) ($user['is_admin'] ?? 0) === 1) {
        return true;
    }
    $phone = normalizePhone((string) ($user['phone'] ?? ''));
    if ($phone === '') {
        return false;
    }
    return in_array($phone, configuredAdminPhones(), true);
}

function currentUser(): ?array
{
    $id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function currentAdminUser(): ?array
{
    $id = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : 0;
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $admin = $stmt->fetch();
    if (!$admin || !userIsAdmin($admin)) {
        return null;
    }

    return $admin;
}

function requireUser(): array
{
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }

    return $user;
}

function requireAdmin(): array
{
    $admin = currentAdminUser();
    if (!$admin) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Admin authorization required']);
        exit;
    }

    return $admin;
}

function userPublic(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'first_name' => (string) $user['first_name'],
        'last_name' => (string) $user['last_name'],
        'full_name' => (string) $user['full_name'],
        'graduation_year' => (string) $user['graduation_year'],
        'concentration' => (string) $user['concentration'],
        'mode' => $user['mode'] ? (string) $user['mode'] : null,
        'registration_step' => (string) $user['registration_step'],
        'teammate_user_id' => $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null,
        'payment_status' => (string) $user['payment_status'],
        'is_admin' => userIsAdmin($user),
    ];
}

function adminPublic(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'phone' => (string) $user['phone'],
        'is_admin' => userIsAdmin($user),
    ];
}

function teammateFor(?int $userId): ?array
{
    if (!$userId) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, first_name, last_name, full_name FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $mate = $stmt->fetch();
    if (!$mate) {
        return null;
    }

    return [
        'id' => (int) $mate['id'],
        'first_name' => (string) $mate['first_name'],
        'last_name' => (string) $mate['last_name'],
        'full_name' => (string) $mate['full_name'],
    ];
}

function canBeMatched(array $user): bool
{
    return in_array((string) ($user['registration_step'] ?? ''), ['profile', 'mode', 'matchmaking'], true)
        && ($user['teammate_user_id'] ?? null) === null;
}

function appSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }

    return (string) $row['setting_value'];
}

function saveAppSetting(string $key, string $value): void
{
    $pdo = db();
    $updatedAt = nowUtc();
    $update = $pdo->prepare('UPDATE app_settings SET setting_value = :value, updated_at = :updated_at WHERE setting_key = :key');
    $update->execute([
        'value' => $value,
        'updated_at' => $updatedAt,
        'key' => $key,
    ]);
    if ($update->rowCount() > 0) {
        return;
    }

    try {
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
        $insert->execute([
            'key' => $key,
            'value' => $value,
            'updated_at' => $updatedAt,
        ]);
    } catch (Throwable) {
        $update->execute([
            'value' => $value,
            'updated_at' => $updatedAt,
            'key' => $key,
        ]);
    }
}

function venmoLink(): string
{
    $config = appConfig();
    $adminConfig = is_array($config['admin'] ?? null) ? $config['admin'] : [];
    $default = trim((string) ($adminConfig['venmo_link'] ?? ''));
    return trim(appSetting('venmo_link', $default));
}
