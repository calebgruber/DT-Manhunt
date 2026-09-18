# DT-Manhunt

Campus-wide Manhunt platform blueprint for SUNY Purchase using:

- **PHP + MySQL + Bootstrap 5** (cPanel-hosted web app)
- **Node.js + WebSockets** (real-time engine)
- **Apache/Nginx reverse proxy** (`wss://yourdomain.com/ws`)

## Goals

- Players register as individuals or duos, pay fee, and log in with phone + PIN
- Player dashboard shows live stage, timer, announcements, kill board, and admin messages
- Player devices send location updates automatically
- Players **cannot** see other player locations
- Admin dashboard shows live player map, team cards, kill board, and game state
- Admin can broadcast/direct-message teams, control stage/timer/announcements, and eliminate teams

## Repository Docs

- `docs/architecture.md` – end-to-end system architecture and deployment model
- `docs/event-model.md` – WebSocket and PHP↔Node event contracts
- `docs/ui-wireframes.md` – Bootstrap 5 screen-level wireframes/spec

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
