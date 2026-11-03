<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';
$user = current_user();
if (!$user) { header('Location: index.php'); exit; }
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'] ?? ''));
if (strlen($code) !== 5) { header('Location: lobby.php'); exit; }
$u = serialize_user($user);
page_head("Table $code");
?>
<script>
window.CURRENT_USER = <?= json_encode($u) ?>;
window.CSRF = <?= json_encode($_SESSION['csrf']) ?>;
window.ROOM_CODE = <?= json_encode($code) ?>;
window.ICE_SERVERS = <?= json_encode($CONFIG['ice_servers']) ?>;
</script>

<div id="loading" class="center-screen muted"><i data-lucide="spade" class="gold pulse i-40"></i><div>Joining table <?= $code ?>...</div></div>

<div id="table-page" class="table-page" hidden>
  <div id="audio-sink" style="position:absolute;width:0;height:0;overflow:hidden"></div>
  <header class="tbl-header glass">
    <div class="row gap">
      <a href="lobby.php" class="gold"><i data-lucide="spade"></i></a>
      <div>
        <div class="room-title font-heading"><span id="room-name"></span> <span id="mode-badge" class="badge hide-sm"></span></div>
        <div class="tiny muted upper hide-sm" id="blinds-line"></div>
      </div>
    </div>
    <div class="row gap-sm">
      <div class="pill glass hide-sm" title="Your chip balance (not on the table)"><i data-lucide="wallet" class="gold i-13"></i><span class="mono gold" id="wallet" data-testid="wallet-balance">—</span></div>
      <button class="pill glass pill-btn" id="copy-code" data-testid="copy-room-code-button"><span class="mono gold wide" data-testid="room-code-display"><?= $code ?></span><i data-lucide="copy" class="i-13 muted"></i></button>
      <button class="icon-btn" id="media-btn" hidden title="Enable camera & microphone" data-testid="enable-media-button"><i data-lucide="video-off" class="red"></i></button>
      <button class="icon-btn" id="sound-btn" data-testid="sound-toggle-button"><i data-lucide="volume-2"></i></button>
      <button class="icon-btn hide-sm" id="history-btn" data-testid="history-button"><i data-lucide="history"></i></button>
      <button class="icon-btn" id="chat-btn" data-testid="chat-button"><i data-lucide="message-square"></i></button>
      <button class="icon-btn danger" id="leave-btn" data-testid="leave-table-button"><i data-lucide="log-out"></i></button>
    </div>
  </header>

  <div class="banner-row">
    <div id="blind-banner" class="glass pill banner" hidden data-testid="blind-schedule-banner"></div>
    <div id="tourney-banner" class="glass pill banner tourney" hidden data-testid="tournament-banner"></div>
  </div>
  <div id="results-banner" class="results-banner glass" hidden data-testid="tournament-results"></div>

  <div class="table-area" id="table-area">
    <div class="table-box" id="table-box">
      <div class="poker-felt" id="felt">
        <div id="reactions-layer"></div>
        <div class="felt-center">
          <div class="community" id="community"></div>
          <div class="pot"><i data-lucide="coins" class="gold i-13"></i><span class="mono gold" id="pot" data-testid="pot-display">0</span></div>
        </div>
        <div id="bets"></div>
      </div>
      <div id="seats"></div>
    </div>
  </div>

  <div id="away-banner" class="away-banner" hidden><button class="btn btn-sky" id="return-btn" data-testid="return-button"><i data-lucide="undo-2"></i> You're away — tap to return</button></div>

  <div class="action-bar glass">
    <div class="action-inner">
      <div class="emoji-wrap">
        <button class="icon-btn emoji-btn" id="emoji-btn" hidden title="Send a reaction" data-testid="reaction-menu-button"><i data-lucide="smile"></i></button>
        <div id="reaction-bar" class="reaction-menu glass" hidden data-testid="reaction-bar"></div>
      </div>
      <div class="action-main">
        <div class="row center gap" id="host-row">
          <button class="btn btn-gold" id="rebuy-btn" hidden data-testid="rebuy-button"><i data-lucide="coins"></i> <span>Rebuy</span></button>
          <button class="btn btn-emerald emerald-glow" id="start-btn" hidden data-testid="start-hand-button"><i data-lucide="play"></i> <span>Start</span></button>
          <button class="btn btn-sky" id="results-btn" hidden data-testid="back-to-lobby-button"><i data-lucide="trophy"></i> <span>Back to Lobby</span></button>
        </div>
        <div id="my-turn" class="my-turn" hidden>
          <div class="actions">
            <button class="btn btn-red" id="fold-btn" data-testid="fold-button">Fold</button>
            <button class="btn btn-emerald" id="check-btn" data-testid="check-button">Check</button>
            <button class="btn btn-emerald" id="call-btn" data-testid="call-button">Call</button>
            <button class="btn btn-gold gold-glow" id="raise-btn" data-testid="raise-button">Raise</button>
          </div>
          <div class="row gap raise-row" id="raise-row">
            <input type="range" id="raise-slider" class="slider" data-testid="bet-slider">
            <span class="mono gold raise-amount" id="raise-amount">0</span>
            <button class="link-btn" id="allin-btn" data-testid="all-in-button">All-In</button>
          </div>
        </div>
        <div id="status-line" class="center small muted"></div>
      </div>
      <div class="my-hand" id="my-hand" data-testid="my-hand"></div>
    </div>
  </div>

  <aside id="chat-drawer" class="chat-drawer glass" hidden>
    <div class="drawer-title font-heading">Table Chat</div>
    <div class="chat-body" id="chat-body"></div>
    <div class="chat-input row gap-sm">
      <input id="chat-text" class="input" placeholder="Say something..." maxlength="200" data-testid="chat-input">
      <button class="btn btn-gold btn-sq" id="chat-send" data-testid="chat-send-button"><i data-lucide="send"></i></button>
    </div>
  </aside>
</div>

<dialog id="buyin-dialog" class="dialog glass" data-testid="buyin-dialog">
  <h2 class="font-heading" id="buyin-title">Buy In</h2>
  <p class="muted" id="buyin-sub"></p>
  <div class="form">
    <div class="row gap raise-row" id="buyin-slider-row">
      <span class="small muted w14">Amount</span>
      <input type="range" id="buyin-slider" class="slider" data-testid="buyin-slider">
      <span class="mono gold w20 right" id="buyin-amount" data-testid="buyin-amount">0</span>
    </div>
    <div class="row gap small muted" style="justify-content:space-between"><span id="buyin-range"></span><span>Balance: <b class="mono gold" id="buyin-wallet"></b></span></div>
    <p class="form-error" id="buyin-error" hidden></p>
  </div>
  <button class="btn btn-gold btn-block" id="buyin-confirm" data-testid="buyin-confirm-button">Take Seat</button>
  <button class="dialog-close icon-btn" data-close><i data-lucide="x"></i></button>
</dialog>

<dialog id="history-dialog" class="dialog glass" data-testid="history-dialog">
  <h2 class="font-heading row gap-sm"><i data-lucide="history" class="gold"></i> Hand History</h2>
  <p class="muted">Recent hands at this table with winners and shown cards.</p>
  <div id="history-body" class="history-body"></div>
  <button class="dialog-close icon-btn" data-close><i data-lucide="x"></i></button>
</dialog>
<?php page_foot(['cards.js', 'sounds.js', 'rtc.js', 'table.js']); ?>
