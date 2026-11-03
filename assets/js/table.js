// Live table: polls the PHP API every second, renders state, relays WebRTC signaling.
(function () {
  const bp = window.BASE_PATH || "";
  const me = window.CURRENT_USER;
  const myId = Number(me.id);
  const code = window.ROOM_CODE;
  const POLL_MS = 1000;
  // seats are spread around the felt ellipse: seat 0 at the bottom, then clockwise via the left side
  const seatAngle = (i, n) => Math.PI / 2 + (i * 2 * Math.PI) / n;
  const seatPos = (i, n, rx = 50, ry = 50) => {
    const a = seatAngle(i, n);
    return { left: `${50 + rx * Math.cos(a)}%`, top: `${50 + ry * Math.sin(a)}%` };
  };
  const betPos = (i, n) => seatPos(i, n, 31, 26);

  // size the felt so that seats (which straddle the rim) always fit in the free area
  function fitTable() {
    const area = $("table-area"), box = $("table-box");
    if (!area || !box) return;
    const mobile = window.innerWidth < 640;
    // phones get a portrait (tall) felt so the seats fit around it
    box.style.aspectRatio = mobile ? "10 / 16" : "16 / 10";
    const padX = mobile ? 68 : 140, padY = mobile ? 60 : 80;
    const freeW = area.clientWidth - padX * 2, freeH = area.clientHeight - padY * 2;
    const w = Math.max(200, Math.min(freeW, mobile ? freeH / 1.6 : freeH * 1.6, 1200));
    box.style.width = `${w}px`;
  }
  window.addEventListener("resize", fitTable);
  const EMOJIS = ["😀", "😎", "😂", "😱", "😡", "🔥", "👏", "💰", "👍", "🃏"];

  const $ = (id) => document.getElementById(id);
  let wallet = null, wasSeated = false;
  let state = null, version = 0, eventCursor = -1, hostId = null, serverOffset = 0;
  let micOn = true, camOn = true, raiseAmount = 0, lastRaiseKey = "", stopped = false;
  let prevHand = 0, prevCommunity = 0, prevPot = 0, prevPotHand = 0, lastWinnerKey = null;
  const chat = [];
  const videos = {}; // user_id -> <video>
  const audios = {}; // user_id -> <audio> (remote sound, always in the DOM)
  const streams = {}; // user_id -> MediaStream

  const mesh = new PeerMesh({
    myId,
    iceServers: window.ICE_SERVERS,
    sendSignal: (type, to, data) => API.post(`/game.php?action=signal&code=${code}`, { type, to, data }).catch((e) => console.debug("signal send failed", e)),
    onStream: (pid, stream) => {
      streams[pid] = stream;
      stream.getTracks().forEach((t) => { t.onunmute = t.onmute = t.onended = () => attachStream(pid); });
      stream.onaddtrack = stream.onremovetrack = () => attachStream(pid);
      attachStream(pid);
    },
    onStreamGone: (pid) => {
      delete streams[pid];
      if (videos[pid]) { videos[pid].remove(); delete videos[pid]; }
      if (audios[pid]) { audios[pid].remove(); delete audios[pid]; }
    },
  });

  // ---- API helpers ----
  const act = async (action, body) => {
    try {
      const data = await API.post(`/game.php?action=${action}&code=${code}&v=${version}&ev=${eventCursor}`, body || {});
      applyPoll(data);
    } catch (err) { toast(err.message, "error"); }
  };

  async function pollLoop() {
    if (stopped) return;
    try {
      const data = await API.get(`/game.php?action=poll&code=${code}&v=${version}&ev=${eventCursor}`);
      applyPoll(data);
    } catch (err) {
      if (err.status === 404) { toast("This table no longer exists", "error"); stopped = true; setTimeout(() => (window.location.href = bp + "/lobby.php"), 1500); return; }
      if (err.status === 401) { window.location.href = bp + "/index.php"; return; }
    }
    if (!stopped) setTimeout(pollLoop, POLL_MS);
  }

  function applyPoll(data) {
    if (!data || !data.ok) return;
    serverOffset = data.server_now - Date.now();
    hostId = Number(data.host_id);
    $("room-name").textContent = data.room_name;
    if (typeof data.wallet === "number") { wallet = data.wallet; $("wallet").textContent = fmt(wallet); }
    if (data.state && data.version > version) {
      version = data.version;
      onState(data.state);
    } else if (data.version > version) {
      version = data.version;
    }
    eventCursor = data.event_cursor;
    (data.events || []).forEach(onEvent);
    mesh.syncPeers((data.peers || []).map((p) => Number(p.user_id)));
    (data.signals || []).forEach((s) => mesh.handleSignal(s));
  }

  // ---- state handling ----
  function onState(s) {
    const first = !state;
    state = s;
    if (first) { $("loading").hidden = true; $("table-page").hidden = false; fitTable(); }
    // sounds
    const hn = s.hand_no || 0, cl = (s.community || []).length;
    if ((hn > prevHand || cl > prevCommunity) && (prevHand !== 0 || hn !== 0) && !first) Sounds.play("deal");
    prevHand = hn; prevCommunity = cl;
    if (s.pot > prevPot && hn === prevPotHand && hn !== 0) Sounds.play("chip");
    prevPot = s.pot; prevPotHand = hn;
    // winner toast
    if (s.last_winners && s.last_winners.length) {
      const key = JSON.stringify(s.last_winners) + hn;
      if (lastWinnerKey !== key) {
        lastWinnerKey = key;
        if (!first) celebrate(s.last_winners);
        toast(s.last_winners.map((w) => `${w.name} wins ${fmt(w.amount)}${w.hand ? ` (${w.hand})` : ""}`).join(" • "), "success", 5000);
      }
    }
    render();
  }

  function onEvent(ev) {
    if (ev.type === "chat") { chat.push(ev); if (chat.length > 40) chat.shift(); renderChat(); }
    else if (ev.type === "reaction") showReaction(ev.user_id, ev.payload);
  }

  // ---- rendering ----
  function render() {
    const s = state;
    const seatMap = {};
    s.players.forEach((p) => (seatMap[p.seat] = p));
    const mySeat = s.your_seat;
    const seated = mySeat !== null && mySeat !== undefined;
    const myPlayer = seated ? seatMap[mySeat] : null;
    const isHost = myId === hostId;
    const legal = s.legal_actions;
    const eligible = s.players.filter((p) => p.stack > 0 && !p.away).length;

    // header
    const badge = $("mode-badge");
    badge.className = `badge hide-sm ${s.game_mode}`;
    badge.textContent = s.game_mode === "tournament" ? `Tourney · Lvl ${s.level}` : "Cash";
    $("blinds-line").textContent = `Blinds ${s.small_blind}/${s.big_blind}`;

    // blind schedule banner
    const sched = s.blind_schedule;
    $("blind-banner").hidden = !sched;
    if (sched) {
      $("blind-banner").innerHTML =
        `<span>Level <b class="mono gold">${sched.level}</b></span><span class="muted">·</span>` +
        `<span>Blinds <b class="mono gold">${sched.current_small}/${sched.current_big}</b></span><span class="muted">·</span>` +
        `<span class="muted row gap-sm"><i data-lucide="clock" class="emerald i-13"></i> Next <b class="mono emerald">${sched.next_small}/${sched.next_big}</b> in <b class="mono emerald">${sched.hands_to_next}</b> ${sched.hands_to_next === 1 ? "hand" : "hands"}</span>`;
    }

    // community + pot
    $("community").innerHTML = s.community.length === 0
      ? `<span class="placeholder" data-testid="felt-status">${s.next_hand_at ? `Dealing in <b data-countdown></b>` : s.phase === "waiting" ? (eligible < 2 || (s.tournament && !s.tournament.started) ? "Waiting for players" : "Get ready") : "Dealing..."}</span>`
      : s.community.map((c, i) => cardHtml(c, "lg", i, true)).join("");
    $("pot").textContent = fmt(s.pot);

    // seats
    const seatsEl = $("seats");
    seatsEl.innerHTML = "";
    $("bets").innerHTML = s.players.filter((p) => p.round_bet > 0).map((p) => {
      const pos = betPos(p.seat, s.max_seats);
      return `<div class="bet" style="left:${pos.left};top:${pos.top}" data-testid="bet-${p.seat}">${fmt(p.round_bet)}</div>`;
    }).join("");
    const t = s.tournament;
    const canSit = !seated && !(t && (t.started || t.finished));
    for (let i = 0; i < s.max_seats; i++) {
      const wrap = document.createElement("div");
      wrap.className = "seat";
      Object.assign(wrap.style, seatPos(i, s.max_seats));
      const p = seatMap[i];
      if (p) wrap.innerHTML = seatHtml(p, i);
      else if (canSit) wrap.innerHTML = `<button class="empty-seat" data-seat="${i}" data-testid="seat-button-${i}"><i data-lucide="crown"></i><span>Sit</span></button>`;
      seatsEl.appendChild(wrap);
    }
    seatsEl.querySelectorAll(".empty-seat").forEach((b) => b.addEventListener("click", () => openBuyIn("sit", Number(b.dataset.seat))));
    seatsEl.querySelectorAll("[data-mic]").forEach((b) => b.addEventListener("click", toggleMic));
    seatsEl.querySelectorAll("[data-cam]").forEach((b) => b.addEventListener("click", toggleCam));
    s.players.forEach((p) => attachStream(p.user_id));
    fitTable();
    $("my-hand").innerHTML = myPlayer && myPlayer.hole && myPlayer.hole.length && myPlayer.hole[0]
      ? myPlayer.hole.map((c, j) => cardHtml(c, "md", j, false)).join("") : "";

    // reaction bar / away banner
    $("emoji-btn").hidden = !seated;
    if (!seated) $("reaction-bar").hidden = true;
    if (seated && !$("reaction-bar").children.length) {
      $("reaction-bar").innerHTML = EMOJIS.map((e) => `<button data-emoji="${e}" data-testid="reaction-${e}">${e}</button>`).join("");
      $("reaction-bar").querySelectorAll("button").forEach((b) => b.addEventListener("click", () => { $("reaction-bar").hidden = true; act("reaction", { emoji: b.dataset.emoji }); }));
    }
    $("away-banner").hidden = !(myPlayer && myPlayer.away);

    // host / rebuy row
    const between = ["waiting", "showdown"].includes(s.phase);
    const preTourney = t && !t.started;
    const canStart = isHost && between && !(t && t.finished) && (preTourney ? s.players.length >= 2 : eligible >= 2);
    $("start-btn").hidden = !canStart;
    $("start-btn").querySelector("span").textContent = preTourney ? `Start Tournament (${s.players.length}/${s.max_seats})` : "Deal Now";
    const canRebuy = s.can_rebuy && !(t && t.finished);
    $("rebuy-btn").hidden = !canRebuy;
    $("rebuy-btn").querySelector("span").textContent = t ? `Rebuy (${fmt(s.buy_in)} chips)` : "Add chips";
    $("results-btn").hidden = !(t && t.finished);
    $("host-row").hidden = !canStart && !canRebuy && !(t && t.finished);
    renderTournament(s, myPlayer);
    if (wasSeated && !seated && t && t.started) toast(t.finished ? "Tournament over" : "You're out of the tournament — you can keep watching", "info", 6000);
    wasSeated = seated;

    // my turn
    const myTurn = !!legal;
    $("my-turn").hidden = !myTurn;
    $("status-line").hidden = myTurn;
    if (myTurn) {
      const r = legal.raise;
      $("raise-row").hidden = !r;
      $("raise-btn").hidden = !r;
      $("allin-btn").hidden = !r;
      $("check-btn").hidden = !legal.check;
      $("call-btn").hidden = !!legal.check;
      $("call-btn").textContent = `Call ${fmt(legal.call)}`;
      if (r) {
        const key = `${r.min}-${r.max}-${s.to_act}-${s.hand_no}`;
        if (key !== lastRaiseKey) { lastRaiseKey = key; raiseAmount = r.min; }
        const slider = $("raise-slider");
        slider.min = r.min; slider.max = r.max; slider.step = s.big_blind; slider.value = raiseAmount;
        $("allin-btn").textContent = `Go All-In (${fmt(r.max)})`;
        updateRaiseLabel();
      }
    } else {
      $("status-line").innerHTML = !seated ? (t && (t.started || t.finished) ? "Spectating — registration is closed" : "Pick a seat to join the game")
        : s.to_act != null ? `Waiting for ${escapeHtml((seatMap[s.to_act] || {}).name || "player")}...`
        : t && t.finished ? "Tournament finished"
        : s.next_hand_at ? (t && t.remaining <= 1 ? `Finishing tournament in <b class="gold mono" data-countdown></b>` : `${s.phase === "showdown" ? "Next hand" : "First hand"} starts in <b class="gold mono" data-countdown></b>`)
        : t && !t.started ? `Waiting for players (${s.players.length}/${s.max_seats}) — auto-starts when full${isHost ? ", or press Start Tournament" : ""}`
        : eligible < 2 ? "Waiting for at least 2 players with chips — share the room code" : "Hand complete";
      updateCountdown();
    }

    renderChat();
    renderHistory();
    refreshIcons();
  }

  function seatHtml(p, i) {
    const isSelf = p.user_id === myId;
    const folded = p.status === "folded";
    const tag = folded ? ["red", "Folded"] : p.away ? ["sky", "Away"] : null;
    const inHand = !["waiting", "showdown"].includes(state.phase);
    const win = state.phase === "showdown" && (state.last_winners || []).find((w) => w.user_id === p.user_id);
    const cardsLeft = Math.cos(seatAngle(i, state.max_seats)) > 0.1; // right-hand seats keep cards on the inner side
    const cards = p.hole && p.hole.length && !tag ? `<div class="hole" data-testid="hole-cards-${i}">${p.hole.map((c, j) => cardHtml(c, window.innerWidth < 640 ? "xs" : "sm", j, false)).join("")}</div>` : "";
    return `<div class="seat-inner ${folded ? "folded" : ""} ${inHand && !p.is_turn && !folded ? "waiting" : ""}" data-testid="player-seat-${i}">
      ${p.is_turn && inHand ? `<div class="turn-label" data-testid="turn-label-${i}">${isSelf ? "Your turn" : "To act"}</div>` : ""}
      ${win ? `<div class="winner-badge" data-testid="winner-badge-${i}"><i data-lucide="trophy"></i> +${fmt(win.amount)}</div>` : ""}
      <div class="seat-row">
        ${cardsLeft ? cards : ""}
        <div class="tile ${p.is_turn && inHand ? "turn" : ""} ${win ? "winner" : ""}" data-tile="${p.user_id}">
          <div class="initials">${escapeHtml(initials(p.name))}</div>
          ${p.is_button ? `<div class="badge-tl dealer" title="Dealer button" data-testid="dealer-button-${i}">D</div>` : ""}
          ${p.is_turn && state.turn_deadline ? `<div class="badge-tr timer" data-timer="${i}" data-testid="turn-timer-${i}"><div><span></span></div></div>` : ""}
          ${tag ? `<div class="tile-tag ${tag[0]}">${tag[1]}</div>` : ""}
          ${isSelf && !tag ? `<div class="av">
            <button class="${micOn ? "" : "off"}" data-mic title="Microphone" data-testid="toggle-mic-button"><i data-lucide="${micOn ? "mic" : "mic-off"}"></i></button>
            <button class="${camOn ? "" : "off"}" data-cam title="Camera" data-testid="toggle-cam-button"><i data-lucide="${camOn ? "video" : "video-off"}"></i></button>
          </div>` : ""}
        </div>
        ${cardsLeft ? "" : cards}
      </div>
      <div class="nameplate ${p.is_turn && inHand ? "turn" : ""} ${win ? "winner" : ""}"><div class="nm">${escapeHtml(p.name)}</div><div class="st">${fmt(p.stack)}</div>${win && win.hand ? `<div class="act gold">${escapeHtml(win.hand)}</div>` : p.last_action && !folded ? `<div class="act">${escapeHtml(p.last_action)}</div>` : ""}</div>
    </div>`;
  }

  // ---- winner celebration ----
  function celebrate(winners) {
    const layer = $("reactions-layer");
    const colors = ["#F59E0B", "#FDE68A", "#10B981", "#38BDF8", "#F472B6", "#ffffff"];
    for (let k = 0; k < 70; k++) {
      const c = document.createElement("i");
      c.className = "confetti";
      c.style.left = `${Math.random() * 100}%`;
      c.style.background = colors[k % colors.length];
      c.style.animationDelay = `${Math.random() * 0.8}s`;
      c.style.animationDuration = `${2.2 + Math.random() * 1.4}s`;
      c.style.transform = `rotate(${Math.random() * 360}deg)`;
      c.style.width = `${6 + Math.random() * 6}px`;
      layer.appendChild(c);
      setTimeout(() => c.remove(), 4500);
    }
    const banner = document.createElement("div");
    banner.className = "win-banner";
    banner.setAttribute("data-testid", "win-banner");
    banner.innerHTML = winners.map((w) => `<div class="win-name">${escapeHtml(w.name)} wins</div><div class="win-amt mono">+${fmt(w.amount)}</div>${w.hand ? `<div class="win-hand">${escapeHtml(w.hand)}</div>` : ""}`).join("");
    layer.appendChild(banner);
    setTimeout(() => banner.remove(), 3800);
    Sounds.play("fanfare");
  }

  function attachStream(uid) {
    const stream = uid === myId ? mesh.localStream : streams[uid];
    if (!stream) return;
    if (uid !== myId) {
      let a = audios[uid];
      if (!a) { a = document.createElement("audio"); a.autoplay = true; audios[uid] = a; $("audio-sink").appendChild(a); }
      if (a.srcObject !== stream) a.srcObject = stream;
      if (a.paused) a.play().catch(() => {});
    }
    const tile = document.querySelector(`[data-tile="${uid}"]`);
    if (!tile) return;
    const hasVideo = stream.getVideoTracks().some((t) => t.readyState === "live" && (uid === myId || !t.muted));
    if ((uid === myId && !camOn) || !hasVideo) { if (videos[uid]) videos[uid].remove(); return; }
    let v = videos[uid];
    if (!v) {
      v = document.createElement("video");
      v.autoplay = true; v.playsInline = true; v.muted = true;
      if (uid === myId) v.classList.add("mirror");
      videos[uid] = v;
    }
    if (v.srcObject !== stream) v.srcObject = stream;
    const ini = tile.querySelector(".initials");
    if (ini) ini.replaceWith(v); else if (v.parentElement !== tile) tile.prepend(v);
    if (v.paused) v.play().catch(() => {});
  }

  function renderChat() {
    const body = $("chat-body");
    body.innerHTML = (state.log || []).map((l) => `<div class="log">${escapeHtml(l)}</div>`).join("") +
      chat.map((m) => `<div class="msg"><b>${escapeHtml(m.name)}: </b><span>${escapeHtml(m.payload)}</span></div>`).join("");
    body.scrollTop = body.scrollHeight;
  }

  function renderHistory() {
    const h = state.history || [];
    $("history-body").innerHTML = !h.length ? `<div class="center muted small" style="padding:24px 0">No hands played yet.</div>` :
      h.map((x) => `<div class="hist glass" data-testid="history-hand-${x.hand_no}">
        <div class="top"><span>Hand #${x.hand_no}</span><span class="mono">Blinds ${x.small_blind}/${x.big_blind}</span></div>
        ${x.community && x.community.length ? `<div class="cards">${x.community.map((c) => cardHtml(c, "sm", 0, false)).join("")}</div>` : ""}
        ${(x.winners || []).map((w) => `<div class="win"><b>${escapeHtml(w.name)}</b> won <span class="amt">${fmt(w.amount)}</span>${w.hand ? ` <span class="muted">with ${w.hand}</span>` : ""}</div>`).join("")}
        ${x.shown && x.shown.length ? `<div class="shown">${x.shown.map((sh) => `<div class="row"><span class="nm">${escapeHtml(sh.name)}</span><div class="hole">${sh.hole.map((c) => cardHtml(c, "sm", 0, false)).join("")}</div></div>`).join("")}</div>` : ""}
      </div>`).join("");
  }

  function showReaction(uid, emoji) {
    const p = state && state.players.find((x) => x.user_id === uid);
    if (!p) return;
    const el = document.createElement("div");
    el.className = "reaction-float";
    el.textContent = emoji;
    Object.assign(el.style, seatPos(p.seat, state.max_seats, 40, 38));
    $("reactions-layer").appendChild(el);
    Sounds.play("pop");
    setTimeout(() => el.remove(), 2400);
  }

  function updateCountdown() {
    if (!state || !state.next_hand_at) return;
    const secs = Math.max(0, Math.ceil((state.next_hand_at - (Date.now() + serverOffset)) / 1000));
    document.querySelectorAll("[data-countdown]").forEach((el) => { el.textContent = `${secs}s`; });
  }

  // countdown ticks locally between polls
  setInterval(() => {
    updateCountdown();
    if (!state || !state.turn_deadline) return;
    const remaining = state.turn_deadline - (Date.now() + serverOffset);
    const total = (state.turn_seconds || 20) * 1000;
    document.querySelectorAll("[data-timer]").forEach((el) => {
      const pct = Math.max(0, Math.min(1, remaining / total));
      const color = pct < 0.3 ? "#EF4444" : "#F59E0B";
      el.style.background = `conic-gradient(${color} ${pct * 360}deg, rgba(255,255,255,0.12) 0deg)`;
      const span = el.querySelector("span");
      span.textContent = Math.max(0, Math.ceil(remaining / 1000));
      span.style.color = color;
    });
  }, 250);

  // ---- controls ----
  function updateRaiseLabel() {
    const r = state.legal_actions && state.legal_actions.raise;
    $("raise-amount").textContent = fmt(raiseAmount);
    $("raise-btn").textContent = r && raiseAmount >= r.max ? "All-In" : `Raise ${fmt(raiseAmount)}`;
  }
  $("raise-slider").addEventListener("input", (e) => { raiseAmount = Number(e.target.value); updateRaiseLabel(); });
  $("allin-btn").addEventListener("click", () => { raiseAmount = state.legal_actions.raise.max; $("raise-slider").value = raiseAmount; updateRaiseLabel(); });
  $("fold-btn").addEventListener("click", () => act("act", { action_type: "fold" }));
  $("check-btn").addEventListener("click", () => act("act", { action_type: "check" }));
  $("call-btn").addEventListener("click", () => act("act", { action_type: "call" }));
  $("raise-btn").addEventListener("click", () => act("act", { action_type: "raise", amount: raiseAmount }));
  $("start-btn").addEventListener("click", () => act("start"));
  $("rebuy-btn").addEventListener("click", () => openBuyIn("rebuy"));
  $("emoji-btn").addEventListener("click", (e) => { e.stopPropagation(); $("reaction-bar").hidden = !$("reaction-bar").hidden; });
  document.addEventListener("click", (e) => { if (!e.target.closest(".emoji-wrap")) $("reaction-bar").hidden = true; });
  $("results-btn").addEventListener("click", () => { window.location.href = bp + "/lobby.php"; });

  // ---- buy-in / rebuy dialog ----
  let buyCtx = null;
  function openBuyIn(kind, seat) {
    const s = state; const t = s.tournament; const w = wallet || 0;
    const dlg = $("buyin-dialog");
    $("buyin-error").hidden = true;
    let min, max, fixed = null, title, sub;
    if (t) {
      fixed = s.buy_in;
      title = kind === "sit" ? "Enter Tournament" : "Rebuy";
      sub = fixed > 0 ? `Entry fee: ${fmt(fixed)} chips from your balance. You start with ${fmt(s.starting_stack)} tournament chips.` : `Free entry — you start with ${fmt(s.starting_stack)} tournament chips.`;
      if (kind === "rebuy") sub = `Rebuy for ${fmt(fixed)} chips (added to the prize pool) and get ${fmt(s.starting_stack)} chips back in play.`;
    } else {
      const me = s.players.find((p) => p.user_id === myId);
      title = kind === "sit" ? "Buy In" : "Add Chips";
      min = kind === "sit" ? s.min_buyin : 1;
      max = kind === "sit" ? s.max_buyin : s.max_buyin - (me ? me.stack : 0);
      max = Math.min(max, w);
      sub = kind === "sit" ? `Choose how many chips to bring to the table (${fmt(s.min_buyin)} – ${fmt(s.max_buyin)}). The rest stays in your balance.` : `Top up your stack (table max ${fmt(s.max_buyin)}).`;
    }
    $("buyin-title").textContent = title; $("buyin-sub").textContent = sub; $("buyin-wallet").textContent = fmt(w);
    $("buyin-slider-row").hidden = fixed !== null;
    $("buyin-range").textContent = fixed !== null ? `Cost: ${fmt(fixed)}` : `Min ${fmt(min)} · Max ${fmt(max)}`;
    if (fixed !== null) {
      $("buyin-amount").textContent = fmt(fixed);
      if (fixed > w) { $("buyin-error").textContent = `You need ${fmt(fixed)} chips but only have ${fmt(w)}. Claim free daily chips in the lobby.`; $("buyin-error").hidden = false; }
    } else {
      if (max < min) { $("buyin-error").textContent = `You need at least ${fmt(min)} chips to sit here (you have ${fmt(w)}). Claim free daily chips in the lobby.`; $("buyin-error").hidden = false; }
      const sl = $("buyin-slider"); sl.min = min; sl.max = Math.max(min, max); sl.step = Math.max(1, Math.round(s.big_blind || 1)); sl.value = Math.max(min, max);
      $("buyin-amount").textContent = fmt(sl.value);
    }
    $("buyin-confirm").textContent = kind === "sit" ? "Take Seat" : (t ? "Rebuy" : "Add Chips");
    $("buyin-confirm").disabled = !$("buyin-error").hidden;
    buyCtx = { kind, seat, fixed };
    dlg.showModal();
  }
  $("buyin-slider").addEventListener("input", (e) => { $("buyin-amount").textContent = fmt(e.target.value); });
  $("buyin-confirm").addEventListener("click", async () => {
    if (!buyCtx) return;
    const amount = buyCtx.fixed !== null ? buyCtx.fixed : Number($("buyin-slider").value);
    $("buyin-dialog").close();
    if (buyCtx.kind === "sit") await act("sit", { seat: buyCtx.seat, amount });
    else await act("rebuy", { amount });
    buyCtx = null;
  });

  function renderTournament(s, myPlayer) {
    const t = s.tournament;
    $("tourney-banner").hidden = !t;
    $("results-banner").hidden = !(t && t.finished && t.results);
    if (!t) return;
    const payout = t.payout === "top3" ? "Top 3 paid" : "Winner takes all";
    const status = t.finished ? "Finished" : t.started ? `Running · ${t.remaining} of ${t.entrants} left` : `Registration open · ${s.players.length}/${s.max_seats} seats`;
    $("tourney-banner").innerHTML =
      `<span class="row gap-sm"><i data-lucide="trophy" class="gold i-13"></i> Prize pool <b class="mono gold" data-testid="prize-pool">${fmt(t.prize_pool)}</b></span><span class="muted">·</span>` +
      `<span>${payout}</span><span class="muted">·</span><span class="muted">${status}</span>`;
    if (t.finished && t.results) {
      $("results-banner").innerHTML = `<h3 class="font-heading">Tournament Results</h3>` +
        t.results.map((r) => `<div class="place"><span>#${r.place} ${escapeHtml(r.name)}${r.user_id === myId ? " (you)" : ""}</span><b class="mono gold">+${fmt(r.amount)} chips</b></div>`).join("");
    }
  }
  $("return-btn").addEventListener("click", () => act("return"));
  $("leave-btn").addEventListener("click", async () => {
    stopped = true;
    try { await API.post(`/game.php?action=leave&code=${code}`); } catch (e) {}
    mesh.closeAll();
    window.location.href = bp + "/lobby.php";
  });
  $("copy-code").addEventListener("click", async () => {
    try { await navigator.clipboard.writeText(code); } catch (e) {
      const el = document.createElement("textarea"); el.value = code; document.body.appendChild(el); el.select();
      try { document.execCommand("copy"); } catch (e2) {} el.remove();
    }
    toast("Room code copied", "success");
  });
  $("chat-btn").addEventListener("click", () => { $("chat-drawer").hidden = !$("chat-drawer").hidden; });
  $("history-btn").addEventListener("click", () => $("history-dialog").showModal());
  const sendChat = () => {
    const t = $("chat-text").value.trim();
    if (!t) return;
    $("chat-text").value = "";
    act("chat", { text: t });
  };
  $("chat-send").addEventListener("click", sendChat);
  $("chat-text").addEventListener("keydown", (e) => e.key === "Enter" && sendChat());
  const syncSoundBtn = () => {
    $("sound-btn").classList.toggle("on", Sounds.enabled);
    $("sound-btn").innerHTML = `<i data-lucide="${Sounds.enabled ? "volume-2" : "volume-x"}"></i>`;
    refreshIcons();
  };
  $("sound-btn").addEventListener("click", () => { Sounds.setEnabled(!Sounds.enabled); syncSoundBtn(); });
  syncSoundBtn();

  function toggleMic() {
    const t = mesh.localStream && mesh.localStream.getAudioTracks()[0];
    if (!t) return startMedia();
    t.enabled = !t.enabled; micOn = t.enabled; render();
  }
  function toggleCam() {
    const t = mesh.localStream && mesh.localStream.getVideoTracks()[0];
    if (!t) return startMedia();
    t.enabled = !t.enabled; camOn = t.enabled; render();
  }

  // Ask for camera + mic; falls back to audio-only; can be retried any time via the header button
  async function startMedia() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      toast("This browser can't access the camera here. Make sure the site is opened over https://", "error", 6000);
      return false;
    }
    const attempts = [{ video: true, audio: true }, { video: false, audio: true }];
    for (const constraints of attempts) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        mesh.setLocalStream(stream);
        micOn = stream.getAudioTracks().length > 0;
        camOn = stream.getVideoTracks().length > 0;
        $("media-btn").hidden = true;
        if (!camOn) toast("Microphone on — no camera found, joining with audio only", "info", 5000);
        if (state) render();
        return true;
      } catch (e) {
        if (e && (e.name === "NotAllowedError" || e.name === "SecurityError")) {
          toast("Camera/mic blocked. Click the camera/lock icon in your browser's address bar, allow access, then press the camera button up top.", "error", 8000);
          return false;
        }
      }
    }
    toast("No camera or microphone found — playing without video. Press the camera button up top to retry.", "info", 6000);
    return false;
  }
  $("media-btn").addEventListener("click", startMedia);

  window.addEventListener("beforeunload", () => {
    mesh.closeAll();
  });

  // ---- boot ----
  (async function init() {
    const ok = await startMedia();
    $("media-btn").hidden = ok;
    pollLoop();
  })();
})();
