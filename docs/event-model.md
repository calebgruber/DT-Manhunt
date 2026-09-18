# WebSocket + Event Model

## 1) Connection

Client connects after login:

`wss://yourdomain.com/ws?token=<ws_token>`

Token claims should include:
- `team_id`
- `role` (`player` or `admin`)
- expiration timestamp

## 2) Envelope

All events use one shape:

```json
{
  "type": "stage_change",
  "ts": "2026-09-18T01:00:00Z",
  "scope": "all",
  "team_id": null,
  "user_id": null,
  "user_ids": null,
  "data": {}
}
```

Routing metadata rules:
- `scope=all`: no routing IDs required
- `scope=team`: set top-level `team_id`
- `scope=user`: set top-level `user_id`
- `scope=users`: set top-level `user_ids`
- `data` is business payload only; transport routing identifiers stay top-level

## 3) Event Types

### Broadcast to all players + admins
- `announcement`
- `stage_change`
- `timer_update`
- `killboard_update`
- `team_eliminated`

### Team-scoped
- `message_to_team`

Required additional field for team scope:

```json
{ "scope": "team", "team_id": 42 }
```

Required additional field for user scope:

```json
{ "scope": "user", "user_id": 202 }
```

Required additional field for users scope:

```json
{ "scope": "users", "user_ids": [101, 202] }
```

### Admin-only
- `location_update`
- `team_status_update`
- `admin_system_notice`

### Registration + matchmaking realtime
- `duo_invite_sent`
- `duo_invite_received`
- `duo_invite_declined`
- `duo_invite_accepted`
- `duo_match_confirmed`
- `registration_step_changed`

## 4) PHP → Node Bridge Contract

`POST /event`

Headers:
- `Content-Type: application/json`
- `X-Bridge-Secret: <shared_secret>`

Payload example:

```json
{
  "type": "message_to_team",
  "scope": "team",
  "team_id": 42,
  "data": {
    "message": "Move to the library",
    "priority": "normal"
  }
}
```

Validation rules:
- reject unknown `type`
- reject missing `team_id` for `scope=team`
- reject missing `user_id` for `scope=user`
- reject missing/empty `user_ids` for `scope=users`
- reject `location_update` from non-admin bridge source
- reject duo matchmaking events when users are not in `duo` mode

## 5) Matchmaking Event Payloads

`duo_invite_sent` / `duo_invite_received`

```json
{
  "type": "duo_invite_received",
  "scope": "user",
  "user_id": 202,
  "data": {
    "invite_id": "inv_123",
    "from_user_id": 101,
    "from_display_name": "Alex",
    "status": "pending"
  }
}
```

`duo_invite_declined`

```json
{
  "type": "duo_invite_declined",
  "scope": "user",
  "user_id": 101,
  "data": {
    "invite_id": "inv_123",
    "declined_by_user_id": 202,
    "next_action": "select_new_teammate"
  }
}
```

`duo_invite_accepted`

```json
{
  "type": "duo_invite_accepted",
  "scope": "users",
  "user_ids": [101, 202],
  "data": {
    "invite_id": "inv_123",
    "accepted_by_user_id": 202,
    "status": "accepted"
  }
}
```

`duo_match_confirmed`

```json
{
  "type": "duo_match_confirmed",
  "scope": "users",
  "user_ids": [101, 202],
  "data": {
    "invite_id": "inv_123",
    "next_step": "payment"
  }
}
```

`registration_step_changed`

```json
{
  "type": "registration_step_changed",
  "scope": "user",
  "user_id": 101,
  "data": {
    "step": "matchmaking"
  }
}
```

Client navigation should be derived from `step` using the app's step-to-route map.

Canonical step-to-route map:
- `profile` → `/game/register/profile`
- `mode` → `/game/register/mode`
- `matchmaking` → `/game/register/matchmaking`
- `payment` → `/game/register/payment`
- `complete` → `/game/register/complete`

## 6) Client Handlers

### Player client should handle
- `announcement`
- `stage_change`
- `timer_update`
- `killboard_update`
- `message_to_team` (own team only)
- `team_eliminated` (global, with own-team highlighting)
- `duo_invite_received`
- `duo_invite_declined`
- `duo_invite_accepted`
- `duo_match_confirmed`
- `registration_step_changed`

### Admin client should handle
- all player events plus:
- `location_update`
- `team_status_update`

## 7) Suggested UI Binding Map

- `announcement` → top alert banner
- `stage_change` → stage badge/card
- `timer_update` → countdown component
- `killboard_update` → leaderboard card/table
- `message_to_team` → inbox/toast for target team
- `location_update` → live map marker updates
- `duo_invite_received` → live invite modal (accept/decline)
- `duo_invite_declined` → teammate selection prompt reset
- `duo_match_confirmed` → automatic navigation to payment step

## 8) Login/Logout + Resume Rules

- every registration write persists current step and payload in MySQL
- login must query saved step and route user to that exact step
- logout must only invalidate session tokens, not persisted registration state
- if duo invite is pending during logout, restore invite state after next login
