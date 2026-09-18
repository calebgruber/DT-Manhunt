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
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        $graduationYear = trim((string) ($input['graduation_year'] ?? ''));
        $concentration = trim((string) ($input['concentration'] ?? ''));

        if ($firstName === '' || $lastName === '' || $phone === '' || $pin === '' || $graduationYear === '' || $concentration === '') {
            reply(false, ['message' => 'All registration fields are required.'], 422);
        }

        if (!preg_match('/^\d{4}$/', $graduationYear)) {
            reply(false, ['message' => 'Graduation year must be 4 digits.'], 422);
        }

        if (!preg_match('/^\d{4,8}$/', $pin)) {
            reply(false, ['message' => 'PIN must be 4-8 digits.'], 422);
        }

        $existingStmt = $pdo->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1');
        $existingStmt->execute(['phone' => $phone]);
        if ($existingStmt->fetch()) {
            reply(false, ['message' => 'Phone number already in use.'], 409);
        }

        $now = nowIso();
        $stmt = $pdo->prepare(
            'INSERT INTO users (phone, pin_hash, first_name, last_name, full_name, graduation_year, concentration, registration_step, created_at, updated_at)
             VALUES (:phone, :pin_hash, :first_name, :last_name, :full_name, :graduation_year, :concentration, :registration_step, :created_at, :updated_at)'
        );

        $stmt->execute([
            'phone' => $phone,
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
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
        $currentStep = (string) ($user['registration_step'] ?? '');

        if (!($currentStep === 'profile' && $step === 'mode')) {
            reply(false, ['message' => 'Invalid step transition.'], 422);
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
        if (($user['registration_step'] ?? '') !== 'mode') {
            reply(false, ['message' => 'Mode can only be chosen from the mode step.'], 422);
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
        if (($user['mode'] ?? null) !== 'duo' || ($user['registration_step'] ?? '') !== 'matchmaking') {
            reply(false, ['message' => 'Search is only available during duo matchmaking.'], 422);
        }
        $query = trim((string) ($input['query'] ?? ''));
        if (mb_strlen($query) < 1) {
            reply(true, ['results' => []]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, first_name, last_name, full_name, graduation_year, concentration
             FROM users
             WHERE id <> :self
               AND lower(full_name) LIKE lower(:q)
               AND (mode IS NULL OR mode = "duo")
               AND (teammate_user_id IS NULL)
               AND registration_step IN ("profile", "mode", "matchmaking")
             ORDER BY last_name ASC, first_name ASC
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
        if (($user['registration_step'] ?? '') !== 'matchmaking') {
            reply(false, ['message' => 'Invites can only be sent from the matchmaking step.'], 422);
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
        if (!in_array((string) ($target['registration_step'] ?? ''), ['profile', 'mode', 'matchmaking'], true)) {
            reply(false, ['message' => 'User is not available for matchmaking.'], 409);
        }

        $pendingCheck = $pdo->prepare('SELECT id FROM invites WHERE status = "pending" AND inviter_user_id = :inviter LIMIT 1');
        $pendingCheck->execute(['inviter' => (int) $user['id']]);
        if ($pendingCheck->fetch()) {
            reply(false, ['message' => 'You already have a pending invite.'], 409);
        }

        $targetPendingCheck = $pdo->prepare('SELECT id FROM invites WHERE status = "pending" AND invitee_user_id = :invitee LIMIT 1');
        $targetPendingCheck->execute(['invitee' => $inviteeId]);
        if ($targetPendingCheck->fetch()) {
            reply(false, ['message' => 'That user already has a pending invite.'], 409);
        }

        $now = nowIso();
        $stmt = $pdo->prepare('INSERT INTO invites (inviter_user_id, invitee_user_id, status, created_at, updated_at) VALUES (:inviter, :invitee, "pending", :created_at, :updated_at)');
        $stmt->execute(['inviter' => (int) $user['id'], 'invitee' => $inviteeId, 'created_at' => $now, 'updated_at' => $now]);

        reply(true);
    }

    if ($action === 'matchmaking_state') {
        $user = requireUser();

        $incomingStmt = $pdo->prepare(
            'SELECT i.id, i.created_at, i.inviter_user_id, u.full_name AS inviter_full_name
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
                'inviter_full_name' => $row['inviter_full_name'],
            ];
        }, $incomingStmt->fetchAll());

        $outgoingStmt = $pdo->prepare(
            'SELECT i.id, i.status, i.updated_at, i.invitee_user_id, u.full_name AS invitee_full_name
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
        if (($user['registration_step'] ?? '') !== 'matchmaking') {
            reply(false, ['message' => 'Invite responses are only allowed during matchmaking.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $inviteStmt = $pdo->prepare('SELECT * FROM invites WHERE id = :id');
            $inviteStmt->execute(['id' => $inviteId]);
            $invite = $inviteStmt->fetch();

            if (!$invite || (int) $invite['invitee_user_id'] !== (int) $user['id']) {
                throw new RuntimeException('Invite not found.');
            }
            if (($invite['status'] ?? '') !== 'pending') {
                throw new RuntimeException('Invite is no longer pending.');
            }

            $inviterId = (int) $invite['inviter_user_id'];
            $inviteeId = (int) $invite['invitee_user_id'];

            $userStateStmt = $pdo->prepare('SELECT id, mode, registration_step, teammate_user_id FROM users WHERE id = :id');
            $userStateStmt->execute(['id' => $inviterId]);
            $inviter = $userStateStmt->fetch();
            $userStateStmt->execute(['id' => $inviteeId]);
            $invitee = $userStateStmt->fetch();

            if (!$inviter || !$invitee) {
                throw new RuntimeException('User state changed.');
            }

            if ($decision === 'decline') {
                $update = $pdo->prepare('UPDATE invites SET status = "declined", updated_at = :updated_at WHERE id = :id');
                $update->execute(['updated_at' => nowIso(), 'id' => $inviteId]);

                $pdo->prepare(
                    'UPDATE users
                     SET registration_step = CASE
                        WHEN mode = "duo" AND registration_step IN ("matchmaking", "payment") THEN "matchmaking"
                        ELSE registration_step
                     END,
                     updated_at = :updated_at
                     WHERE id = :inviter OR id = :invitee'
                )
                    ->execute([
                        'updated_at' => nowIso(),
                        'inviter' => $inviterId,
                        'invitee' => $inviteeId,
                    ]);
            } else {
                if (($inviter['teammate_user_id'] ?? null) || ($invitee['teammate_user_id'] ?? null)) {
                    throw new RuntimeException('One of the users is already matched.');
                }
                if (($inviter['registration_step'] ?? '') !== 'matchmaking' || ($invitee['registration_step'] ?? '') !== 'matchmaking') {
                    throw new RuntimeException('Both users must still be in matchmaking.');
                }

                $update = $pdo->prepare('UPDATE invites SET status = "accepted", updated_at = :updated_at WHERE id = :id');
                $update->execute(['updated_at' => nowIso(), 'id' => $inviteId]);

                $pairStmt = $pdo->prepare('UPDATE users SET mode = "duo", teammate_user_id = :mate_id, registration_step = "payment", updated_at = :updated_at WHERE id = :id');
                $pairStmt->execute(['mate_id' => $inviteeId, 'updated_at' => nowIso(), 'id' => $inviterId]);
                $pairStmt->execute(['mate_id' => $inviterId, 'updated_at' => nowIso(), 'id' => $inviteeId]);

                $cancelStmt = $pdo->prepare(
                    'UPDATE invites
                     SET status = "cancelled", updated_at = :updated_at
                     WHERE status = "pending"
                       AND (
                           inviter_user_id = :inviter
                           OR inviter_user_id = :invitee
                           OR invitee_user_id = :inviter
                           OR invitee_user_id = :invitee
                       )'
                );
                $cancelStmt->execute(['updated_at' => nowIso(), 'inviter' => $inviterId, 'invitee' => $inviteeId]);
            }

            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getMessage() === 'Invite not found.') {
                reply(false, ['message' => 'Invite not found.'], 404);
            }
            if ($e->getMessage() === 'Invite is no longer pending.') {
                reply(false, ['message' => 'Invite is no longer pending.'], 409);
            }
            reply(false, ['message' => $e->getMessage()], 409);
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

        if (($user['registration_step'] ?? '') !== 'payment') {
            reply(false, ['message' => 'Payment can only be completed from the payment step.'], 422);
        }
        if (($user['mode'] ?? null) === 'duo' && !$teammateId) {
            reply(false, ['message' => 'Duo payment requires a matched teammate.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE users SET payment_status = "paid", updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['updated_at' => nowIso(), 'id' => (int) $user['id']]);

            if (($user['mode'] ?? null) === 'duo' && $teammateId) {
                $pairStateStmt = $pdo->prepare('SELECT id, payment_status, teammate_user_id FROM users WHERE id = :id OR id = :teammate_id');
                $pairStateStmt->execute([
                    'id' => (int) $user['id'],
                    'teammate_id' => $teammateId,
                ]);
                $pairRows = $pairStateStmt->fetchAll();
                $pairById = [];
                foreach ($pairRows as $row) {
                    $pairById[(int) $row['id']] = $row;
                }

                $isMutualPair = isset($pairById[(int) $user['id']], $pairById[$teammateId])
                    && (int) ($pairById[(int) $user['id']]['teammate_user_id'] ?? 0) === $teammateId
                    && (int) ($pairById[$teammateId]['teammate_user_id'] ?? 0) === (int) $user['id'];

                $bothPaid = $isMutualPair && array_reduce(
                    $pairRows,
                    static fn (bool $carry, array $row): bool => $carry && (($row['payment_status'] ?? '') === 'paid'),
                    true
                );

                if ($bothPaid) {
                    $finalize = $pdo->prepare('UPDATE users SET registration_step = "complete", updated_at = :updated_at WHERE id = :id OR id = :teammate_id');
                    $finalize->execute([
                        'updated_at' => nowIso(),
                        'id' => (int) $user['id'],
                        'teammate_id' => $teammateId,
                    ]);
                } else {
                    $hold = $pdo->prepare('UPDATE users SET registration_step = "payment", updated_at = :updated_at WHERE id = :id');
                    $hold->execute(['updated_at' => nowIso(), 'id' => (int) $user['id']]);
                }
            } else {
                $soloFinalize = $pdo->prepare('UPDATE users SET registration_step = "complete", updated_at = :updated_at WHERE id = :id');
                $soloFinalize->execute(['updated_at' => nowIso(), 'id' => (int) $user['id']]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $user = currentUser();
        reply(true, ['user' => userPublic($user)]);
    }

    reply(false, ['message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    reply(false, ['message' => 'Server error'], 500);
}
