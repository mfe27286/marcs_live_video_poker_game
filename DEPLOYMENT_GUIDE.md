# Royal Hold'em (PHP + MySQL) — Complete Deployment Guide

This guide walks you through putting the poker app live on a standard **shared cPanel web host**
(the kind that gives you PHP, MySQL, phpMyAdmin and a File Manager). No command line is required.

Estimated time: **20–30 minutes**.

---

## Table of contents

1. [What you need](#1-what-you-need)
2. [What's in the package](#2-whats-in-the-package)
3. [Step 1 – Create the MySQL database](#3-step-1--create-the-mysql-database)
4. [Step 2 – Upload the files](#4-step-2--upload-the-files)
5. [Step 3 – Edit config.php](#5-step-3--edit-configphp)
6. [Step 4 – Create the tables (run the installer)](#6-step-4--create-the-tables-run-the-installer)
7. [Step 5 – Turn on HTTPS (required for video)](#7-step-5--turn-on-https-required-for-video)
8. [Step 6 – First login & test with a friend](#8-step-6--first-login--test-with-a-friend)
9. [Putting it in a sub-folder or sub-domain](#9-putting-it-in-a-sub-folder-or-sub-domain)
10. [Hosting requirements checklist](#10-hosting-requirements-checklist)
11. [Video calling: STUN/TURN explained](#11-video-calling-stunturn-explained)
12. [Maintenance: backups, cleanup, updates](#12-maintenance-backups-cleanup-updates)
13. [Troubleshooting](#13-troubleshooting)
14. [Security notes](#14-security-notes)
15. [How polling replaces WebSockets](#15-how-polling-replaces-websockets)

---

## 1. What you need

| Item | Details |
|---|---|
| A web hosting account | Shared cPanel/Plesk/DirectAdmin hosting, or any server with Apache/Nginx + PHP + MySQL |
| PHP | **7.4 or newer** (8.1 / 8.2 recommended) with the `pdo_mysql`, `mbstring` and `json` extensions (these are enabled by default on virtually every host) |
| MySQL / MariaDB | MySQL 5.7+ or MariaDB 10.3+ (InnoDB engine) |
| A domain or sub-domain | e.g. `poker.yourdomain.com` or `yourdomain.com/poker` |
| HTTPS (SSL certificate) | **Required** for camera & microphone. cPanel hosts give you a free *AutoSSL / Let's Encrypt* certificate |
| The ZIP file | `royal-holdem-php.zip` (this package) |

---

## 2. What's in the package

```
royal-holdem-php/
├── index.php             Login / sign-up page
├── lobby.php             Lobby: create table, join by code, profile stats
├── table.php             The live poker table
├── logout.php
├── install.php           One-time installer that creates the DB tables  (DELETE after use)
├── config.php            ← YOU EDIT THIS (database details)
├── config.sample.php     Reference copy of the config
├── schema.sql            The database tables (used by install.php, or import in phpMyAdmin)
├── .htaccess             Apache rules: blocks access to config/lib, disables caching of API
├── api/
│   ├── auth.php          register / login / logout / me
│   ├── rooms.php         create room / room info
│   └── game.php          poll + every table action (sit, start, act, chat, reaction, WebRTC signal…)
├── lib/
│   ├── bootstrap.php     DB connection, sessions, JSON helpers, CSRF
│   ├── Poker.php         Texas Hold'em engine (deck, hand evaluator, betting, side pots)
│   ├── Room.php          Loads/saves game state in MySQL, turn timers, stats, presence, signaling
│   └── layout.php        Shared HTML head/footer
├── assets/
│   ├── css/style.css     All styling
│   └── js/               api.js, ui.js, auth.js, lobby.js, cards.js, sounds.js, rtc.js, table.js
├── tests/api_smoke.py    Optional backend test script (for developers)
├── DEPLOYMENT_GUIDE.md   This file
└── HOW_IT_WAS_BUILT.md   Step-by-step engineering report
```

---

## 3. Step 1 – Create the MySQL database

1. Log in to **cPanel**.
2. Find **Databases → MySQL® Databases**.
3. Under **Create New Database**, type a name such as `poker` and click **Create Database**.
   cPanel prefixes it with your account name, so the real name becomes something like
   `myaccount_poker`. **Write this full name down.**
4. Scroll to **MySQL Users → Add New User**. Enter a username (e.g. `pokeruser`) and click
   **Password Generator** to get a strong password. **Copy the password somewhere safe.**
   Click **Create User**. The full username will look like `myaccount_pokeruser`.
5. Scroll to **Add User To Database**. Pick the user and the database, click **Add**.
6. On the privileges screen tick **ALL PRIVILEGES** and click **Make Changes**.

You now have three values you'll need in Step 3:

```
Database name : myaccount_poker
Database user : myaccount_pokeruser
Password      : (the generated one)
Host          : localhost   (99% of hosts; a few use a hostname shown on the MySQL Databases page)
```

> **Plesk users:** Websites & Domains → Databases → Add Database. The dialog creates the DB and user together.

---

## 4. Step 2 – Upload the files

### Option A – cPanel File Manager (no software needed)

1. In cPanel open **Files → File Manager**.
2. Navigate to the folder your site is served from:
   - Main domain: `public_html`
   - Add-on domain / sub-domain: the document root shown under **Domains** (e.g. `public_html/poker.yourdomain.com` or `poker.yourdomain.com`)
   - If you want the app at `yourdomain.com/poker`, create a folder `public_html/poker` and go into it.
3. Click **Upload**, choose `royal-holdem-php.zip`, wait for 100%, then go back.
4. Right-click the ZIP → **Extract** → confirm. A folder `royal-holdem-php` appears.
5. Open that folder, press **Select All**, then **Move** and set the destination to the parent
   folder (e.g. `/public_html`). This puts `index.php`, `api/`, `lib/` etc. directly in the web root.
6. Delete the now-empty `royal-holdem-php` folder and the ZIP.
7. Make sure the hidden file **`.htaccess`** was extracted. In File Manager click **Settings**
   (top-right) → tick **Show Hidden Files (dotfiles)** → Save. If `.htaccess` is missing, create it
   and paste the contents from the package.

### Option B – FTP / SFTP (FileZilla etc.)

1. Unzip `royal-holdem-php.zip` on your computer.
2. Connect with the FTP details from cPanel (**Files → FTP Accounts**).
3. Drag everything **inside** the unzipped folder into your web root (`public_html` or sub-folder).
   Ensure *Transfer → Transfer type → Auto* so `.htaccess` and PHP files upload intact.

### File permissions

Default permissions work: folders `755`, files `644`. Nothing needs to be writable — the app stores
everything in MySQL and uses PHP's default session directory.

---

## 5. Step 3 – Edit config.php

1. In File Manager, right-click **`config.php`** → **Edit** (or edit locally and re-upload).
2. Replace the placeholder values with the ones from Step 1:

```php
return [
    'db_host' => 'localhost',
    'db_name' => 'myaccount_poker',
    'db_user' => 'myaccount_pokeruser',
    'db_pass' => 'PASTE-THE-GENERATED-PASSWORD',

    'presence_timeout' => 20,    // leave as is

    'ice_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
    ],
];
```

3. Save. The app works from any folder (root, sub-folder or sub-domain) — all links are relative, so there is nothing else to configure.

> `.htaccess` blocks browsers from downloading `config.php`, so your password is not exposed even
> if someone guesses the URL.

---

## 6. Step 4 – Create the tables (run the installer)

### Option A – the installer page (easiest)

1. Open your browser and go to `https://yourdomain.com/install.php`
   (or `https://yourdomain.com/poker/install.php`).
2. You should see green lines:
   ```
   Connected to database 'myaccount_poker' as 'myaccount_pokeruser'.
   Table ready: users
   Table ready: rooms
   Table ready: room_members
   Table ready: room_events
   Table ready: signals
   Installation complete.
   ```
3. **Delete `install.php` from the server now** (File Manager → right-click → Delete).

If you see a red error, it is almost always a typo in `config.php` — re-check the database name,
user and password (remember the `myaccount_` prefix).

### Option B – phpMyAdmin

1. cPanel → **Databases → phpMyAdmin**.
2. Click your database in the left column.
3. Click the **Import** tab → **Choose File** → select `schema.sql` → **Go**.
4. The five tables appear. Delete `install.php` from the server anyway.

---

## 7. Step 5 – Turn on HTTPS (required for video)

Browsers only allow camera/microphone access on `https://` pages (or `localhost`). Without HTTPS the
game still works but nobody will have video.

1. cPanel → **Security → SSL/TLS Status**.
2. Tick your domain / sub-domain and click **Run AutoSSL**. Wait 1–5 minutes until the padlock turns green.
   (Some hosts call this *Let's Encrypt SSL* under the Security section.)
3. Force HTTPS: cPanel → **Domains**, toggle **Force HTTPS Redirect** on for the domain.
   If that toggle doesn't exist, add this at the **top** of your `.htaccess`:

   ```apache
   RewriteEngine On
   RewriteCond %{HTTPS} !=on
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

4. Visit `https://yourdomain.com/` and confirm the padlock.

---

## 8. Step 6 – First login & test with a friend

1. Open `https://yourdomain.com/` → **Sign Up** → enter a display name, email and password (min 6 chars).
2. You land in the **Lobby**. Click **Create Game**, choose *Cash* or *Tournament*, blinds, stack and
   turn timer, then **Create & Enter Table**.
3. The browser will ask for camera & microphone permission → **Allow**.
4. Click any dashed **Sit** seat.
5. Copy the 5-letter room code (top-right pill) and send it to a friend.
6. Your friend signs up, pastes the code in **Join Game**, allows camera, and sits.
7. Within 1–2 seconds you see each other's video.
   - **Cash game:** each player buys in from their chip balance (between the table's min/max). The first hand
     deals itself 4 s after two players are seated; the next hand follows 7 s after each showdown. Between hands
     you can **Add chips** up to the max buy-in; when you leave, your stack goes back to your balance.
   - **Tournament:** every seat costs the entry fee (prize pool). It auto-starts only when **all seats are
     filled**; the host can press **Start Tournament** with 2+ players. Busted players are eliminated (or may
     rebuy if enabled); when one player remains the prize pool is paid out (winner-takes-all or top 3).
   - Every account starts with **10,000 chips**. Anyone under 1,000 can claim **1,000 free chips once per 24 h**
     from the lobby.
8. Play! Turn timer, auto-fold, chat (speech-bubble icon), reactions (emoji bar), hand history (clock icon)
   and sound toggle (speaker icon) are all in the header/footer.

Test on a phone too — the layout is responsive and works on mobile Safari/Chrome over HTTPS.

---

## 9. Putting it in a sub-folder or sub-domain

| Where | What to do |
|---|---|
| **Sub-domain** `poker.yourdomain.com` (recommended) | cPanel → Domains → Create a New Domain / Subdomains → `poker`. Note its document root. Upload the files there. Run AutoSSL for the sub-domain too. |
| **Sub-folder** `yourdomain.com/poker` | Upload to `public_html/poker`. Make sure your main site's own `.htaccess` doesn't rewrite `/poker/*` to something else (WordPress's default rules are fine because they skip real directories). |
| **Root of main domain** | Upload to `public_html`. |

---

## 10. Hosting requirements checklist

Open `https://yourdomain.com/phpinfo.php` after uploading a file containing `<?php phpinfo();` to confirm
(then delete it):

- [ ] PHP version ≥ 7.4 (cPanel → **Software → Select PHP Version / MultiPHP Manager** to change it)
- [ ] Extensions: `pdo_mysql`, `mbstring`, `json`, `session` (all standard)
- [ ] `session.save_path` is writable (default on all hosts)
- [ ] `max_execution_time` ≥ 30 (default)
- [ ] MySQL user has ALL PRIVILEGES on the database (needed for `CREATE TABLE` during install)
- [ ] `mod_rewrite` / `.htaccess` enabled (standard on cPanel Apache; LiteSpeed also honours `.htaccess`)
- [ ] HTTPS certificate active

**Load expectations:** every connected player sends one small request per second. A table of 6 people
= ~6 requests/second, each taking a few milliseconds. Shared hosting handles several simultaneous
tables comfortably. If your host has a "max concurrent PHP processes" limit (often 20–50), that
supports roughly that many simultaneous players.

---

## 11. Video calling: STUN/TURN explained

Video and audio travel **directly between players' browsers** (peer-to-peer WebRTC). Your server only
relays the small "handshake" messages through MySQL. This means video costs you no bandwidth.

- **STUN** (free Google servers) lets browsers discover their public address. Works for most home connections.
- **TURN** is a relay used when a direct path is impossible (corporate networks, some mobile carriers,
  symmetric NAT). Without TURN those players see each other's names but no video.

The default `config.php` already includes the free public **Open Relay** TURN servers from Metered, so most
players connect out of the box. For a busy site, use your own account (Metered.ca, Twilio, Xirsys — all have
free tiers) or run `coturn` on a VPS and put its details in `config.php`:

```php
'ice_servers' => [
    ['urls' => 'stun:stun.l.google.com:19302'],
    ['urls' => 'turn:a.relay.metered.ca:443', 'username' => 'xxxx', 'credential' => 'yyyy'],
],
```

No code changes are needed; the table page reads this list on load.

**If a player has no video:**
1. They must open the site over **https://** — otherwise the browser never asks for the camera.
2. They should look for the camera/lock icon in the address bar and set Camera & Microphone to *Allow*,
   then press the red camera button in the table header (it appears whenever the camera is off/blocked)
   — no reload needed.
3. If the camera is in use by another app (Zoom, Teams, OBS) the browser can't open it; close that app first.
4. Players *without* a camera still see and hear everyone else (one-way video is supported).

---

## 12. Maintenance: backups, cleanup, updates

- **Backups:** cPanel → **Files → Backup → Download a MySQL Database Backup**. The only data that
  matters long-term is the `users` table (accounts + stats). Rooms are temporary.
- **Automatic cleanup:** rooms that have been idle for 24 hours are deleted automatically the next
  time anyone creates a table (members, events and signals cascade-delete with them).
- **Updating the app:** upload the new files over the old ones. **Never overwrite your `config.php`.**
  After uploading, open `install.php` once more — it creates any missing tables/columns (it never deletes
  data) — then delete it again. *Required when upgrading from a version without tournaments/daily chips:
  it adds the `users.last_bonus_at` column.*
- **Resetting everything:** in phpMyAdmin drop all five tables and re-run `install.php`.

---

## 13. Troubleshooting

| Symptom | Cause / Fix |
|---|---|
| Page shows `{"error":"config.php is missing..."}` | `config.php` was not uploaded or is in the wrong folder. It must sit next to `index.php`. |
| `install.php` says *Access denied for user* | Wrong DB user/password, or the user wasn't added to the database (Step 1.5–1.6). |
| `install.php` says *Unknown database* | Wrong `db_name` — remember the `account_` prefix. |
| Blank white page | PHP error with display off. In cPanel → **Metrics → Errors** read the last error. Usually PHP version too old (needs 7.4+) → change in *Select PHP Version*. |
| CSS/JS not loading, page unstyled | Check `assets/` folder was uploaded (with `css/` and `js/` inside). If you moved files, open `https://yourdomain.com/<folder>/assets/css/style.css` directly — it must load. |
| "Invalid CSRF token" when clicking buttons | Sessions aren't persisting. Check `session.save_path` is writable (ask host) and that you aren't mixing `http://` and `https://` or `www`/non-`www` — pick one and force it. |
| Login works but refreshing logs you out | Same session issue as above; also check the host isn't stripping cookies via a caching layer (disable "LiteSpeed Cache"/"Varnish" for this folder). |
| Camera permission prompt never appears | You're on `http://`. Switch to HTTPS (Step 5). Or the permission was previously denied — click the lock icon in the address bar to allow, then press the red camera button in the header. |
| Players see each other but no video | Camera blocked (press the red camera button in the header after allowing it in the browser), camera used by another app, or strict NAT — see Section 11. |
| Game feels delayed by ~1 second | Expected — the page polls once per second. This is the trade-off of running on shared hosting without WebSockets. |
| Player vanished from seat after leaving tab open | A player who hasn't polled for 20 s (`presence_timeout`) is treated as disconnected and removed, exactly as a dropped connection would be. Increase the value in `config.php` if your players often switch tabs for long periods. |
| 500 error on `api/game.php` only | The `rooms` table is missing or you ran an old `schema.sql`. Re-run `install.php`. |
| Emoji reactions show as boxes | Server-side fine; it's a font issue on the viewer's device. Nothing to fix. |
| "Room not found" immediately after creating | The DB's `rooms` table isn't InnoDB or `code` column length changed. Re-import `schema.sql`. |

**Reading errors:** cPanel → **Metrics → Errors**, or the `error_log` file that appears in the folder where
the failing PHP file lives.

---

## 14. Security notes

- Passwords are hashed with PHP's `password_hash()` (bcrypt). Nobody, including you, can read them.
- Sessions use an `HttpOnly`, `SameSite=Lax` cookie; all state-changing requests require a CSRF token.
- All SQL uses prepared statements (PDO) – no SQL injection surface.
- All user text is HTML-escaped before rendering (chat, names).
- Hole cards are never sent to other players until showdown — the server decides what each player may see.
- `.htaccess` blocks web access to `config.php`, `schema.sql` and the `lib/` folder.
- Delete `install.php` after installing.
- Keep PHP updated via cPanel's *Select PHP Version*.

---

## 15. How polling replaces WebSockets

The original app used a permanent WebSocket connection, which shared hosting cannot provide. This
version uses **short polling**:

1. Every second the table page calls `api/game.php?action=poll&v=<version>`.
2. The server locks the room row (`SELECT … FOR UPDATE`), runs housekeeping — removing players who
   stopped polling, auto-folding anyone whose turn timer expired — saves, and unlocks.
3. If the room's `version` number changed since the client's last poll, the full personalised state is
   returned; otherwise only a tiny `{state:null}` payload.
4. The same response piggybacks: online peers (for WebRTC), new chat/reaction events, and any WebRTC
   offers/answers/ICE candidates addressed to this player (which are deleted once delivered).
5. Player actions (fold/call/raise/sit/chat…) are POSTs to the same endpoint and return the fresh
   state immediately, so your own actions feel instant; other players see them within ≤1 s.

Because every poll performs housekeeping, the game never "stalls" — a timed-out player is folded by
whichever client polls next, even if the idle player closed their laptop.
