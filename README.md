# DT-Manhunt

Campus-wide Manhunt platform blueprint for SUNY Purchase using:

- **PHP + SQLite (scaffold) + Tabler UI (Bootstrap-based)** (cPanel-hosted web app)
- **Node.js + WebSockets** (real-time engine)
- **Apache/Nginx reverse proxy** (`wss://yourdomain.com/ws`)

## Goals

- Players register as solo or duo, pay fee, and log in with phone + PIN
- Duo registration includes live teammate invite/accept/decline matching before payment
- Registration captures profile details (for example graduation year and concentration)
- Player dashboard shows live stage, timer, announcements, kill board, and admin messages
- Player devices send location updates automatically
- Players **cannot** see other player locations
- Admin dashboard shows live player map, team cards, kill board, and game state
- Admin can broadcast/direct-message teams, control stage/timer/announcements, and eliminate teams
- Users can log in/out and resume saved registration progress

## Repository Docs

- `docs/architecture.md` – end-to-end system architecture and deployment model
- `docs/event-model.md` – WebSocket and PHP↔Node event contracts
- `docs/ui-wireframes.md` – Tabler UI screen-level wireframes/spec
- `starter.sql` – MySQL starter schema for production database setup

## Suggested Project Layout (cPanel deployment)

```text
/public_html/game/      # player experience
/public_html/admin/     # admin dashboard
/public_html/api/       # PHP API endpoints
/node-realtime/         # Node websocket service (on VPS)
```

## Operational Model

1. PHP handles auth, persistence, and game logic
2. Admin actions are written to MySQL by PHP
3. PHP posts normalized event payloads to Node (`POST /event`)
4. Node broadcasts updates over WebSocket to connected clients instantly

This design keeps PHP simple and cPanel-friendly while providing low-latency mobile real-time behavior.

> Runtime default: SQLite (`manhunt.sqlite`). For production, switch to MySQL and run `starter.sql`.

## App Configuration

- Base config file: `config.php`
- Local override file (not committed): `config.local.php`
- Example template: `config.local.php.example`

Typical setup:
1. Copy `config.local.php.example` to `config.local.php`
2. Add your DB DSN/user/password and app secrets
3. Keep `config.local.php` private (already gitignored)

Registration dropdown config:
- `registration.graduation_year_options` controls the year dropdown (admin-editable in config files)
- `registration.concentration_options` controls the concentration dropdown values

Admin config:
- `admin.allowed_phone_numbers` controls which user phone numbers can log in at `/admin/`
- `admin.venmo_link` is the default payment link (admins can update it live in `/admin/`)
- `test_admin` controls automatic seeded test admin credentials

Default seeded test admin:
- Phone: `0000000000`
- PIN: `0000`
- Name: `Admin Test`

Payment flow:
- Players submit payment from the payment step
- Admins approve payments per user in `/admin/`
- Admins can reset approvals back to pending
- Registration completes only after approval (for duos, both users must be approved)

Live gameplay operations:
- User complete step is a fullscreen-oriented live dashboard with duo info, persistent admin alerts, map, and kill board cards
- Player statuses are `in`, `eliminated`, `seeker`, and `withdrawn`
- Users can withdraw from the game; if they were in a duo, their teammate is switched to solo and receives an acknowledge alert
- Admins can control stage, clock mode (count up/down), hide duration, seek duration, start/reset game, and persistent live messages
- Admin dashboard is tabbed and includes dark-mode live map, fullscreen map/kill-board controls, incidents, and operations tabs
- When game stage is `live`, user devices post geolocation updates at ~60-second intervals for the admin map
- Incident reports are submitted via user modal and trigger a live emergency banner on admin when open high/emergency incidents exist
