<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';
$user = current_user();
if (!$user) { header('Location: index.php'); exit; }
$u = serialize_user($user);
page_head('Lobby');
?>
<script>window.CURRENT_USER = <?= json_encode($u) ?>; window.CSRF = <?= json_encode($_SESSION['csrf']) ?>;</script>
<header class="topbar glass">
  <div class="topbar-inner">
    <div class="brand"><i data-lucide="spade"></i><span class="font-heading">Royal Hold'em</span></div>
    <div class="topbar-right">
      <div class="pill glass" title="Your chip balance"><i data-lucide="coins" class="gold"></i><span class="mono gold" id="chips" data-testid="wallet-balance"><?= number_format($u['chips']) ?></span></div>
      <button class="pill glass pill-btn" id="profile-btn" data-testid="profile-button">
        <span class="avatar-sm"><?= htmlspecialchars(mb_strtoupper(mb_substr($u['name'], 0, 2))) ?></span>
        <span class="hide-sm"><?= htmlspecialchars($u['name']) ?></span>
      </button>
      <button class="icon-btn" id="logout-btn" data-testid="logout-button" title="Log out"><i data-lucide="log-out"></i></button>
    </div>
  </div>
</header>

<main class="lobby">
  <div class="hero">
    <h1 class="font-heading">Welcome back,<br><span><?= htmlspecialchars($u['name']) ?></span></h1>
    <p>Create a private table, share the code with friends, and play Texas Hold'em face-to-face over live video.</p>
    <div id="bonus-box" class="bonus-box glass" hidden data-testid="bonus-box">
      <div><b>Running low?</b> <span id="bonus-text"></span></div>
      <button class="btn btn-gold" id="claim-bonus" data-testid="claim-bonus-button"><i data-lucide="gift"></i> Claim daily chips</button>
    </div>
  </div>

  <div class="cards3">
    <button class="glass lobby-card hover-gold" id="create-room-btn" data-testid="create-room-button">
      <div class="icon-box gold"><i data-lucide="plus"></i></div>
      <h3 class="font-heading">Create Game</h3>
      <p>Host a cash game or a tournament and get a shareable room code.</p>
    </button>

    <div class="glass lobby-card">
      <div class="icon-box emerald"><i data-lucide="log-in"></i></div>
      <h3 class="font-heading">Join Game</h3>
      <p>Enter a room code from a friend.</p>
      <div class="row gap">
        <input id="join-code" class="input mono upper" placeholder="CODE" maxlength="5" data-testid="join-room-input">
        <button class="btn btn-emerald" id="join-btn" data-testid="join-room-button">Join</button>
      </div>
    </div>

    <button class="glass lobby-card hover-fuchsia" id="profile-card" data-testid="profile-card">
      <div class="icon-box fuchsia"><i data-lucide="user"></i></div>
      <h3 class="font-heading">Your Profile</h3>
      <p>Chip balance, games played, win rate and your best hand.</p>
      <div class="row gap small muted" style="margin-top:14px">
        <span class="row gap-sm"><i data-lucide="users" class="i-13"></i> <?= (int)$u['stats']['hands_played'] ?> played</span>
        <span class="row gap-sm"><i data-lucide="video" class="i-13"></i> live video</span>
      </div>
    </button>
  </div>
</main>

<dialog id="create-dialog" class="dialog glass" data-testid="create-room-dialog">
  <h2 class="font-heading">Create a Table</h2>
  <p class="muted">Pick a game type, set the stakes, then share the room code with friends.</p>
  <div class="form">
    <div class="field"><label>Table Name</label><input id="room-name" class="input" value="My Poker Night" maxlength="40" data-testid="room-name-input"></div>
    <div class="field">
      <label>Game Type</label>
      <div class="segmented">
        <button type="button" class="seg active" data-mode="cash" data-testid="game-mode-cash"><b>Cash Game</b><small>Buy in from your balance • leave any time</small></button>
        <button type="button" class="seg" data-mode="tournament" data-testid="game-mode-tournament"><b>Tournament</b><small>Entry fee • blinds rise • prize pool</small></button>
      </div>
    </div>

    <div class="grid2">
      <div class="field"><label id="blinds-label">Blinds</label>
        <select id="blinds" class="input" data-testid="blinds-select">
          <option value="5/10">5 / 10</option><option value="10/20" selected>10 / 20</option><option value="25/50">25 / 50</option><option value="50/100">50 / 100</option>
        </select></div>
      <div class="field"><label>Seats</label>
        <select id="max-seats" class="input" data-testid="seats-select"></select></div>
    </div>

    <div class="grid2 cash-only">
      <div class="field"><label>Min Buy-in</label>
        <select id="min-buyin" class="input" data-testid="min-buyin-select">
          <option value="100">100</option><option value="200">200</option><option value="500" selected>500</option><option value="1000">1,000</option><option value="2000">2,000</option>
        </select></div>
      <div class="field"><label>Max Buy-in</label>
        <select id="max-buyin" class="input" data-testid="max-buyin-select">
          <option value="500">500</option><option value="1000">1,000</option><option value="2000" selected>2,000</option><option value="5000">5,000</option><option value="10000">10,000</option>
        </select></div>
    </div>

    <div class="grid2 tourney-only" hidden>
      <div class="field"><label>Buy-in (entry fee)</label>
        <select id="buy-in" class="input" data-testid="buyin-select">
          <option value="0">Free roll (0)</option><option value="100">100</option><option value="250">250</option><option value="500" selected>500</option><option value="1000">1,000</option><option value="2500">2,500</option><option value="5000">5,000</option>
        </select></div>
      <div class="field"><label>Starting Chips</label>
        <select id="stack" class="input" data-testid="stack-select">
          <option value="1000">1,000</option><option value="1500">1,500</option><option value="3000" selected>3,000</option><option value="5000">5,000</option><option value="10000">10,000</option>
        </select></div>
    </div>

    <div class="grid2 tourney-only" hidden>
      <div class="field"><label>Payout</label>
        <select id="payout" class="input" data-testid="payout-select">
          <option value="winner">Winner takes all</option><option value="top3">Top 3 (50 / 30 / 20)</option>
        </select></div>
      <div class="field">
        <label>&nbsp;</label>
        <label class="toggle-box">
          <span><b>Rebuys</b><small>Busted players may re-enter (costs a buy-in)</small></span>
          <input type="checkbox" id="allow-rebuys" checked data-testid="allow-rebuys-switch"><span class="switch"></span>
        </label>
      </div>
    </div>

    <div class="grid2">
      <div class="field"><label>Turn Timer</label>
        <select id="turn-seconds" class="input" data-testid="turn-timer-select">
          <option value="10">10 seconds</option><option value="15">15 seconds</option><option value="20" selected>20 seconds</option><option value="30">30 seconds</option><option value="60">60 seconds</option>
        </select></div>
      <div class="field"><label>&nbsp;</label><div class="note-box" id="mode-note"></div></div>
    </div>
  </div>
  <button class="btn btn-gold btn-block" id="confirm-create" data-testid="confirm-create-room-button">Create &amp; Enter Table</button>
  <button class="dialog-close icon-btn" data-close><i data-lucide="x"></i></button>
</dialog>

<dialog id="profile-dialog" class="dialog glass" data-testid="profile-dialog">
  <h2 class="font-heading">Your Profile</h2>
  <p class="muted"><?= htmlspecialchars($u['email']) ?></p>
  <div class="stats-grid" id="stats-grid"></div>
  <button class="dialog-close icon-btn" data-close><i data-lucide="x"></i></button>
</dialog>
<?php page_foot(['lobby.js']); ?>
