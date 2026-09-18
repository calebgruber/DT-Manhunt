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
  "data": {}
}
```

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

### Admin-only
- `location_update`
- `team_status_update`
- `admin_system_notice`

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
- reject `location_update` from non-admin bridge source

## 5) Client Handlers

### Player client should handle
- `announcement`
- `stage_change`
- `timer_update`
- `killboard_update`
- `message_to_team` (own team only)
- `team_eliminated` (global, with own-team highlighting)

### Admin client should handle
- all player events plus:
- `location_update`
- `team_status_update`

## 6) Suggested UI Binding Map

- `announcement` → top alert banner
- `stage_change` → stage badge/card
- `timer_update` → countdown component
- `killboard_update` → leaderboard card/table
- `message_to_team` → inbox/toast for target team
- `location_update` → live map marker updates
