# Architecture Blueprint

## 1) Core Stack

### cPanel Web App (PHP + MySQL + Bootstrap 5)
Responsibilities:
- registration (individual/duo)
- login (phone + PIN)
- game state persistence
- kill board, messaging, eliminations
- location ingestion/storage
- rendering player/admin pages

### Real-time Engine (Node.js WebSocket Service)
Responsibilities:
- maintain live socket connections
- authorize socket session token
- broadcast stage/message/kill board/admin events instantly
- route team-specific messages

### Reverse Proxy (Apache/Nginx in WHM)
Expose WebSocket endpoint:

`wss://yourdomain.com/ws`

Proxy must forward `Upgrade` and `Connection` headers.

### PHP → Node Event Bridge
When state changes in PHP, PHP sends a signed/internal HTTP request to Node:

`POST http://127.0.0.1:3000/event`

Node validates shared secret and broadcasts normalized events.

## 2) Data Ownership

- **MySQL** is source-of-truth for game state, teams, locations, kill board, and messages.
- **Node** is stateless for business data (uses memory only for active socket sessions).

## 3) Registration + Matchmaking Lifecycle

Registration is a persisted multi-step flow so users can leave and return without losing progress.

1. User starts registration and chooses `single` or `duo`.
2. User enters required profile fields (name, phone, PIN, graduation year, concentration, plus other configured profile fields).
3. If `single`: user proceeds directly to payment.
4. If `duo`: user enters teammate matching step, searches users by name, and sends invite.
5. Invite recipient receives a live prompt and can accept or decline.
6. On decline:
   - inviter remains in teammate selection and is prompted to pick a new teammate
   - invite recipient returns to teammate selection
7. On accept:
   - duo pairing is created
   - both users advance to payment step

Registration state is stored by step (`profile`, `mode`, `matchmaking`, `payment`, `complete`) and resumed on next login.

## 4) Security and Access Separation

- Player socket subscriptions: game-state, announcements, kill board, own team messages.
- Admin socket subscriptions: all player channels plus location stream.
- Player UIs never request or render global location feed.
- Use short-lived WebSocket auth token issued after successful PHP login.

## 5) Session + Progress Persistence

- login uses phone + PIN and returns authenticated app session + websocket token
- logout clears active browser session but does not clear registration/game progress
- on next login, PHP loads saved progress and routes user to the correct step/page

## 6) Location Flow

1. Player dashboard starts `navigator.geolocation.watchPosition()`.
2. Browser posts location to PHP every ~10s.
3. PHP stores latest location (+ timestamp) in MySQL.
4. Admin dashboard fetches `/api/all_locations.php` every ~5s (or consumes `location_update` socket events if enabled).

## 7) Reliability + Scale (dozens of players)

- one persistent socket per client instead of repeated HTTP polling
- lower PHP worker pressure compared to long polling
- keep event payloads small and normalized
- rate limit location updates and reject invalid coordinates

## 8) Deployment Plan

1. Deploy PHP app in cPanel directories (`/game`, `/admin`, `/api`)
2. Run Node service via `pm2` or `systemd`
3. Configure WHM reverse proxy for `/ws`
4. Set bridge secret/env vars on both PHP and Node
5. Validate with two player clients + one admin client
