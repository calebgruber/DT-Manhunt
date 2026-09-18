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

$input = body();
$action = (string) ($input['action'] ?? $_GET['action'] ?? '');
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

        $existing = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
        $existing->execute(['phone' => $phone]);
        if ($existing->fetch()) {
            respond(false, ['message' => 'Phone number already in use.'], 409);
        }

        $fullName = trim($firstName . ' ' . $lastName);
        $now = nowUtc();

        $stmt = $pdo->prepare(
            "INSERT INTO users (phone, pin_hash, first_name, last_name, full_name, graduation_year, concentration, mode, registration_step, payment_status, created_at, updated_at)
             VALUES (:phone, :pin_hash, :first_name, :last_name, :full_name, :graduation_year, :concentration, NULL, 'profile', 'pending', :created_at, :updated_at)"
        );
        $stmt->execute([
            'phone' => $phone,
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'graduation_year' => $graduationYear,
            'concentration' => $concentration,
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

        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = :phone LIMIT 1");
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

    if ($action === 'state') {
        $user = requireUser();
        respond(true, [
            'user' => userPublic($user),
            'teammate' => teammateFor($user['teammate_user_id'] ? (int) $user['teammate_user_id'] : null),
        ]);
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

        $next = $mode === 'solo' ? 'payment' : 'matchmaking';
        $stmt = $pdo->prepare("UPDATE users SET mode = :mode, teammate_user_id = NULL, registration_step = :registration_step, updated_at = :updated_at WHERE id = :id");
        $stmt->execute([
            'mode' => $mode,
            'registration_step' => $next,
            'updated_at' => nowUtc(),
            'id' => (int) $user['id'],
        ]);

        $cancel = $pdo->prepare("UPDATE invites SET status = 'cancelled', updated_at = :updated_at WHERE status = 'pending' AND (inviter_user_id = :id OR invitee_user_id = :id)");
        $cancel->execute(['updated_at' => nowUtc(), 'id' => (int) $user['id']]);

        respond(true, ['user' => userPublic(currentUser())]);
    }

    if ($action === 'switch_to_solo') {
        $user = requireUser();
        if (($user['mode'] ?? null) !== 'duo' || (string) $user['registration_step'] !== 'matchmaking') {
            respond(false, ['message' => 'You can only switch to solo during duo matchmaking.'], 422);
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
            "SELECT id, first_name, last_name, full_name, graduation_year, concentration, registration_step, teammate_user_id, mode
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

        $inviteeId = (int) ($input['invitee_user_id'] ?? 0);
        if ($inviteeId <= 0 || $inviteeId === (int) $user['id']) {
            respond(false, ['message' => 'Invalid invite target.'], 422);
        }

        $targetStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $targetStmt->execute(['id' => $inviteeId]);
        $target = $targetStmt->fetch();
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

        $inviteId = (int) ($input['invite_id'] ?? 0);
        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        if (!in_array($decision, ['accept', 'decline'], true)) {
            respond(false, ['message' => 'Decision must be accept or decline.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $inviteStmt = $pdo->prepare("SELECT * FROM invites WHERE id = :id LIMIT 1");
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
            $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute(['id' => $inviterId]);
            $inviter = $userStmt->fetch();
            $userStmt->execute(['id' => $inviteeId]);
            $invitee = $userStmt->fetch();

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
            $markPaid = $pdo->prepare("UPDATE users SET payment_status = 'paid', updated_at = :updated_at WHERE id = :id");
            $markPaid->execute(['updated_at' => nowUtc(), 'id' => (int) $user['id']]);

            if (($user['mode'] ?? null) === 'duo' && $teammateId) {
                $pairStmt = $pdo->prepare("SELECT id, payment_status, teammate_user_id FROM users WHERE id = :id OR id = :teammate_id");
                $pairStmt->execute(['id' => (int) $user['id'], 'teammate_id' => $teammateId]);
                $rows = $pairStmt->fetchAll();
                $map = [];
                foreach ($rows as $row) {
                    $map[(int) $row['id']] = $row;
                }

                $isMutual = isset($map[(int) $user['id']], $map[$teammateId])
                    && (int) ($map[(int) $user['id']]['teammate_user_id'] ?? 0) === $teammateId
                    && (int) ($map[$teammateId]['teammate_user_id'] ?? 0) === (int) $user['id'];

                $bothPaid = $isMutual
                    && ((string) $map[(int) $user['id']]['payment_status'] === 'paid')
                    && ((string) $map[$teammateId]['payment_status'] === 'paid');

                if ($bothPaid) {
                    $complete = $pdo->prepare("UPDATE users SET registration_step = 'complete', updated_at = :updated_at WHERE id = :id OR id = :teammate_id");
                    $complete->execute([
                        'updated_at' => nowUtc(),
                        'id' => (int) $user['id'],
                        'teammate_id' => $teammateId,
                    ]);
                } else {
                    $hold = $pdo->prepare("UPDATE users SET registration_step = 'payment', updated_at = :updated_at WHERE id = :id");
                    $hold->execute(['updated_at' => nowUtc(), 'id' => (int) $user['id']]);
                }
            } else {
                $complete = $pdo->prepare("UPDATE users SET registration_step = 'complete', updated_at = :updated_at WHERE id = :id");
                $complete->execute(['updated_at' => nowUtc(), 'id' => (int) $user['id']]);
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

    respond(false, ['message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    respond(false, ['message' => 'Server error.'], 500);
}
