<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');

function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function reply(bool $ok, array $payload = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $payload));
    exit;
}

$input = jsonInput();
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    $pdo = db();

    if ($action === 'register') {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        $graduationYear = trim((string) ($input['graduation_year'] ?? ''));
        $concentration = trim((string) ($input['concentration'] ?? ''));

        if ($fullName === '' || $displayName === '' || $phone === '' || $pin === '' || $graduationYear === '' || $concentration === '') {
            reply(false, ['message' => 'All registration fields are required.'], 422);
        }

        if (!preg_match('/^\d{4}$/', $graduationYear)) {
            reply(false, ['message' => 'Graduation year must be 4 digits.'], 422);
        }

        if (!preg_match('/^\d{4,8}$/', $pin)) {
            reply(false, ['message' => 'PIN must be 4-8 digits.'], 422);
        }

        $existingStmt = $pdo->prepare('SELECT id FROM users WHERE phone = :phone OR lower(display_name) = lower(:display_name) LIMIT 1');
        $existingStmt->execute(['phone' => $phone, 'display_name' => $displayName]);
        if ($existingStmt->fetch()) {
            reply(false, ['message' => 'Phone number or display name already in use.'], 409);
        }

        $now = nowIso();
        $stmt = $pdo->prepare(
            'INSERT INTO users (phone, pin_hash, full_name, display_name, graduation_year, concentration, registration_step, created_at, updated_at)
             VALUES (:phone, :pin_hash, :full_name, :display_name, :graduation_year, :concentration, :registration_step, :created_at, :updated_at)'
        );

        $stmt->execute([
            'phone' => $phone,
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'display_name' => $displayName,
            'graduation_year' => $graduationYear,
            'concentration' => $concentration,
            'registration_step' => 'profile',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        $user = currentUser();
        reply(true, ['user' => userPublic($user)]);
    }

    if ($action === 'login') {
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));

        if ($phone === '' || $pin === '') {
            reply(false, ['message' => 'Phone and PIN are required.'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = :phone');
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pin, $user['pin_hash'])) {
            reply(false, ['message' => 'Invalid credentials.'], 401);
        }

        $_SESSION['user_id'] = (int) $user['id'];
        $user = currentUser();
        reply(true, ['user' => userPublic($user)]);
    }

    if ($action === 'logout') {
        unset($_SESSION['user_id']);
        reply(true);
    }

    if ($action === 'state') {
        $user = requireUser();
        reply(true, [
            'user' => userPublic($user),
            'teammate' => teammate($user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null),
        ]);
    }

    if ($action === 'set_step') {
        $user = requireUser();
        $step = strtolower(trim((string) ($input['step'] ?? '')));
        $allowed = ['profile', 'mode', 'matchmaking', 'payment', 'complete'];
        if (!in_array($step, $allowed, true)) {
            reply(false, ['message' => 'Invalid step.'], 422);
        }

        $stmt = $pdo->prepare('UPDATE users SET registration_step = :step, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'step' => $step,
            'updated_at' => nowIso(),
            'id' => (int) $user['id'],
        ]);

        $user = currentUser();
        reply(true, ['user' => userPublic($user)]);
    }

    if ($action === 'set_mode') {
        $user = requireUser();
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        if (!in_array($mode, ['solo', 'duo'], true)) {
            reply(false, ['message' => 'Mode must be solo or duo.'], 422);
        }

        $step = $mode === 'solo' ? 'payment' : 'matchmaking';
        $stmt = $pdo->prepare('UPDATE users SET mode = :mode, teammate_user_id = NULL, registration_step = :step, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'mode' => $mode,
            'step' => $step,
            'updated_at' => nowIso(),
            'id' => (int) $user['id'],
        ]);

        // clear outgoing/incoming pending invites when switching modes
        $clear = $pdo->prepare('UPDATE invites SET status = :status, updated_at = :updated_at WHERE status = "pending" AND (inviter_user_id = :uid OR invitee_user_id = :uid)');
        $clear->execute(['status' => 'cancelled', 'updated_at' => nowIso(), 'uid' => (int) $user['id']]);

        $user = currentUser();
        reply(true, ['user' => userPublic($user)]);
    }

    if ($action === 'search_users') {
        $user = requireUser();
        $query = trim((string) ($input['query'] ?? ''));
        if (mb_strlen($query) < 1) {
            reply(true, ['results' => []]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, display_name, full_name, graduation_year, concentration
             FROM users
             WHERE id <> :self
               AND lower(display_name) LIKE lower(:q)
               AND (mode IS NULL OR mode = "duo")
               AND (teammate_user_id IS NULL)
             ORDER BY display_name ASC
             LIMIT 20'
        );
        $stmt->execute(['self' => (int) $user['id'], 'q' => '%' . $query . '%']);
        $results = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            return $row;
        }, $stmt->fetchAll());

        reply(true, ['results' => $results]);
    }

    if ($action === 'send_invite') {
        $user = requireUser();

        if (($user['mode'] ?? null) !== 'duo') {
            reply(false, ['message' => 'Set mode to duo first.'], 422);
        }

        $inviteeId = (int) ($input['invitee_user_id'] ?? 0);
        if ($inviteeId <= 0 || $inviteeId === (int) $user['id']) {
            reply(false, ['message' => 'Invalid invite target.'], 422);
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $inviteeId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            reply(false, ['message' => 'User not found.'], 404);
        }
        if ($target['teammate_user_id']) {
            reply(false, ['message' => 'User is already matched.'], 409);
        }

        $pendingCheck = $pdo->prepare('SELECT id FROM invites WHERE status = "pending" AND inviter_user_id = :inviter LIMIT 1');
        $pendingCheck->execute(['inviter' => (int) $user['id']]);
        if ($pendingCheck->fetch()) {
            reply(false, ['message' => 'You already have a pending invite.'], 409);
        }

        $now = nowIso();
        $stmt = $pdo->prepare('INSERT INTO invites (inviter_user_id, invitee_user_id, status, created_at, updated_at) VALUES (:inviter, :invitee, "pending", :created_at, :updated_at)');
        $stmt->execute(['inviter' => (int) $user['id'], 'invitee' => $inviteeId, 'created_at' => $now, 'updated_at' => $now]);

        reply(true);
    }

    if ($action === 'matchmaking_state') {
        $user = requireUser();

        $incomingStmt = $pdo->prepare(
            'SELECT i.id, i.created_at, i.inviter_user_id, u.display_name AS inviter_display_name
             FROM invites i
             JOIN users u ON u.id = i.inviter_user_id
             WHERE i.invitee_user_id = :uid AND i.status = "pending"
             ORDER BY i.id DESC'
        );
        $incomingStmt->execute(['uid' => (int) $user['id']]);
        $incoming = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'inviter_user_id' => (int) $row['inviter_user_id'],
                'inviter_display_name' => $row['inviter_display_name'],
            ];
        }, $incomingStmt->fetchAll());

        $outgoingStmt = $pdo->prepare(
            'SELECT i.id, i.status, i.updated_at, i.invitee_user_id, u.display_name AS invitee_display_name
             FROM invites i
             JOIN users u ON u.id = i.invitee_user_id
             WHERE i.inviter_user_id = :uid
             ORDER BY i.id DESC
             LIMIT 1'
        );
        $outgoingStmt->execute(['uid' => (int) $user['id']]);
        $outgoing = $outgoingStmt->fetch() ?: null;
        if ($outgoing) {
            $outgoing['id'] = (int) $outgoing['id'];
            $outgoing['invitee_user_id'] = (int) $outgoing['invitee_user_id'];
        }

        $freshUser = currentUser();

        reply(true, [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'user' => userPublic($freshUser),
            'teammate' => teammate($freshUser['teammate_user_id'] ? (int) $freshUser['teammate_user_id'] : null),
        ]);
    }

    if ($action === 'respond_invite') {
        $user = requireUser();
        $inviteId = (int) ($input['invite_id'] ?? 0);
        $decision = strtolower((string) ($input['decision'] ?? ''));

        if (!in_array($decision, ['accept', 'decline'], true)) {
            reply(false, ['message' => 'Decision must be accept or decline.'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM invites WHERE id = :id');
        $stmt->execute(['id' => $inviteId]);
        $invite = $stmt->fetch();

        if (!$invite || (int) $invite['invitee_user_id'] !== (int) $user['id']) {
            reply(false, ['message' => 'Invite not found.'], 404);
        }

        if ($invite['status'] !== 'pending') {
            reply(false, ['message' => 'Invite is no longer pending.'], 409);
        }

        $pdo->beginTransaction();
        try {
            if ($decision === 'decline') {
                $update = $pdo->prepare('UPDATE invites SET status = "declined", updated_at = :updated_at WHERE id = :id');
                $update->execute(['updated_at' => nowIso(), 'id' => $inviteId]);

                $pdo->prepare('UPDATE users SET registration_step = "matchmaking", updated_at = :updated_at WHERE id IN (:inviter, :invitee)')
                    ->execute([
                        'updated_at' => nowIso(),
                        'inviter' => (int) $invite['inviter_user_id'],
                        'invitee' => (int) $invite['invitee_user_id'],
                    ]);
            } else {
                $update = $pdo->prepare('UPDATE invites SET status = "accepted", updated_at = :updated_at WHERE id = :id');
                $update->execute(['updated_at' => nowIso(), 'id' => $inviteId]);

                $inviterId = (int) $invite['inviter_user_id'];
                $inviteeId = (int) $invite['invitee_user_id'];

                $pairStmt = $pdo->prepare('UPDATE users SET mode = "duo", teammate_user_id = :mate_id, registration_step = "payment", updated_at = :updated_at WHERE id = :id');
                $pairStmt->execute(['mate_id' => $inviteeId, 'updated_at' => nowIso(), 'id' => $inviterId]);
                $pairStmt->execute(['mate_id' => $inviterId, 'updated_at' => nowIso(), 'id' => $inviteeId]);

                $cancelStmt = $pdo->prepare('UPDATE invites SET status = "cancelled", updated_at = :updated_at WHERE status = "pending" AND (inviter_user_id IN (:inviter, :invitee) OR invitee_user_id IN (:inviter, :invitee))');
                $cancelStmt->execute(['updated_at' => nowIso(), 'inviter' => $inviterId, 'invitee' => $inviteeId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        reply(true);
    }

    if ($action === 'complete_payment') {
        $user = requireUser();
        $teammateId = $user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null;

        $stmt = $pdo->prepare('UPDATE users SET payment_status = "paid", registration_step = "complete", updated_at = :updated_at WHERE id = :id');
        $stmt->execute(['updated_at' => nowIso(), 'id' => (int) $user['id']]);

        if (($user['mode'] ?? null) === 'duo' && $teammateId) {
            $mateStmt = $pdo->prepare('UPDATE users SET registration_step = CASE WHEN payment_status = "paid" THEN "complete" ELSE "payment" END, updated_at = :updated_at WHERE id = :id');
            $mateStmt->execute(['updated_at' => nowIso(), 'id' => $teammateId]);
        }

        $user = currentUser();
        reply(true, ['user' => userPublic($user)]);
    }

    reply(false, ['message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    reply(false, ['message' => 'Server error'], 500);
}
