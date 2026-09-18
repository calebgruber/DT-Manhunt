<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');

function body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function respond(bool $ok, array $payload = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $payload));
    exit;
}

function findUser(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function pairStatus(PDO $pdo, int $userId, int $teammateId): array
{
    $stmt = $pdo->prepare('SELECT id, payment_status, teammate_user_id FROM users WHERE id = :id OR id = :teammate_id');
    $stmt->execute([
        'id' => $userId,
        'teammate_id' => $teammateId,
    ]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row;
    }

    $isMutual = isset($map[$userId], $map[$teammateId])
        && (int) ($map[$userId]['teammate_user_id'] ?? 0) === $teammateId
        && (int) ($map[$teammateId]['teammate_user_id'] ?? 0) === $userId;
    $bothApproved = $isMutual
        && (string) ($map[$userId]['payment_status'] ?? '') === 'approved'
        && (string) ($map[$teammateId]['payment_status'] ?? '') === 'approved';

    return [
        'is_mutual' => $isMutual,
        'both_approved' => $bothApproved,
    ];
}

function normalizeUserIds(mixed $rawIds): array
{
    $parts = [];
    if (is_array($rawIds)) {
        $parts = $rawIds;
    } elseif (is_string($rawIds)) {
        $parts = preg_split('/\s*,\s*/', trim($rawIds)) ?: [];
    }

    $ids = [];
    foreach ($parts as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function loadInbox(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT mr.message_id, mr.is_read, m.body, m.priority, m.recipient_scope, m.created_at
         FROM message_recipients mr
         JOIN messages m ON m.id = mr.message_id
         WHERE mr.user_id = :user_id
         ORDER BY m.id DESC
         LIMIT 25"
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(static fn (array $row): array => [
        'message_id' => (int) $row['message_id'],
        'is_read' => (int) $row['is_read'] === 1,
        'body' => (string) $row['body'],
        'priority' => (string) ($row['priority'] ?? 'info'),
        'recipient_scope' => (string) $row['recipient_scope'],
        'created_at' => (string) $row['created_at'],
    ], $stmt->fetchAll());
}

function userChatGroup(array $user): string
{
    $status = (string) ($user['game_status'] ?? 'in');
    if ($status === 'seeker') {
        return 'seekers';
    }
    if ($status === 'withdrawn' || $status === 'eliminated' || (int) ($user['is_enrolled'] ?? 1) === 0) {
        return 'withdrawn';
    }
    return 'in';
}

function loadGroupChat(PDO $pdo, string $group): array
{
    $stmt = $pdo->prepare(
        "SELECT id, user_id, full_name, body, created_at
         FROM group_chat_messages
         WHERE group_name = :group_name
         ORDER BY id DESC
         LIMIT 50"
    );
    $stmt->execute(['group_name' => $group]);
    $rows = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'full_name' => (string) $row['full_name'],
        'body' => (string) $row['body'],
        'created_at' => (string) $row['created_at'],
    ], $stmt->fetchAll());
    return array_reverse($rows);
}

function killboardData(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, full_name, mode, is_enrolled, game_status
         FROM users
         ORDER BY
            CASE WHEN game_status = 'in' THEN 0
                 WHEN game_status = 'seeker' THEN 1
                 WHEN game_status = 'eliminated' THEN 2
                 ELSE 3 END,
            lower(full_name) ASC"
    );
    $rows = $stmt->fetchAll();

    $counts = [
        'in' => 0,
        'seeker' => 0,
        'eliminated' => 0,
        'withdrawn' => 0,
    ];

    $players = [];
    foreach ($rows as $row) {
        $isEnrolled = (int) ($row['is_enrolled'] ?? 1) === 1;
        $status = (string) ($row['game_status'] ?? 'in');
        if ($status === 'out') {
            $status = 'withdrawn';
        }
        if (!$isEnrolled || $status === 'withdrawn') {
            $status = 'withdrawn';
        } elseif (!in_array($status, ['in', 'seeker', 'eliminated'], true)) {
            $status = 'in';
        }

        if (!array_key_exists($status, $counts)) {
            $status = 'in';
        }
        $counts[$status] += 1;
        $players[] = [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'mode' => $row['mode'] ? (string) $row['mode'] : null,
            'status' => $status,
        ];
    }

    return [
        'counts' => $counts,
        'players' => $players,
    ];
}

function resolveRecipients(PDO $pdo, string $target, ?int $userId, array $groupIds): array
{
    if ($target === 'all_users') {
        $stmt = $pdo->query('SELECT id FROM users');
        return array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());
    }

    if ($target === 'active') {
        $stmt = $pdo->query("SELECT id FROM users WHERE is_enrolled = 1 AND game_status IN ('in', 'seeker')");
        return array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());
    }

    if ($target === 'eliminated') {
        $stmt = $pdo->query("SELECT id FROM users WHERE game_status IN ('eliminated', 'withdrawn') OR is_enrolled = 0");
        return array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());
    }

    if ($target === 'user') {
        return $userId ? [$userId] : [];
    }

    if ($target === 'duo') {
        if (!$userId) {
            return [];
        }
        $user = findUser($pdo, $userId);
        if (!$user) {
            return [];
        }
        $ids = [$userId => $userId];
        $mateId = (int) ($user['teammate_user_id'] ?? 0);
        if ($mateId > 0) {
            $mate = findUser($pdo, $mateId);
            if ($mate && (int) ($mate['teammate_user_id'] ?? 0) === $userId) {
                $ids[$mateId] = $mateId;
            }
        }
        return array_values($ids);
    }

    if ($target === 'group') {
        return $groupIds;
    }

    return [];
}

function gameClockData(): array
{
    $stage = gameStage();
    $startedAt = trim(appSetting('game_started_at', ''));
    $hideSeconds = max(0, (int) appSetting('hide_duration_seconds', '300'));
    $seekSeconds = max(0, (int) appSetting('seek_duration_seconds', '3600'));
    $clockMode = strtolower(trim(appSetting('clock_mode', 'countdown')));
    if (!in_array($clockMode, ['countdown', 'countup'], true)) {
        $clockMode = 'countdown';
    }

    $phase = 'idle';
    $seconds = $clockMode === 'countup' ? 0 : $seekSeconds;
    $direction = $clockMode === 'countup' ? 'up' : 'down';
    $running = false;

    if ($startedAt !== '') {
        $startedTs = strtotime($startedAt);
        if ($startedTs !== false) {
            $elapsed = max(0, time() - $startedTs);
            $running = $stage === 'live';
            if ($elapsed < $hideSeconds) {
                $phase = 'hide';
                $seconds = $hideSeconds - $elapsed;
                $direction = 'down';
            } else {
                $phase = 'seek';
                $seekElapsed = $elapsed - $hideSeconds;
                if ($clockMode === 'countdown') {
                    $seconds = max(0, $seekSeconds - $seekElapsed);
                    $direction = 'down';
                    if ($seconds === 0) {
                        $phase = 'ended';
                    }
                } else {
                    $seconds = $seekElapsed;
                    $direction = 'up';
                }
            }
        }
    }

    return [
        'stage' => $stage,
        'phase' => $phase,
        'seconds' => $seconds,
        'direction' => $direction,
        'running' => $running,
        'clock_mode' => $clockMode,
        'hide_duration_seconds' => $hideSeconds,
        'seek_duration_seconds' => $seekSeconds,
        'started_at' => $startedAt,
    ];
}

function dashboardPayload(PDO $pdo, array $user): array
{
    $uid = (int) $user['id'];
    $inbox = loadInbox($pdo, $uid);
    $unread = 0;
    foreach ($inbox as $msg) {
        if (!$msg['is_read']) {
            $unread += 1;
        }
    }

    $incStmt = $pdo->prepare(
        "SELECT id, incident_type, severity, details, status, created_at
         FROM incidents
         WHERE reporter_user_id = :uid
         ORDER BY id DESC
         LIMIT 10"
    );
    $incStmt->execute(['uid' => $uid]);
    $incidents = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'incident_type' => (string) $row['incident_type'],
        'severity' => (string) $row['severity'],
        'details' => (string) $row['details'],
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
    ], $incStmt->fetchAll());

    $teammate = teammateFor($user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null);
    $selfLocation = [
        'latitude' => $user['latitude'] === null ? null : (float) $user['latitude'],
        'longitude' => $user['longitude'] === null ? null : (float) $user['longitude'],
        'updated_at' => $user['location_updated_at'] ? (string) $user['location_updated_at'] : null,
    ];
    $teammateLocation = null;
    if ($teammate) {
        $mateRow = findUser($pdo, (int) $teammate['id']);
        if ($mateRow) {
            $teammateLocation = [
                'latitude' => $mateRow['latitude'] === null ? null : (float) $mateRow['latitude'],
                'longitude' => $mateRow['longitude'] === null ? null : (float) $mateRow['longitude'],
                'updated_at' => $mateRow['location_updated_at'] ? (string) $mateRow['location_updated_at'] : null,
            ];
        }
    }

    $killboard = killboardData($pdo);
    $clock = gameClockData();
    $chatGroup = userChatGroup($user);
    $chatMessages = loadGroupChat($pdo, $chatGroup);

    return [
        'game_stage' => gameStage(),
        'announcement' => gameAnnouncement(),
        'game_info' => trim(appSetting('game_info', '')),
        'inbox' => $inbox,
        'unread_messages' => $unread,
        'active_alerts' => $inbox,
        'incidents' => $incidents,
        'killboard' => $killboard,
        'stats' => $killboard['counts'],
        'clock' => $clock,
        'chat_group' => $chatGroup,
        'chat_messages' => $chatMessages,
        'duo' => [
            'teammate' => $teammate,
            'self_location' => $selfLocation,
            'teammate_location' => $teammateLocation,
        ],
        'role' => (string) ($user['game_status'] ?? 'in') === 'seeker' ? 'seeker' : 'hider',
    ];
}

$input = body();
$action = (string) ($input['action'] ?? $_GET['action'] ?? '');
if ($action === 'withdraw_game') {
    $action = 'unenroll';
}
$pdo = db();
$registrationOptions = registrationOptions();
$allowedYears = $registrationOptions['graduation_year_options'];
$allowedConcentrations = $registrationOptions['concentration_options'];

try {
    if ($action === 'register') {
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $phone = normalizePhone((string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        $graduationYear = trim((string) ($input['graduation_year'] ?? ''));
        $concentration = trim((string) ($input['concentration'] ?? ''));

        if ($firstName === '' || $lastName === '' || $phone === '' || $pin === '' || $graduationYear === '' || $concentration === '') {
            respond(false, ['message' => 'All fields are required.'], 422);
        }
        if (!isValidPhone($phone)) {
            respond(false, ['message' => 'Phone number must be 10 to 15 digits.'], 422);
        }
        if (!preg_match('/^\d{4}$/', $graduationYear)) {
            respond(false, ['message' => 'Graduation year must be 4 digits.'], 422);
        }
        if ($allowedYears !== [] && !in_array($graduationYear, $allowedYears, true)) {
            respond(false, ['message' => 'Select a valid graduation year.'], 422);
        }
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            respond(false, ['message' => 'PIN must be 4-8 digits.'], 422);
        }
        if ($allowedConcentrations !== [] && !in_array($concentration, $allowedConcentrations, true)) {
            respond(false, ['message' => 'Select a valid concentration.'], 422);
        }

        $existing = $pdo->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1');
        $existing->execute(['phone' => $phone]);
        if ($existing->fetch()) {
            respond(false, ['message' => 'Phone number already in use.'], 409);
        }

        $fullName = trim($firstName . ' ' . $lastName);
        $isAdmin = in_array($phone, configuredAdminPhones(), true) ? 1 : 0;
        $now = nowUtc();
        $stmt = $pdo->prepare(
            "INSERT INTO users (phone, pin_hash, first_name, last_name, full_name, graduation_year, concentration, mode, registration_step, teammate_user_id, payment_status, is_admin, created_at, updated_at)
             VALUES (:phone, :pin_hash, :first_name, :last_name, :full_name, :graduation_year, :concentration, NULL, 'profile', NULL, 'pending', :is_admin, :created_at, :updated_at)"
        );
        $stmt->execute([
            'phone' => $phone,
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'graduation_year' => $graduationYear,
            'concentration' => $concentration,
            'is_admin' => $isAdmin,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        respond(true, ['user' => userPublic(currentUser())]);
    }

    if ($action === 'login') {
        $phone = normalizePhone((string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        if ($phone === '' || $pin === '') {
            respond(false, ['message' => 'Phone and PIN are required.'], 422);
        }
        if (!isValidPhone($phone)) {
            respond(false, ['message' => 'Phone number must be 10 to 15 digits.'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pin, (string) $user['pin_hash'])) {
            respond(false, ['message' => 'Invalid credentials.'], 401);
        }

        $_SESSION['user_id'] = (int) $user['id'];
        respond(true, ['user' => userPublic(currentUser())]);
    }

    if ($action === 'logout') {
        unset($_SESSION['user_id']);
        respond(true);
    }

    if ($action === 'admin_login') {
        $phone = normalizePhone((string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        if ($phone === '' || $pin === '') {
            respond(false, ['message' => 'Phone and PIN are required.'], 422);
        }
        if (!isValidPhone($phone)) {
            respond(false, ['message' => 'Phone number must be 10 to 15 digits.'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pin, (string) $user['pin_hash']) || !userIsAdmin($user)) {
            respond(false, ['message' => 'Invalid admin credentials.'], 401);
        }

        $_SESSION['admin_user_id'] = (int) $user['id'];
        respond(true, [
            'admin' => adminPublic($user),
            'venmo_link' => venmoLink(),
            'game_stage' => gameStage(),
            'announcement' => gameAnnouncement(),
            'game_info' => trim(appSetting('game_info', '')),
        ]);
    }

    if ($action === 'admin_logout') {
        unset($_SESSION['admin_user_id']);
        respond(true);
    }

    if ($action === 'state') {
        $user = requireUser();
        respond(true, [
            'user' => userPublic($user),
            'teammate' => teammateFor($user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null),
            'venmo_link' => venmoLink(),
            'dashboard' => dashboardPayload($pdo, $user),
        ]);
    }

    if ($action === 'admin_state') {
        $admin = requireAdmin();
        $clock = gameClockData();
        respond(true, [
            'admin' => adminPublic($admin),
            'venmo_link' => venmoLink(),
            'game_stage' => gameStage(),
            'announcement' => gameAnnouncement(),
            'game_info' => trim(appSetting('game_info', '')),
            'killboard' => killboardData($pdo),
            'clock' => $clock,
            'clock_mode' => $clock['clock_mode'],
            'hide_duration_seconds' => $clock['hide_duration_seconds'],
            'seek_duration_seconds' => $clock['seek_duration_seconds'],
        ]);
    }

    if ($action === 'acknowledge_alert') {
        $user = requireUser();
        $stmt = $pdo->prepare('UPDATE users SET pending_alert = \'\', updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'updated_at' => nowUtc(),
            'id' => (int) $user['id'],
        ]);
        respond(true);
    }

    if ($action === 'mark_message_read') {
        $user = requireUser();
        $messageId = (int) ($input['message_id'] ?? 0);
        if ($messageId <= 0) {
            respond(false, ['message' => 'Invalid message.'], 422);
        }
        $stmt = $pdo->prepare(
            'UPDATE message_recipients SET is_read = 1, read_at = :read_at WHERE message_id = :message_id AND user_id = :user_id'
        );
        $stmt->execute([
            'read_at' => nowUtc(),
            'message_id' => $messageId,
            'user_id' => (int) $user['id'],
        ]);
        respond(true);
    }

    if ($action === 'unenroll') {
        $user = requireUser();

        $pdo->beginTransaction();
        try {
            $fresh = findUser($pdo, (int) $user['id']);
            if (!$fresh) {
                throw new RuntimeException('User not found.');
            }

            $updatedAt = nowUtc();
            $mateId = (int) ($fresh['teammate_user_id'] ?? 0);

            $unenroll = $pdo->prepare(
                "UPDATE users
                 SET is_enrolled = 0,
                     game_status = 'withdrawn',
                     teammate_user_id = NULL,
                     mode = NULL,
                     pending_alert = '',
                     registration_step = 'mode',
                     updated_at = :updated_at
                 WHERE id = :id"
            );
            $unenroll->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $fresh['id'],
            ]);

            if ($mateId > 0) {
                $mate = findUser($pdo, $mateId);
                if ($mate && (int) ($mate['teammate_user_id'] ?? 0) === (int) $fresh['id']) {
                    $alert = $fresh['full_name'] . ' unenrolled. You were switched to solo mode.';
                    $shiftMate = $pdo->prepare(
                        "UPDATE users
                         SET teammate_user_id = NULL,
                             mode = 'solo',
                             registration_step = CASE WHEN payment_status = 'approved' THEN 'complete' ELSE 'payment' END,
                             pending_alert = :alert,
                             updated_at = :updated_at
                         WHERE id = :id"
                    );
                    $shiftMate->execute([
                        'alert' => $alert,
                        'updated_at' => $updatedAt,
                        'id' => $mateId,
                    ]);
                }
            }

            $cancel = $pdo->prepare(
                "UPDATE invites
                 SET status = 'cancelled', updated_at = :updated_at
                 WHERE status = 'pending'
                   AND (inviter_user_id = :id OR invitee_user_id = :id OR inviter_user_id = :mate_id OR invitee_user_id = :mate_id)"
            );
            $cancel->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $fresh['id'],
                'mate_id' => $mateId,
            ]);

            $pdo->commit();
            respond(true, ['user' => userPublic(currentUser())]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($action === 'send_group_chat') {
                $user = requireUser();
                $body = trim((string) ($input['body'] ?? ''));
                if ($body === '') {
                    respond(false, ['message' => 'Message is required.'], 422);
                }
                if (mb_strlen($body) > 1000) {
                    respond(false, ['message' => 'Message is too long.'], 422);
                }

                $group = userChatGroup($user);
                $stmt = $pdo->prepare(
                    'INSERT INTO group_chat_messages (user_id, full_name, group_name, body, created_at) VALUES (:user_id, :full_name, :group_name, :body, :created_at)'
                );
                $stmt->execute([
                    'user_id' => (int) $user['id'],
                    'full_name' => (string) $user['full_name'],
                    'group_name' => $group,
                    'body' => $body,
                    'created_at' => nowUtc(),
                ]);
                respond(true);
            }

            respond(false, ['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'report_incident') {
        $user = requireUser();
        $type = trim((string) ($input['incident_type'] ?? ''));
        $severity = strtolower(trim((string) ($input['severity'] ?? '')));
        $details = trim((string) ($input['details'] ?? ''));

        if ($type === '' || $details === '') {
            respond(false, ['message' => 'Incident type and details are required.'], 422);
        }
        if (!in_array($severity, ['low', 'medium', 'high', 'emergency'], true)) {
            respond(false, ['message' => 'Severity must be low, medium, high, or emergency.'], 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO incidents (reporter_user_id, incident_type, severity, details, status, created_at, updated_at) VALUES (:reporter, :incident_type, :severity, :details, :status, :created_at, :updated_at)'
        );
        $now = nowUtc();
        $stmt->execute([
            'reporter' => (int) $user['id'],
            'incident_type' => $type,
            'severity' => $severity,
            'details' => $details,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        respond(true);
    }

    if ($action === 'update_location') {
        $user = requireUser();
        $lat = (float) ($input['latitude'] ?? 0);
        $lng = (float) ($input['longitude'] ?? 0);
        $accuracy = (float) ($input['accuracy'] ?? 0);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            respond(false, ['message' => 'Invalid coordinates.'], 422);
        }

        $stmt = $pdo->prepare(
            'UPDATE users SET latitude = :lat, longitude = :lng, location_accuracy = :acc, location_updated_at = :location_updated_at, updated_at = :updated_at WHERE id = :id'
        );
        $now = nowUtc();
        $stmt->execute([
            'lat' => $lat,
            'lng' => $lng,
            'acc' => $accuracy,
            'location_updated_at' => $now,
            'updated_at' => $now,
            'id' => (int) $user['id'],
        ]);
        respond(true);
    }

    if ($action === 'set_step') {
        $user = requireUser();
        $step = trim((string) ($input['step'] ?? ''));
        if (!((string) $user['registration_step'] === 'profile' && $step === 'mode')) {
            respond(false, ['message' => 'Invalid step transition.'], 422);
        }

        $stmt = $pdo->prepare("UPDATE users SET registration_step = 'mode', updated_at = :updated_at WHERE id = :id");
        $stmt->execute(['updated_at' => nowUtc(), 'id' => (int) $user['id']]);
        respond(true, ['user' => userPublic(currentUser())]);
    }

    if ($action === 'set_mode') {
        $user = requireUser();
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        if (!in_array($mode, ['solo', 'duo'], true)) {
            respond(false, ['message' => 'Mode must be solo or duo.'], 422);
        }
        if ((string) $user['registration_step'] !== 'mode') {
            respond(false, ['message' => 'Mode can only be selected on the mode step.'], 422);
        }
        $stage = gameStage();
        if ((string) ($user['game_status'] ?? 'in') === 'withdrawn' && in_array($stage, ['live', 'paused'], true)) {
            respond(false, ['message' => 'You can rejoin registration after the active game ends.'], 409);
        }

        $next = $mode === 'solo' ? 'payment' : 'matchmaking';
        $stmt = $pdo->prepare("UPDATE users SET mode = :mode, teammate_user_id = NULL, registration_step = :registration_step, updated_at = :updated_at WHERE id = :id");
        $stmt->execute([
            'mode' => $mode,
            'registration_step' => $next,
            'updated_at' => nowUtc(),
            'id' => (int) $user['id'],
        ]);

        $cancel = $pdo->prepare("UPDATE invites SET status = 'cancelled', updated_at = :updated_at WHERE status = 'pending' AND (inviter_user_id = :id OR invitee_user_id = :id)");
        $cancel->execute([
            'updated_at' => nowUtc(),
            'id' => (int) $user['id'],
        ]);

        respond(true, ['user' => userPublic(currentUser())]);
    }

    if ($action === 'switch_to_solo') {
        $user = requireUser();
        if (($user['mode'] ?? null) !== 'duo' || (string) $user['registration_step'] !== 'matchmaking') {
            respond(false, ['message' => 'You can only switch to solo during duo matchmaking.'], 422);
        }
        $stage = gameStage();
        if ((string) ($user['game_status'] ?? 'in') === 'withdrawn' && in_array($stage, ['live', 'paused'], true)) {
            respond(false, ['message' => 'You can rejoin registration after the active game ends.'], 409);
        }

        $pdo->beginTransaction();
        try {
            $updatedAt = nowUtc();
            $updateUser = $pdo->prepare(
                "UPDATE users
                 SET mode = 'solo',
                     teammate_user_id = NULL,
                     registration_step = 'payment',
                     updated_at = :updated_at
                 WHERE id = :id"
            );
            $updateUser->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $user['id'],
            ]);
            $cancelInvites = $pdo->prepare(
                "UPDATE invites
                 SET status = 'cancelled', updated_at = :updated_at
                 WHERE status = 'pending'
                   AND (inviter_user_id = :id OR invitee_user_id = :id)"
            );
            $cancelInvites->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $user['id'],
            ]);
            $pdo->commit();
            respond(true, ['user' => userPublic(currentUser())]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'search_users') {
        $user = requireUser();
        if (($user['mode'] ?? null) !== 'duo' || (string) $user['registration_step'] !== 'matchmaking') {
            respond(false, ['message' => 'Search is only available in duo matchmaking.'], 422);
        }
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            respond(true, ['results' => []]);
        }

        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, full_name, graduation_year, concentration, registration_step, teammate_user_id, mode, is_enrolled, game_status
             FROM users
             WHERE id <> :id
               AND lower(full_name) LIKE lower(:query)
               AND (mode IS NULL OR mode = 'duo')
             ORDER BY last_name ASC, first_name ASC
             LIMIT 20"
        );
        $stmt->execute([
            'id' => (int) $user['id'],
            'query' => '%' . $query . '%',
        ]);

        $eligible = [];
        foreach ($stmt->fetchAll() as $row) {
            if (canBeMatched($row)) {
                $eligible[] = [
                    'id' => (int) $row['id'],
                    'first_name' => (string) $row['first_name'],
                    'last_name' => (string) $row['last_name'],
                    'full_name' => (string) $row['full_name'],
                    'graduation_year' => (string) $row['graduation_year'],
                    'concentration' => (string) $row['concentration'],
                ];
            }
        }

        respond(true, ['results' => $eligible]);
    }

    if ($action === 'send_invite') {
        $user = requireUser();
        if (($user['mode'] ?? null) !== 'duo' || (string) $user['registration_step'] !== 'matchmaking') {
            respond(false, ['message' => 'Invites can only be sent in duo matchmaking.'], 422);
        }
        $stage = gameStage();
        if ((string) ($user['game_status'] ?? 'in') === 'withdrawn' && in_array($stage, ['live', 'paused'], true)) {
            respond(false, ['message' => 'You can rejoin registration after the active game ends.'], 409);
        }

        $inviteeId = (int) ($input['invitee_user_id'] ?? 0);
        if ($inviteeId <= 0 || $inviteeId === (int) $user['id']) {
            respond(false, ['message' => 'Invalid invite target.'], 422);
        }

        $target = findUser($pdo, $inviteeId);
        if (!$target) {
            respond(false, ['message' => 'User not found.'], 404);
        }
        if (!canBeMatched($target)) {
            respond(false, ['message' => 'User is not available for matchmaking.'], 409);
        }

        $selfPending = $pdo->prepare("SELECT id FROM invites WHERE inviter_user_id = :id AND status = 'pending' LIMIT 1");
        $selfPending->execute(['id' => (int) $user['id']]);
        if ($selfPending->fetch()) {
            respond(false, ['message' => 'You already have a pending invite.'], 409);
        }
        $targetPending = $pdo->prepare("SELECT id FROM invites WHERE invitee_user_id = :id AND status = 'pending' LIMIT 1");
        $targetPending->execute(['id' => $inviteeId]);
        if ($targetPending->fetch()) {
            respond(false, ['message' => 'That user already has a pending invite.'], 409);
        }

        $stmt = $pdo->prepare("INSERT INTO invites (inviter_user_id, invitee_user_id, status, created_at, updated_at) VALUES (:inviter, :invitee, 'pending', :created_at, :updated_at)");
        $stmt->execute([
            'inviter' => (int) $user['id'],
            'invitee' => $inviteeId,
            'created_at' => nowUtc(),
            'updated_at' => nowUtc(),
        ]);
        respond(true);
    }

    if ($action === 'matchmaking_state') {
        $user = requireUser();
        $incomingStmt = $pdo->prepare(
            "SELECT i.id, i.created_at, i.inviter_user_id, u.full_name AS inviter_full_name
             FROM invites i
             JOIN users u ON u.id = i.inviter_user_id
             WHERE i.invitee_user_id = :id AND i.status = 'pending'
             ORDER BY i.id DESC"
        );
        $incomingStmt->execute(['id' => (int) $user['id']]);
        $incoming = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'inviter_user_id' => (int) $row['inviter_user_id'],
            'inviter_full_name' => (string) $row['inviter_full_name'],
        ], $incomingStmt->fetchAll());

        $outgoingStmt = $pdo->prepare(
            "SELECT i.id, i.status, i.updated_at, i.invitee_user_id, u.full_name AS invitee_full_name
             FROM invites i
             JOIN users u ON u.id = i.invitee_user_id
             WHERE i.inviter_user_id = :id
             ORDER BY i.id DESC
             LIMIT 1"
        );
        $outgoingStmt->execute(['id' => (int) $user['id']]);
        $outgoing = $outgoingStmt->fetch() ?: null;
        if ($outgoing) {
            $outgoing['id'] = (int) $outgoing['id'];
            $outgoing['invitee_user_id'] = (int) $outgoing['invitee_user_id'];
        }

        $fresh = currentUser();
        respond(true, [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'user' => userPublic($fresh),
            'teammate' => teammateFor($fresh['teammate_user_id'] ? (int) $fresh['teammate_user_id'] : null),
        ]);
    }

    if ($action === 'respond_invite') {
        $user = requireUser();
        if ((string) $user['registration_step'] !== 'matchmaking') {
            respond(false, ['message' => 'Invite responses are only allowed in matchmaking.'], 422);
        }
        $stage = gameStage();
        if ((string) ($user['game_status'] ?? 'in') === 'withdrawn' && in_array($stage, ['live', 'paused'], true)) {
            respond(false, ['message' => 'You can rejoin registration after the active game ends.'], 409);
        }
        $inviteId = (int) ($input['invite_id'] ?? 0);
        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        if (!in_array($decision, ['accept', 'decline'], true)) {
            respond(false, ['message' => 'Decision must be accept or decline.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $inviteStmt = $pdo->prepare('SELECT * FROM invites WHERE id = :id LIMIT 1');
            $inviteStmt->execute(['id' => $inviteId]);
            $invite = $inviteStmt->fetch();
            if (!$invite || (int) $invite['invitee_user_id'] !== (int) $user['id']) {
                throw new RuntimeException('Invite not found.');
            }
            if ((string) $invite['status'] !== 'pending') {
                throw new RuntimeException('Invite is no longer pending.');
            }

            $inviterId = (int) $invite['inviter_user_id'];
            $inviteeId = (int) $invite['invitee_user_id'];
            $inviter = findUser($pdo, $inviterId);
            $invitee = findUser($pdo, $inviteeId);
            if (!$inviter || !$invitee) {
                throw new RuntimeException('User state changed.');
            }

            if ($decision === 'decline') {
                $mark = $pdo->prepare("UPDATE invites SET status = 'declined', updated_at = :updated_at WHERE id = :id");
                $mark->execute(['updated_at' => nowUtc(), 'id' => $inviteId]);
                $rewind = $pdo->prepare(
                    "UPDATE users
                     SET registration_step = CASE
                        WHEN mode = 'duo' AND registration_step IN ('matchmaking', 'payment') THEN 'matchmaking'
                        ELSE registration_step
                     END,
                     updated_at = :updated_at
                     WHERE id = :inviter OR id = :invitee"
                );
                $rewind->execute([
                    'updated_at' => nowUtc(),
                    'inviter' => $inviterId,
                    'invitee' => $inviteeId,
                ]);
            } else {
                if (!canBeMatched($inviter) || !canBeMatched($invitee)) {
                    throw new RuntimeException('One or both users are no longer available for matching.');
                }
                if (($inviter['mode'] ?? null) !== 'duo' || ($invitee['mode'] ?? null) !== 'duo') {
                    throw new RuntimeException('Both users must be in duo mode.');
                }
                if ((string) $inviter['registration_step'] !== 'matchmaking' || (string) $invitee['registration_step'] !== 'matchmaking') {
                    throw new RuntimeException('Both users must still be in matchmaking.');
                }

                $mark = $pdo->prepare("UPDATE invites SET status = 'accepted', updated_at = :updated_at WHERE id = :id");
                $mark->execute(['updated_at' => nowUtc(), 'id' => $inviteId]);
                $pairStmt = $pdo->prepare("UPDATE users SET teammate_user_id = :mate_id, registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
                $pairStmt->execute(['mate_id' => $inviteeId, 'updated_at' => nowUtc(), 'id' => $inviterId]);
                $pairStmt->execute(['mate_id' => $inviterId, 'updated_at' => nowUtc(), 'id' => $inviteeId]);
                $cancelStmt = $pdo->prepare(
                    "UPDATE invites
                     SET status = 'cancelled', updated_at = :updated_at
                     WHERE status = 'pending'
                       AND (inviter_user_id = :inviter OR inviter_user_id = :invitee OR invitee_user_id = :inviter OR invitee_user_id = :invitee)"
                );
                $cancelStmt->execute([
                    'updated_at' => nowUtc(),
                    'inviter' => $inviterId,
                    'invitee' => $inviteeId,
                ]);
            }

            $pdo->commit();
            respond(true);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getMessage() === 'Invite not found.') {
                respond(false, ['message' => 'Invite not found.'], 404);
            }
            if ($e->getMessage() === 'Invite is no longer pending.') {
                respond(false, ['message' => 'Invite is no longer pending.'], 409);
            }
            respond(false, ['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'complete_payment') {
        $user = requireUser();
        if ((string) $user['registration_step'] !== 'payment') {
            respond(false, ['message' => 'Payment can only be completed on the payment step.'], 422);
        }

        $teammateId = $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null;
        if (($user['mode'] ?? null) === 'duo' && !$teammateId) {
            respond(false, ['message' => 'Duo payment requires a teammate match.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $mark = $pdo->prepare(
                "UPDATE users
                 SET payment_status = CASE WHEN payment_status = 'approved' THEN 'approved' ELSE 'submitted' END,
                     updated_at = :updated_at
                 WHERE id = :id"
            );
            $mark->execute([
                'updated_at' => nowUtc(),
                'id' => (int) $user['id'],
            ]);

            $fresh = findUser($pdo, (int) $user['id']);
            if (!$fresh) {
                throw new RuntimeException('User not found.');
            }

            if (($fresh['mode'] ?? null) === 'duo' && $teammateId) {
                $pair = pairStatus($pdo, (int) $fresh['id'], $teammateId);
                if ($pair['both_approved']) {
                    $complete = $pdo->prepare("UPDATE users SET registration_step = 'complete', updated_at = :updated_at WHERE id = :id OR id = :teammate_id");
                    $complete->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $fresh['id'],
                        'teammate_id' => $teammateId,
                    ]);
                } else {
                    $hold = $pdo->prepare("UPDATE users SET registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
                    $hold->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $fresh['id'],
                    ]);
                }
            } else {
                if ((string) $fresh['payment_status'] === 'approved') {
                    $complete = $pdo->prepare("UPDATE users SET registration_step = 'complete', updated_at = :updated_at WHERE id = :id");
                    $complete->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $fresh['id'],
                    ]);
                } else {
                    $hold = $pdo->prepare("UPDATE users SET registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
                    $hold->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $fresh['id'],
                    ]);
                }
            }

            $pdo->commit();
            respond(true, ['user' => userPublic(currentUser())]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'admin_set_venmo_link') {
        requireAdmin();
        $venmo = trim((string) ($input['venmo_link'] ?? ''));
        if ($venmo !== '') {
            $valid = filter_var($venmo, FILTER_VALIDATE_URL) !== false;
            $scheme = strtolower((string) parse_url($venmo, PHP_URL_SCHEME));
            if (!$valid || !in_array($scheme, ['http', 'https', 'venmo'], true)) {
                respond(false, ['message' => 'Enter a valid Venmo URL.'], 422);
            }
        }
        saveAppSetting('venmo_link', $venmo);
        respond(true, ['venmo_link' => venmoLink()]);
    }

    if ($action === 'admin_set_game_state') {
        requireAdmin();
        $stage = strtolower(trim((string) ($input['game_stage'] ?? gameStage())));
        $announcement = trim((string) ($input['announcement'] ?? gameAnnouncement()));
        $gameInfo = trim((string) ($input['game_info'] ?? trim(appSetting('game_info', ''))));
        $hideDuration = max(0, (int) ($input['hide_duration_seconds'] ?? (int) appSetting('hide_duration_seconds', '300')));
        $seekDuration = max(0, (int) ($input['seek_duration_seconds'] ?? (int) appSetting('seek_duration_seconds', '3600')));
        $clockMode = strtolower(trim((string) ($input['clock_mode'] ?? appSetting('clock_mode', 'countdown'))));
        if (!in_array($stage, ['pregame', 'live', 'paused', 'ended'], true)) {
            respond(false, ['message' => 'Invalid game stage.'], 422);
        }
        if (!in_array($clockMode, ['countdown', 'countup'], true)) {
            respond(false, ['message' => 'Clock mode must be countdown or countup.'], 422);
        }
        saveAppSetting('game_stage', $stage);
        saveAppSetting('announcement', $announcement);
        saveAppSetting('game_info', $gameInfo);
        saveAppSetting('hide_duration_seconds', (string) $hideDuration);
        saveAppSetting('seek_duration_seconds', (string) $seekDuration);
        saveAppSetting('clock_mode', $clockMode);
        respond(true, [
            'game_stage' => gameStage(),
            'announcement' => gameAnnouncement(),
            'game_info' => trim(appSetting('game_info', '')),
            'clock' => gameClockData(),
        ]);
    }

    if ($action === 'admin_start_game') {
        requireAdmin();
        saveAppSetting('game_stage', 'live');
        saveAppSetting('game_started_at', nowUtc());
        respond(true, ['clock' => gameClockData()]);
    }

    if ($action === 'admin_reset_game') {
        requireAdmin();
        $pdo->beginTransaction();
        try {
            saveAppSetting('game_stage', 'pregame');
            saveAppSetting('game_started_at', '');
            $reset = $pdo->prepare(
                "UPDATE users
                 SET game_status = CASE WHEN game_status = 'withdrawn' THEN 'in' ELSE game_status END,
                     is_enrolled = CASE WHEN is_enrolled = 0 THEN 1 ELSE is_enrolled END,
                     pending_alert = '',
                     updated_at = :updated_at"
            );
            $reset->execute(['updated_at' => nowUtc()]);
            $pdo->commit();
            respond(true, ['clock' => gameClockData()]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'admin_send_message') {
        $admin = requireAdmin();
        $body = trim((string) ($input['message'] ?? ''));
        $target = strtolower(trim((string) ($input['target'] ?? 'all_users')));
        $userId = (int) ($input['user_id'] ?? 0);
        $groupIds = normalizeUserIds($input['group_user_ids'] ?? []);

        if ($body === '') {
            respond(false, ['message' => 'Message is required.'], 422);
        }
        if (!in_array($target, ['all_users', 'active', 'eliminated', 'user', 'duo', 'group'], true)) {
            respond(false, ['message' => 'Invalid message target.'], 422);
        }

        $recipients = resolveRecipients($pdo, $target, $userId > 0 ? $userId : null, $groupIds);
        if ($recipients === []) {
            respond(false, ['message' => 'No recipients resolved for this message target.'], 422);
        }

        $metadata = json_encode([
            'target' => $target,
            'user_id' => $userId > 0 ? $userId : null,
            'group_user_ids' => $groupIds,
        ], JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            $insertMsg = $pdo->prepare(
                'INSERT INTO messages (sender_admin_user_id, recipient_scope, body, metadata, created_at) VALUES (:sender_admin_user_id, :recipient_scope, :body, :metadata, :created_at)'
            );
            $now = nowUtc();
            $insertMsg->execute([
                'sender_admin_user_id' => (int) $admin['id'],
                'recipient_scope' => $target,
                'body' => $body,
                'metadata' => $metadata ?: '',
                'created_at' => $now,
            ]);
            $messageId = (int) $pdo->lastInsertId();

            $insertRecipient = $pdo->prepare(
                'INSERT INTO message_recipients (message_id, user_id, is_read, read_at) VALUES (:message_id, :user_id, 0, NULL)'
            );
            foreach ($recipients as $recipientId) {
                try {
                    $insertRecipient->execute([
                        'message_id' => $messageId,
                        'user_id' => $recipientId,
                    ]);
                } catch (Throwable) {
                    // skip duplicate recipient insert
                }
            }

            $pdo->commit();
            respond(true, ['recipient_count' => count($recipients)]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'admin_list_messages') {
        requireAdmin();
        $stmt = $pdo->query(
            "SELECT m.id, m.recipient_scope, m.body, m.created_at, COUNT(mr.user_id) AS recipient_count
             FROM messages m
             LEFT JOIN message_recipients mr ON mr.message_id = m.id
             GROUP BY m.id, m.recipient_scope, m.body, m.created_at
             ORDER BY m.id DESC
             LIMIT 50"
        );
        $messages = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'recipient_scope' => (string) $row['recipient_scope'],
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
            'recipient_count' => (int) $row['recipient_count'],
        ], $stmt->fetchAll());
        respond(true, ['messages' => $messages]);
    }

    if ($action === 'admin_delete_message') {
        requireAdmin();
        $messageId = (int) ($input['message_id'] ?? 0);
        if ($messageId <= 0) {
            respond(false, ['message' => 'Invalid message.'], 422);
        }
        $stmt = $pdo->prepare('DELETE FROM messages WHERE id = :id');
        $stmt->execute(['id' => $messageId]);
        respond(true);
    }

    if ($action === 'admin_list_locations') {
        requireAdmin();
        $stmt = $pdo->query(
            "SELECT id, full_name, phone, mode, is_enrolled, game_status, latitude, longitude, location_accuracy, location_updated_at
             FROM users
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY location_updated_at DESC"
        );
        $locations = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'phone' => (string) $row['phone'],
            'mode' => $row['mode'] ? (string) $row['mode'] : null,
            'is_enrolled' => (int) ($row['is_enrolled'] ?? 1) === 1,
            'game_status' => (string) ($row['game_status'] ?? 'in'),
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'location_accuracy' => $row['location_accuracy'] === null ? null : (float) $row['location_accuracy'],
            'location_updated_at' => $row['location_updated_at'] ? (string) $row['location_updated_at'] : null,
        ], $stmt->fetchAll());
        respond(true, ['locations' => $locations]);
    }

    if ($action === 'admin_list_incidents') {
        requireAdmin();
        $stmt = $pdo->query(
            "SELECT i.id, i.reporter_user_id, u.full_name AS reporter_name, i.incident_type, i.severity, i.details, i.status, i.created_at
             FROM incidents i
             JOIN users u ON u.id = i.reporter_user_id
             ORDER BY i.id DESC
             LIMIT 100"
        );
        $incidents = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'reporter_user_id' => (int) $row['reporter_user_id'],
            'reporter_name' => (string) $row['reporter_name'],
            'incident_type' => (string) $row['incident_type'],
            'severity' => (string) $row['severity'],
            'details' => (string) $row['details'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ], $stmt->fetchAll());
        respond(true, ['incidents' => $incidents]);
    }

    if ($action === 'admin_update_incident_status') {
        requireAdmin();
        $incidentId = (int) ($input['incident_id'] ?? 0);
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if ($incidentId <= 0) {
            respond(false, ['message' => 'Invalid incident.'], 422);
        }
        if (!in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            respond(false, ['message' => 'Invalid incident status.'], 422);
        }

        $stmt = $pdo->prepare('UPDATE incidents SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'updated_at' => nowUtc(),
            'id' => $incidentId,
        ]);
        respond(true);
    }

    if ($action === 'admin_list_killboard') {
        requireAdmin();
        respond(true, ['killboard' => killboardData($pdo)]);
    }

    if ($action === 'admin_set_player_status') {
        requireAdmin();
        $userId = (int) ($input['user_id'] ?? 0);
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if ($userId <= 0) {
            respond(false, ['message' => 'Invalid user.'], 422);
        }
        if (!in_array($status, ['in', 'eliminated', 'seeker', 'withdrawn'], true)) {
            respond(false, ['message' => 'Status must be in, eliminated, seeker, or withdrawn.'], 422);
        }

        $isEnrolled = $status === 'withdrawn' ? 0 : 1;
        $stmt = $pdo->prepare('UPDATE users SET game_status = :game_status, is_enrolled = :is_enrolled, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'game_status' => $status,
            'is_enrolled' => $isEnrolled,
            'updated_at' => nowUtc(),
            'id' => $userId,
        ]);
        respond(true);
    }

    if ($action === 'admin_list_payments') {
        requireAdmin();
        $stmt = $pdo->query(
            "SELECT id, full_name, phone, mode, registration_step, payment_status, updated_at
             FROM users
             WHERE payment_status IN ('pending', 'submitted', 'approved') OR registration_step IN ('payment', 'complete')
             ORDER BY updated_at DESC, id DESC"
        );
        $payments = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'phone' => (string) $row['phone'],
            'mode' => $row['mode'] ? (string) $row['mode'] : null,
            'registration_step' => (string) $row['registration_step'],
            'payment_status' => (string) $row['payment_status'],
            'updated_at' => (string) $row['updated_at'],
        ], $stmt->fetchAll());
        respond(true, ['payments' => $payments]);
    }

    if ($action === 'admin_approve_payment') {
        requireAdmin();
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            respond(false, ['message' => 'Invalid user.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $target = findUser($pdo, $userId);
            if (!$target) {
                throw new RuntimeException('User not found.');
            }

            $approve = $pdo->prepare("UPDATE users SET payment_status = 'approved', updated_at = :updated_at WHERE id = :id");
            $approve->execute([
                'updated_at' => nowUtc(),
                'id' => $userId,
            ]);

            $fresh = findUser($pdo, $userId);
            if (!$fresh) {
                throw new RuntimeException('User not found.');
            }
            $teammateId = $fresh['teammate_user_id'] ? (int) $fresh['teammate_user_id'] : null;
            if (($fresh['mode'] ?? null) === 'duo' && $teammateId) {
                $pair = pairStatus($pdo, (int) $fresh['id'], $teammateId);
                if ($pair['both_approved']) {
                    $complete = $pdo->prepare("UPDATE users SET registration_step = 'complete', updated_at = :updated_at WHERE id = :id OR id = :teammate_id");
                    $complete->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $fresh['id'],
                        'teammate_id' => $teammateId,
                    ]);
                } else {
                    $hold = $pdo->prepare("UPDATE users SET registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
                    $hold->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $fresh['id'],
                    ]);
                }
            } else {
                $complete = $pdo->prepare("UPDATE users SET registration_step = 'complete', updated_at = :updated_at WHERE id = :id");
                $complete->execute([
                    'updated_at' => nowUtc(),
                    'id' => (int) $fresh['id'],
                ]);
            }

            $pdo->commit();
            respond(true);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getMessage() === 'User not found.' ? 404 : 409;
            respond(false, ['message' => $e->getMessage()], $code);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'admin_reset_payment') {
        requireAdmin();
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            respond(false, ['message' => 'Invalid user.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $user = findUser($pdo, $userId);
            if (!$user) {
                throw new RuntimeException('User not found.');
            }

            $reset = $pdo->prepare("UPDATE users SET payment_status = 'pending', registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
            $reset->execute([
                'updated_at' => nowUtc(),
                'id' => $userId,
            ]);

            $teammateId = $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : 0;
            if (($user['mode'] ?? null) === 'duo' && $teammateId > 0) {
                $teammate = findUser($pdo, $teammateId);
                if ($teammate && (int) ($teammate['teammate_user_id'] ?? 0) === $userId) {
                    $rewindMate = $pdo->prepare("UPDATE users SET registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
                    $rewindMate->execute([
                        'updated_at' => nowUtc(),
                        'id' => $teammateId,
                    ]);
                }
            }

            $pdo->commit();
            respond(true);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getMessage() === 'User not found.' ? 404 : 409;
            respond(false, ['message' => $e->getMessage()], $code);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'admin_list_matches') {
        requireAdmin();
        $stmt = $pdo->query(
            "SELECT u.id AS user_a_id, u.full_name AS user_a_name, u.payment_status AS user_a_payment, u.registration_step AS user_a_step,
                    m.id AS user_b_id, m.full_name AS user_b_name, m.payment_status AS user_b_payment, m.registration_step AS user_b_step
             FROM users u
             JOIN users m ON m.id = u.teammate_user_id
             WHERE u.teammate_user_id IS NOT NULL
               AND m.teammate_user_id = u.id
               AND u.id < m.id
             ORDER BY u.updated_at DESC"
        );
        $matches = array_map(static fn (array $row): array => [
            'user_a_id' => (int) $row['user_a_id'],
            'user_a_name' => (string) $row['user_a_name'],
            'user_a_payment' => (string) $row['user_a_payment'],
            'user_a_step' => (string) $row['user_a_step'],
            'user_b_id' => (int) $row['user_b_id'],
            'user_b_name' => (string) $row['user_b_name'],
            'user_b_payment' => (string) $row['user_b_payment'],
            'user_b_step' => (string) $row['user_b_step'],
        ], $stmt->fetchAll());
        respond(true, ['matches' => $matches]);
    }

    if ($action === 'admin_reset_match') {
        requireAdmin();
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            respond(false, ['message' => 'Invalid user.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $user = findUser($pdo, $userId);
            if (!$user) {
                throw new RuntimeException('User not found.');
            }
            $teammateId = $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : 0;
            if ($teammateId <= 0) {
                throw new RuntimeException('User is not currently matched.');
            }
            $teammate = findUser($pdo, $teammateId);
            if (!$teammate || (int) ($teammate['teammate_user_id'] ?? 0) !== (int) $user['id']) {
                throw new RuntimeException('Match is no longer mutual.');
            }

            $updatedAt = nowUtc();
            $reset = $pdo->prepare(
                "UPDATE users
                 SET teammate_user_id = NULL,
                     mode = 'duo',
                     registration_step = 'matchmaking',
                     updated_at = :updated_at
                 WHERE id = :id OR id = :teammate_id"
            );
            $reset->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $user['id'],
                'teammate_id' => $teammateId,
            ]);
            $cancel = $pdo->prepare(
                "UPDATE invites
                 SET status = 'cancelled', updated_at = :updated_at
                 WHERE status = 'pending'
                   AND (inviter_user_id = :id OR inviter_user_id = :teammate_id OR invitee_user_id = :id OR invitee_user_id = :teammate_id)"
            );
            $cancel->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $user['id'],
                'teammate_id' => $teammateId,
            ]);

            $pdo->commit();
            respond(true);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getMessage() === 'User not found.' ? 404 : 409;
            respond(false, ['message' => $e->getMessage()], $code);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'admin_switch_match_to_solo') {
        requireAdmin();
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId <= 0) {
            respond(false, ['message' => 'Invalid user.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $user = findUser($pdo, $userId);
            if (!$user) {
                throw new RuntimeException('User not found.');
            }
            $teammateId = $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : 0;
            if ($teammateId <= 0) {
                throw new RuntimeException('User is not currently matched.');
            }
            $teammate = findUser($pdo, $teammateId);
            if (!$teammate || (int) ($teammate['teammate_user_id'] ?? 0) !== (int) $user['id']) {
                throw new RuntimeException('Match is no longer mutual.');
            }

            $updatedAt = nowUtc();
            $switch = $pdo->prepare(
                "UPDATE users
                 SET teammate_user_id = NULL,
                     mode = 'solo',
                     registration_step = CASE WHEN payment_status = 'approved' THEN 'complete' ELSE 'payment' END,
                     updated_at = :updated_at
                 WHERE id = :id OR id = :teammate_id"
            );
            $switch->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $user['id'],
                'teammate_id' => $teammateId,
            ]);
            $cancel = $pdo->prepare(
                "UPDATE invites
                 SET status = 'cancelled', updated_at = :updated_at
                 WHERE status = 'pending'
                   AND (inviter_user_id = :id OR inviter_user_id = :teammate_id OR invitee_user_id = :id OR invitee_user_id = :teammate_id)"
            );
            $cancel->execute([
                'updated_at' => $updatedAt,
                'id' => (int) $user['id'],
                'teammate_id' => $teammateId,
            ]);

            $pdo->commit();
            respond(true);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getMessage() === 'User not found.' ? 404 : 409;
            respond(false, ['message' => $e->getMessage()], $code);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    respond(false, ['message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    respond(false, ['message' => 'Server error.'], 500);
}
