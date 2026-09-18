# Bootstrap 5 UI Wireframes (Spec)

## Player Experience

## A) Login Screen
- Inputs: phone number, PIN
- CTA: `Sign In`
- Secondary CTA: `Register`
- Mobile-first single-card centered layout

## B) Registration Screen
- Toggle: `Individual` / `Duo`
- Fields:
  - team name
  - player 1 phone + PIN
  - player 2 phone + PIN (duo only)
  - payment confirmation/reference
- CTA: `Create Team`

## C) Player Dashboard
Top section:
- Stage card (`Waiting`, `Hunt Phase`, `Finale`)
- Countdown timer card

Middle section:
- Announcement alert stack
- Team messages card

Bottom section:
- Kill board card
- Status card (`Alive` / `Eliminated`)

Background behavior:
- geolocation permission prompt on first load
- silent location updates every ~10s
- socket reconnect banner on disconnect

## Admin Experience

## A) Admin Login
- admin credential form
- secure session + role check

## B) Admin Operations Dashboard
Header controls:
- stage selector + `Apply`
- timer set/start/pause/reset
- broadcast announcement composer

Body (2-column desktop / stacked mobile):
- Left: live map card (all team markers)
- Right: live team cards (status, last seen, actions)

Action controls per team:
- send direct message
- eliminate / reinstate
- increment kill count

Additional cards:
- live kill board
- system event feed (audit trail style)

## Visual System
- Bootstrap 5 components: cards, badges, alerts, toasts, offcanvas
- Dark mode friendly palette
- high-contrast status colors:
  - alive = success
  - eliminated = danger
  - warning/offline = warning
- touch-first controls (large tap targets)
