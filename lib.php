<?php

declare(strict_types=1);

session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = getenv('DB_DSN') ?: '';
    $dbUser = getenv('DB_USER') ?: '';
    $dbPass = getenv('DB_PASS') ?: '';

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
