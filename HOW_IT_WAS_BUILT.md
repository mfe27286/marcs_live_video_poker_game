# How Royal Hold'em Was Built — A Step-by-Step Engineering Report

This document explains how a developer would build this application from a blank folder to a working
multiplayer poker site with live video, using only **PHP, MySQL, HTML, CSS and vanilla JavaScript**.
It is written so that someone with basic web-development knowledge can follow the reasoning, reproduce
the work, and understand every design decision.

---

## Contents

1. [Defining the product](#1-defining-the-product)
2. [Choosing the architecture](#2-choosing-the-architecture)
3. [Phase 1 – Project skeleton & database](#3-phase-1--project-skeleton--database)
4. [Phase 2 – Accounts (register / login / sessions)](#4-phase-2--accounts-register--login--sessions)
5. [Phase 3 – The poker engine](#5-phase-3--the-poker-engine)
6. [Phase 4 – Persisting a game in MySQL](#6-phase-4--persisting-a-game-in-mysql)
7. [Phase 5 – The polling API](#7-phase-5--the-polling-api)
8. [Phase 6 – Turn timers, away detection, stats](#8-phase-6--turn-timers-away-detection-stats)
9. [Phase 7 – Lobby and authentication UI](#9-phase-7--lobby-and-authentication-ui)
10. [Phase 8 – The table UI](#10-phase-8--the-table-ui)
11. [Phase 9 – Live video with WebRTC](#11-phase-9--live-video-with-webrtc)
12. [Phase 10 – Chat, reactions, sounds, history](#12-phase-10--chat-reactions-sounds-history)
13. [Phase 11 – Security hardening](#13-phase-11--security-hardening)
14. [Phase 12 – Testing](#14-phase-12--testing)
15. [Phase 13 – Packaging for shared hosting](#15-phase-13--packaging-for-shared-hosting)
16. [Design decisions & trade-offs](#16-design-decisions--trade-offs)
17. [Ideas for future work](#17-ideas-for-future-work)

---

## 1. Defining the product

Before writing code, pin down exactly what the app must do:

**Core loop**
- A player signs up / logs in.
- From a lobby they **create a private table** (gets a 5-character code) or **join** one with a code.
- Up to 9 players sit at the table and play **No-Limit Texas Hold'em** with play-money chips.
- Everyone sees and hears each other via **live video**.

**Table options**
- Cash game (fixed blinds, rebuy any time between hands) or Tournament (blinds double every 10 hands, optional rebuys).
- Blinds (5/10 … 50/100), starting stack (1,000 … 10,000), turn timer (10–60 s).

**Rules the server must enforce**
- Correct dealing, blinds, action order (heads-up special case), min-raise rules, all-ins and **side pots**.
- A player who doesn't act in time is auto-checked/folded; after two timeouts they're marked **Away**
  and skipped until they return.
- Hole cards are secret until showdown.

**Nice-to-haves**
- Text chat, emoji reactions floating over a seat, sound effects, hand history, profile statistics
  (hands played/won, best hand, biggest pot, total winnings).

Writing this list first is what lets you build in small testable phases later.

---

## 2. Choosing the architecture

### Constraint: shared hosting
Shared cPanel hosting runs PHP **per request** and kills the process when the response is sent. There
is no long-lived process, so there are no WebSockets, no in-memory game state, no background timers.

### Consequences
1. **All state lives in MySQL.** Every request loads the game, mutates it, saves it.
2. **Clients poll.** The browser asks "anything new?" once per second.
3. **Housekeeping is piggybacked on requests.** Timers can't fire by themselves, so every poll checks
   "has anyone's turn expired?" and acts on it.
4. **Video must be peer-to-peer.** The server can't stream media; it only relays WebRTC handshake messages.

### Resulting structure

```
Browser (HTML/CSS/JS)  ──1s poll / actions (JSON over HTTPS)──►  PHP API  ──PDO──►  MySQL
       ▲                                                             │
       └──────────── WebRTC media (direct browser ↔ browser) ◄───────┘ (signaling relayed via MySQL)
```

| Layer | Files | Responsibility |
|---|---|---|
| Pages | `index.php`, `lobby.php`, `table.php` | Server-rendered shells; check login, inject user/CSRF, load JS |
| API | `api/auth.php`, `api/rooms.php`, `api/game.php` | JSON endpoints |
| Domain | `lib/Poker.php` | Pure game rules, no I/O |
| Persistence | `lib/Room.php` | Load/save game, timers, stats, presence, events, signaling |
| Infra | `lib/bootstrap.php`, `lib/layout.php` | Config, DB, sessions, helpers |
| Front-end | `assets/js/*.js`, `assets/css/style.css` | Rendering, polling, WebRTC, sounds |

The key principle: **the engine (`Poker.php`) knows nothing about HTTP or MySQL**. That makes it
testable in isolation and keeps the complicated poker logic away from request plumbing.

---

## 3. Phase 1 – Project skeleton & database

### 3.1 Folder layout
Create the folder tree shown above. Keep everything the browser must never fetch (`lib/`, `config.php`,
`schema.sql`) blocked by `.htaccess`.

### 3.2 Configuration
`config.php` returns a PHP array (DB credentials, presence timeout, ICE servers). All page/asset/API links are relative, so the app runs from any folder without configuration. Using a
plain PHP file means no parsing library and the web server can't serve it as text.

### 3.3 Schema (`schema.sql`)

Five tables, all InnoDB (needed for row locks and foreign keys):

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Accounts + stats | `email` (unique), `password_hash`, `chips`, `hands_played`, `hands_won`, `best_hand`, `biggest_pot`, `total_winnings` |
| `rooms` | One row per table | `code` (unique 5 chars), `host_id`, `state` (JSON of the engine), `meta` (JSON: timers, history, dealt-in list), `version` |
| `room_members` | Who is currently connected | `(room_id,user_id)` PK, `last_seen` unix time |
| `room_events` | Chat + reactions | auto-increment `id` used as a cursor |
| `signals` | WebRTC offers/answers/ICE | `to_user`, deleted once delivered |

**Why JSON blobs for game state?** A poker hand is a single consistent object (deck, seats, pots,
pending actors). Normalising it into 6 tables would add joins and make atomic updates harder. One row
with a `version` counter gives us atomic save, cheap change detection, and trivial locking.

### 3.4 Bootstrap (`lib/bootstrap.php`)
- `db()` — lazily creates a PDO connection with exceptions on, emulated prepares off.
- `start_session()` — `HttpOnly`, `SameSite=Lax`, `Secure` when on HTTPS, 7-day lifetime; generates a CSRF token.
- `json_out()/json_error()` — every API response is JSON with `Cache-Control: no-store`.
- `serialize_user()` — the one place that shapes a DB row into the public user object.

### 3.5 Installer
`install.php` reads `schema.sql`, splits on `;`, executes each `CREATE TABLE IF NOT EXISTS`, prints a
green/red checklist. Non-technical users can't always use phpMyAdmin; a browser page is friendlier.

**Test for this phase:** run `install.php`, see five tables in phpMyAdmin.

---

## 4. Phase 2 – Accounts (register / login / sessions)

`api/auth.php` has four actions selected by `?action=`:

- **register** – validate (`filter_var` email, password ≥ 6, name 2–24), reject duplicates, store
  `password_hash($pw, PASSWORD_DEFAULT)`, `session_regenerate_id(true)`, set `$_SESSION['user_id']`.
- **login** – `password_verify()`; same generic error for unknown email and wrong password (don't leak which).
- **logout** – clear and destroy the session.
- **me** – return the current user and CSRF token (used by the profile dialog to refresh stats).

Pages (`lobby.php`, `table.php`) call `current_user()`; if null they redirect to `index.php`. They then
inject `window.CURRENT_USER` and `window.CSRF` into the page so JavaScript doesn't need an extra request.

**Why sessions instead of JWT?** Same-origin app, server-rendered pages → PHP's built-in session is
simpler, revocable, and the cookie is `HttpOnly` (not readable by scripts).

**Test:** register via the form, see the row in `users`; log out/in; wrong password shows error.

---

## 5. Phase 3 – The poker engine

This is the hardest, most self-contained piece, so build and test it first.

### 5.1 Representing cards
A card is a 2-character string: rank `2-9,T,J,Q,K,A` + suit `s,h,d,c` (e.g. `"Ah"`, `"Td"`). A deck is
an array of 52 such strings, `shuffle()`d.

### 5.2 Hand evaluation
`poker_eval5(cards)` scores exactly five cards and returns a tuple `[category, kicker1, kicker2, …]`:

```
8 Straight flush   [8, high]
7 Four of a kind   [7, quad rank, kicker]
6 Full house       [6, trips rank, pair rank]
5 Flush            [5, c1, c2, c3, c4, c5]
4 Straight         [4, high]          (A-2-3-4-5 "wheel" handled by treating Ace as 1)
3 Three of a kind  [3, trips, k1, k2]
2 Two pair         [2, hi pair, lo pair, kicker]
1 Pair             [1, pair, k1, k2, k3]
0 High card        [0, c1, …, c5]
```

Comparing tuples of different lengths is error-prone, so `poker_score_key()` packs the tuple into a
single integer (`category`, then five kickers in base 15). Bigger integer = better hand, full stop.

`poker_best_hand(7 cards)` tries all 21 five-card combinations and keeps the max. 21 evaluations per
player is trivial.

### 5.3 State machine (`PokerGame`)
Properties: blinds, `seats[9]` (`PokerPlayer` or null), `button`, `phase`
(`waiting → preflop → flop → turn → river → showdown`), `community`, `deck`, `pot`, `current_bet`,
`min_raise`, `to_act`, `pending` (seats that still owe an action this street), `hand_no`, `log`.

Each `PokerPlayer` tracks `stack`, `hole`, `status` (`active | folded | allin | sitting_out`),
`round_bet` (this street), `committed` (whole hand — needed for side pots), `away`, `consec_timeouts`.

Key methods:

- **`startHand()`** – move the dealer button to the next eligible seat, post blinds (heads-up: button
  is small blind and acts first preflop), deal two cards each, set `pending` to all active seats and
  `to_act` to the first actor. In tournament mode it doubles the blinds every `level_hands` hands.
- **`legalActions(seat)`** – returns what the acting player may do: `fold`, `check` *or* `call` amount,
  and `raise {min,max}` (min = current bet + last raise size; max = all-in). The UI renders exactly this,
  so client and server can never disagree.
- **`act(user, action, amount)`** – validates it's that player's turn, applies the action, marks them
  acted, removes them from `pending`. A raise **re-opens** action: every other active seat is put back
  in `pending`. Then `checkRoundProgress()`.
- **`checkRoundProgress()`** – if only one non-folded player remains → award pot without showdown.
  If `pending` is empty → `advanceStreet()`. Otherwise → move `to_act` clockwise to the next pending seat.
- **`advanceStreet()`** – reset street bets, deal flop/turn/river. If ≤1 player can still act (everyone
  else all-in) the board is **run out** automatically to showdown.
- **`buildPots()` + `showdown()`** – classic side-pot algorithm: repeatedly take the smallest remaining
  `committed` amount, collect it from everyone who still has chips committed, and only non-folded
  players are eligible for that pot. Each pot goes to the best eligible hand(s); ties split with odd chips
  going clockwise.
- **`stateFor(userId)`** – the *personalised* view: your own hole cards revealed, others' shown as
  `[null,null]` unless at showdown; plus `legal_actions` if it's your turn.

### 5.4 Serialisation
`toArray()`/`fromArray()` convert the whole object graph to/from a plain array so it can be
`json_encode`d into the `rooms.state` column and rebuilt on the next request. This is what makes a
stateless PHP process behave like a long-running game server.

**Test:** a script that creates a game, seats 3 players, plays scripted actions (including an all-in
with unequal stacks) and asserts pot sizes and winners.

---

## 6. Phase 4 – Persisting a game in MySQL

`lib/Room.php` wraps a `rooms` row:

- `Room::create()` – builds a fresh `PokerGame`, generates a 5-char code from an unambiguous alphabet
  (no `0/O/1/I`), retries on the unlikely unique-key collision. Also deletes rooms idle for >24 h.
- `Room::open($db, $code, $forUpdate)` – `SELECT … FOR UPDATE` when we intend to mutate. Inside a
  transaction this **row lock** serialises all concurrent requests for the same table, so two players
  clicking at the same instant can't both act out of order.
- `touch()` marks dirty; `save()` bumps `version` and writes `state` + `meta` back.

Every mutating request therefore looks like:

```php
$db->beginTransaction();
$room = Room::open($db, $code, true);   // lock
...mutate $room->game...
$room->save();                           // version++
$db->commit();                           // unlock
```

The `version` column is the heart of cheap polling: a client sends the version it has; if unchanged,
the server replies with `state: null` (a few hundred bytes).

---

## 7. Phase 5 – The polling API

`api/game.php` is a single endpoint with `?action=`:

| action | effect |
|---|---|
| `poll` | no mutation (except housekeeping) |
| `sit`, `leave`, `rebuy`, `start`, `act`, `return` | game mutations |
| `chat`, `reaction` | insert into `room_events` |
| `signal` | insert into `signals` for one target user |

Request flow (identical for every action):

1. `require_user()` – 401 if not logged in, 403 if POST without a valid CSRF header.
2. Begin transaction, lock room.
3. **Heartbeat** – upsert `room_members.last_seen = now`.
4. **Housekeeping** – `removeStalePlayers()` (anyone with `last_seen` older than `presence_timeout`
   is treated as disconnected: folded/unseated), then `applyTimeouts()` (see Phase 6).
5. Away auto-return – any deliberate action by an "away" player brings them back.
6. `switch ($action)` – apply the requested change, collecting an error message if the engine refuses.
7. Save + commit.
8. Build the response: `version`, `state` (only if changed), `host_id`, `room_name`, `peers` (online
   members), `events` since the client's cursor, `event_cursor`, `signals` addressed to me (then
   deleted), and `server_now` so the client can correct its clock.

Returning the fresh state from action requests means your *own* actions render instantly; the 1-second
poll only matters for seeing *other* people's moves.

**Event cursors:** the client starts with `ev=-1`, meaning "don't replay history, just tell me the
latest id". Afterwards it sends the last id it saw; the server returns rows with `id > cursor`.

---

## 8. Phase 6 – Turn timers, away detection, stats

With no background process, timers are **data, not threads**:

- `armTurnTimer()` – whenever `to_act` changes to a *different* seat, store
  `meta.turn_deadline = now_ms + turn_seconds*1000` and `meta.turn_seat`. Same seat still on the clock →
  don't reset.
- `applyTimeouts()` – on every request: if `now > deadline + 500 ms` and the seat still hasn't acted,
  call `act()` with `check` if legal else `fold`, append "X auto-folds (timed out)" to the log,
  `markTimeout()` (2 in a row ⇒ `away = true`), record stats, re-arm.

Because *every* client's poll runs this, a timed-out player is folded within ~1 s of the deadline even
if their own browser is closed.

**Stats** (`recordHandStats()`) run once per hand when `phase === 'showdown'` and `hand_no` hasn't
been recorded yet. For every player dealt in: `hands_played++`; winners get `hands_won++`,
`total_winnings += amount`, `biggest_pot = max(...)`; `best_hand` is upgraded if this hand's category
beats the stored one. The same function appends a compact summary (board, winners, shown cards) to
`meta.history` (last 25 hands).

---

## 9. Phase 7 – Lobby and authentication UI

### Visual language
Dark casino palette: background `#0B0E14`, glass panels (`backdrop-filter: blur(20px)` over
`rgba(18,24,36,.82)`), gold accent `#F59E0B`, emerald `#10B981`, crimson for danger. Fonts: *Outfit*
for headings, *Inter* for body, *JetBrains Mono* for numbers (chips, codes). A subtle SVG noise overlay
adds texture. Icons come from Lucide via CDN (`<i data-lucide="spade">` → `lucide.createIcons()`).

### index.php (auth)
Two tabs (Log In / Sign Up) toggle a hidden *Display Name* field and the submit label. `auth.js`
serialises the form with `FormData`, POSTs JSON to `api/auth.php?action=login|register`, shows the
server's error text inline, and redirects to `lobby.php` on success.

### lobby.php
Three cards: **Create Game** (opens a native `<dialog>` with table name, Cash/Tournament segmented
control, blinds, stack, turn timer, rebuys switch), **Join Game** (code input → `rooms.php?action=info`
to verify, then navigate), **Your Profile** (dialog that calls `auth.php?action=me` and renders six stat
tiles). The native `<dialog>` element gives focus trapping, Escape-to-close and a backdrop for free.

---

## 10. Phase 8 – The table UI

`table.php` renders the static shell (header, felt, action bar, chat drawer, history dialog) and
`table.js` fills it from state.

### Polling loop
```js
async function pollLoop() {
  const data = await API.get(`/game.php?action=poll&code=${code}&v=${version}&ev=${eventCursor}`);
  applyPoll(data);
  setTimeout(pollLoop, 1000);     // setTimeout, not setInterval → never overlaps requests
}
```
`applyPoll` only replaces state if `data.version > version` (ignores late/out-of-order responses),
appends events, syncs WebRTC peers and feeds signals to the mesh.

### Rendering strategy
`render()` is a single function that redraws everything from `state` — simple and bug-resistant.
Two things must survive re-renders:
1. **`<video>` elements** – re-creating them restarts playback. They're kept in a `videos` map and
   *moved* into the new tile (`tile.replaceChild`) instead of recreated.
2. **Turn countdown** – ticks every 250 ms via a separate interval that only touches the timer
   element, using `turn_deadline − (Date.now() + serverOffset)` so the client's clock skew doesn't matter.

### Seats
Nine absolute positions around an elliptical felt (`border-radius: 45%`, radial green gradient,
wooden border, gold rim). Each occupied seat shows: video/initials tile (pulsing gold ring when it's
their turn, "D" dealer button, conic-gradient countdown badge), name + stack plate, hole cards, current
bet chip, last-action tag, Folded/Away tags, and — for yourself — mic/cam toggles. Empty seats show a
dashed "Sit" button only if you're not already seated.

### Action bar
Renders strictly from `legal_actions`: Fold always; Check *or* Call N; Raise with a range slider
(`min`…`max`, step = big blind) and a "Go All-In" shortcut. Hands start automatically: `Room::autoStart()`
runs on every poll and schedules `meta.next_hand_at` (4 s after ≥2 players can play, 7 s after a showdown);
the UI shows the countdown and the host gets a **Deal Now** button to skip it. Anyone below the starting
stack between hands sees **Rebuy**.

### Playing cards
Pure CSS: white card with rank in corners (`T` displayed as `10`), suit symbol in the middle, red for
♥♦. Face-down cards use a burgundy gradient with gold border and hatched pattern. Community cards
animate in with a staggered `deal-in` keyframe.

---

## 11. Phase 9 – Live video with WebRTC

### Concept
WebRTC lets two browsers stream audio/video directly. They need to exchange three kinds of small
messages first (the *signaling*): an **offer** (SDP), an **answer** (SDP), and **ICE candidates**
(network paths). The signaling channel can be anything — here it's rows in the `signals` table
delivered through the poll.

### Mesh topology
With up to 9 players, every pair gets its own `RTCPeerConnection` (a "mesh"). To avoid both sides
offering at once, the rule is: **the player with the lower user id initiates** toward each higher id.

### `rtc.js` – `PeerMesh`
- `syncPeers(ids)` – called every poll with online members. Creates connections to new peers (if I'm
  the initiator) and closes ones for peers who left.
- `getPeer(pid, initiator)` – creates `RTCPeerConnection(iceServers)`, adds local tracks, wires
  `ontrack` → show video, `onicecandidate` → POST `signal` to the server; if initiator,
  `onnegotiationneeded` creates and sends the offer.
- `handleSignal(msg)` – offer → set remote, create answer, send it; answer → set remote;
  ICE → `addIceCandidate`, **queued** if the remote description hasn't arrived yet (with polling,
  candidates can overtake the offer).

### Getting the camera
On table load: `navigator.mediaDevices.getUserMedia({video:true,audio:true})`. If denied/unavailable
the app shows a toast and continues without video (initials tiles instead). Mic/cam buttons just flip
`track.enabled`; the local preview is mirrored with `scaleX(-1)` and muted to avoid echo.

### Why STUN/TURN
STUN (free Google servers) lets browsers learn their public IP for direct connection. Players behind
symmetric NATs need a TURN relay; the config exposes `ice_servers` so an operator can add one without
touching code.

---

## 12. Phase 10 – Chat, reactions, sounds, history

- **Chat** – POST `chat`; rows in `room_events`; delivered via the events cursor; rendered in a slide-in
  drawer together with the engine's log lines ("Alice raises to 60").
- **Reactions** – same mechanism with `type = 'reaction'`; the receiver looks up the sender's seat and
  animates the emoji floating up from that position (`float-up` keyframes, removed after 2.4 s).
- **Sounds** – no audio files: `sounds.js` synthesises short tones with the Web Audio API (deal = falling
  triangle wave, chip = two square-wave clinks, win = rising 3-note chord, pop for reactions). Triggered
  by diffing state: new hand or more community cards → deal; pot grew within the same hand → chip; new
  `last_winners` → win. The toggle persists in `localStorage`.
- **Hand history** – `meta.history` is included in state (last 15, newest first) and rendered in a dialog
  with board, winners and shown hole cards.

---

## 12b. Phase 10b – Chip economy: buy-ins, tournaments, daily chips

Chips live in `users.chips` (the *wallet*). Table stacks are separate, so money only moves at well-defined
points, all inside the room's row lock:

| Event | Wallet effect |
|---|---|
| Sit at a cash table | `−buy_in` (player picks an amount between the table's min and max); stack = buy-in |
| Add chips between hands | `−amount` (capped so stack ≤ max buy-in) |
| Leave a cash table (or time out) | `+stack` |
| Enter a tournament | `−entry fee`; fee added to `meta.prize_pool`; stack = starting chips |
| Leave before the tournament starts | `+entry fee` refund |
| Tournament rebuy (if enabled, stack = 0) | `−entry fee` → prize pool |
| Tournament ends | payouts from the prize pool (100% or 50/30/20 with 3–5 players paying 65/35) |
| Claim daily chips | `+1,000` if balance < 1,000 and 24 h since last claim |

Debits use `UPDATE users SET chips = chips - ? WHERE id = ? AND chips >= ?` and check the affected row
count, so a player can never go negative even with concurrent requests.

Tournament lifecycle is driven by `Room::autoStart()`: before the start it only schedules a hand when all
seats are filled (the host may start early with ≥2 players); after each showdown it waits 7 s, eliminates
players with no chips (recording their finishing place), and if one player remains calls
`checkTournamentEnd()` to pay out and freeze the table. Away players in tournaments are still dealt in and
auto-folded immediately so blinds keep rotating.

The engine supports 2–10 seats; the UI positions seats parametrically on the felt ellipse
(`seatPos(i, n)`), so any table size renders correctly.

## 13. Phase 11 – Security hardening

| Threat | Mitigation |
|---|---|
| SQL injection | PDO prepared statements everywhere; no string-built SQL with user input |
| XSS | `escapeHtml()` on every user-provided string before `innerHTML`; server escapes with `htmlspecialchars()` |
| CSRF | Session token must be sent as `X-CSRF-Token` on all POSTs (`hash_equals` compare) |
| Session hijack | `HttpOnly`, `SameSite=Lax`, `Secure` cookies; `session_regenerate_id()` on login |
| Password leaks | bcrypt via `password_hash()`; generic login error |
| Cheating (seeing cards) | Server builds a per-player view; opponents' hole cards are literally not in the JSON |
| Cheating (acting out of turn / bad amounts) | Engine validates turn, legal action, min/max raise server-side |
| Race conditions | `SELECT … FOR UPDATE` row lock per room |
| Information files | `.htaccess` denies `config.php`, `schema.sql`, `lib/` |
| Abuse limits | Name/chat/emoji lengths capped; room codes from a 32-char alphabet (33 M combinations) |

---

## 14. Phase 12 – Testing

1. **Engine unit checks** – scripted hands verifying blinds, action order, re-opened betting after a
   raise, all-in run-outs, side pots and split pots.
2. **API smoke script** (`tests/api_smoke.py`) – registers two users, creates a tournament room, sits both,
   asserts a non-host can't start, plays a hand to showdown, verifies winners/history/stats, waits past
   the turn timer and asserts the auto-fold log line, sends chat/reaction/signal and checks delivery and
   that signals are consumed exactly once, then leaves.
3. **Browser end-to-end** – two independent browser contexts: register → lobby → create/join → sit →
   start → play → chat → reactions → history → leave, checking that each side sees the other's changes
   within the polling window, and that action buttons appear only for the player on turn.
4. **Negative checks** – unauthenticated poll → 401; POST without CSRF → 403; invalid room → 404.

---

## 15. Phase 13 – Packaging for shared hosting

- Ship `config.php` with placeholders plus `config.sample.php` as reference.
- Ship `install.php` for one-click table creation, with an unmissable "delete me" warning.
- `.htaccess` for Apache/LiteSpeed; everything else is plain PHP with no Composer dependencies, no
  build step, no Node — upload and run.
- Use CDN for the icon library and Google Fonts so there's nothing to compile.
- Write `DEPLOYMENT_GUIDE.md` covering cPanel database creation, upload, config, HTTPS, TURN, and
  troubleshooting.

---

## 16. Design decisions & trade-offs

| Decision | Alternative | Why this one |
|---|---|---|
| Short polling every 1 s | WebSockets / SSE / long-polling | Only option that works on *every* shared host. Long-polling would hold PHP processes open and hit process limits. 1 s latency is acceptable for turn-based poker. |
| Game state as JSON in one row | Normalised tables | Atomic save + simple lock + version counter; the engine object is the natural unit of consistency. |
| Housekeeping on poll | Cron job | Cron granularity on shared hosts is 1 min; polls already arrive every second. |
| PHP sessions | JWT | Same-origin pages; simpler, revocable, HttpOnly. |
| Peer-to-peer mesh | SFU media server | No media server possible on shared hosting; mesh is fine for ≤9 participants. |
| Vanilla JS, no framework | React/Vue | Zero build tooling for the hosting target; the page is small enough that one render function stays readable. |
| Presence = "polled within 20 s" | Explicit disconnect events | Browsers can't reliably send a request on tab close; a timeout is the only robust signal. |

Known limitations: a player who switches tabs for >20 s on a heavily-throttled mobile browser may be
auto-removed (tunable via `presence_timeout`); video needs TURN for some corporate networks; 1-second
visibility delay for opponents' actions.

---

## 17. Ideas for future work

- Hand-strength hint for beginners (client-side evaluation of your hole + board).
- Spectator mode (join without sitting, see everything except hole cards).
- Private "friends" list and invite links (`table.php?code=XXXXX` already works as a link).
- Leaderboard page from the `users` stats columns.
- Optional WebSocket upgrade path for VPS users (the API contract already separates state from transport).
- Server-side rate limiting on chat/reactions.
