# Bootstrap 5 UI Wireframes (Spec)

DB: voxelnodes_manhunt
DB PW: t9K-ejDhnn0p(p%2
## Player Experience

## A) Login Screen
- Inputs: phone number, PIN
- CTA: `Sign In`
- Secondary CTA: `Register`
- Mobile-first single-card centered layout
- Saved progress card shown after login: `Resume registration at Step X`

## B) Registration Screen
Top sticky stepper (large, mobile friendly):
1. Profile
2. Mode
3. Matchmaking (duo only)
4. Payment
5. Complete

Step 1: Profile fields
- full name
- display name (searchable)
- phone number
- PIN
- graduation year
- concentration
- optional profile extras (as configured)

Step 2: Mode select
- Large icon button: `Solo`
- Large icon button: `Duo`
- Buttons are full-width on mobile

Step 3 (duo only): Matchmaking screen
- Search box: `Search teammate by name`
- Search results list with large `Invite` action button
- Live invite status chip: `Pending`, `Accepted`, `Declined`
- On decline:
  - inviter sees prompt to choose new teammate
  - invite recipient returns to teammate selection
- On accept:
  - both users auto-advance to payment step

Step 4: Payment screen
- payment amount/summary
- payment method UI
- completion state after successful payment

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
- if user logs out and returns, app resumes their saved registration or game state

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
- stepper and primary action buttons remain sticky/visible on small screens
