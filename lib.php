<?php

declare(strict_types=1);

session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/manhunt.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA foreign_keys = ON');

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

    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $hasFirstName = false;
    $hasLastName = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'first_name') {
            $hasFirstName = true;
        }
        if (($column['name'] ?? '') === 'last_name') {
            $hasLastName = true;
        }
    }

    if (!$hasFirstName) {
        $pdo->exec('ALTER TABLE users ADD COLUMN first_name TEXT');
    }
    if (!$hasLastName) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_name TEXT');
    }

    $needsBackfill = $pdo->query('SELECT COUNT(*) AS count FROM users WHERE first_name IS NULL OR first_name = "" OR last_name IS NULL OR last_name = ""')->fetch();
    if ((int) ($needsBackfill['count'] ?? 0) > 0) {
        $rows = $pdo->query('SELECT id, full_name FROM users')->fetchAll();
        $updateStmt = $pdo->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name WHERE id = :id');
        foreach ($rows as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name === '') {
                $first = 'Player';
                $last = (string) ($row['id'] ?? '');
            } else {
                $parts = preg_split('/\s+/', $name) ?: [];
                $first = $parts[0] ?? 'Player';
                $last = trim(implode(' ', array_slice($parts, 1)));
                if ($last === '') {
                    $last = 'User';
                }
            }
            $updateStmt->execute([
                'first_name' => $first,
                'last_name' => $last,
                'id' => (int) $row['id'],
            ]);
        }
    }

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

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_invitee_status ON invites(invitee_user_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_inviter_status ON invites(inviter_user_id, status)');

    return $pdo;
}

function nowIso(): string
{
    return gmdate('c');
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
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
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'full_name' => $user['full_name'],
        'graduation_year' => $user['graduation_year'],
        'concentration' => $user['concentration'],
        'mode' => $user['mode'],
        'registration_step' => $user['registration_step'],
        'teammate_user_id' => $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null,
        'payment_status' => $user['payment_status'],
    ];
}

function teammate(?int $teammateUserId): ?array
{
    if (!$teammateUserId) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, first_name, last_name, full_name FROM users WHERE id = :id');
    $stmt->execute(['id' => $teammateUserId]);
    $mate = $stmt->fetch();

    if (!$mate) {
        return null;
    }

    return [
        'id' => (int) $mate['id'],
        'first_name' => $mate['first_name'],
        'last_name' => $mate['last_name'],
        'full_name' => $mate['full_name'],
    ];
}
