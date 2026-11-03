// Lobby: create / join / profile / daily chips
(function () {
  const bp = window.BASE_PATH || "";
  const createDialog = document.getElementById("create-dialog");
  const profileDialog = document.getElementById("profile-dialog");
  const $ = (id) => document.getElementById(id);
  let mode = "cash";

  $("create-room-btn").addEventListener("click", () => createDialog.showModal());
  $("logout-btn").addEventListener("click", async () => {
    try { await API.post("/auth.php?action=logout"); } catch (e) { console.debug("logout failed", e); }
    window.location.href = bp + "/index.php";
  });

  // seats 2..10
  $("max-seats").innerHTML = Array.from({ length: 9 }, (_, i) => i + 2).map((n) => `<option value="${n}">${n} players</option>`).join("");

  const syncMode = () => {
    document.querySelectorAll(".seg").forEach((b) => b.classList.toggle("active", b.dataset.mode === mode));
    $("blinds-label").textContent = mode === "tournament" ? "Starting Blinds" : "Blinds";
    document.querySelectorAll(".cash-only").forEach((el) => (el.hidden = mode !== "cash"));
    document.querySelectorAll(".tourney-only").forEach((el) => (el.hidden = mode !== "tournament"));
    if (mode === "cash") {
      $("max-seats").value = "10";
      $("mode-note").textContent = "Cash game: hands start automatically once 2 players are seated. Players buy in from their balance and take their stack back when they leave.";
    } else {
      $("max-seats").value = "6";
      $("mode-note").textContent = "Tournament: entry fees form the prize pool. It auto-starts when every seat is filled, or you can start it early with 2+ players.";
    }
  };
  document.querySelectorAll(".seg").forEach((b) => b.addEventListener("click", () => { mode = b.dataset.mode; syncMode(); }));
  $("min-buyin").addEventListener("change", () => { if (Number($("max-buyin").value) < Number($("min-buyin").value)) $("max-buyin").value = $("min-buyin").value; });
  $("max-buyin").addEventListener("change", () => { if (Number($("max-buyin").value) < Number($("min-buyin").value)) $("min-buyin").value = $("max-buyin").value; });
  syncMode();

  $("confirm-create").addEventListener("click", async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true; btn.textContent = "Creating...";
    const [sb, bb] = $("blinds").value.split("/").map(Number);
    try {
      const data = await API.post("/rooms.php?action=create", {
        name: $("room-name").value,
        small_blind: sb, big_blind: bb,
        game_mode: mode,
        max_seats: Number($("max-seats").value),
        min_buyin: Number($("min-buyin").value), max_buyin: Number($("max-buyin").value),
        buy_in: Number($("buy-in").value), starting_stack: Number($("stack").value),
        payout: $("payout").value, allow_rebuys: $("allow-rebuys").checked,
        turn_seconds: Number($("turn-seconds").value),
      });
      toast(`Room ${data.code} created`, "success");
      window.location.href = `${bp}/table.php?code=${data.code}`;
    } catch (err) {
      toast(err.message, "error");
      btn.disabled = false; btn.textContent = "Create & Enter Table";
    }
  });

  const joinInput = $("join-code");
  const join = async () => {
    const code = joinInput.value.trim().toUpperCase();
    if (!code) return;
    try {
      await API.get(`/rooms.php?action=info&code=${encodeURIComponent(code)}`);
      window.location.href = `${bp}/table.php?code=${code}`;
    } catch (err) {
      toast(err.status === 404 ? "Room not found" : "Could not join room", "error");
    }
  };
  joinInput.addEventListener("input", () => { joinInput.value = joinInput.value.toUpperCase(); });
  joinInput.addEventListener("keydown", (e) => e.key === "Enter" && join());
  $("join-btn").addEventListener("click", join);

  // ---- daily chips ----
  const renderBonus = (u) => {
    const b = u.bonus || {};
    $("chips").textContent = fmt(u.chips);
    const low = u.chips < b.min_balance;
    $("bonus-box").hidden = !low;
    if (!low) return;
    $("claim-bonus").disabled = !b.eligible;
    $("bonus-text").textContent = b.eligible
      ? `Your balance is ${fmt(u.chips)}. Claim ${fmt(b.amount)} free chips (once every 24 hours).`
      : `Your balance is ${fmt(u.chips)}. Next free chips in ${Math.floor(b.next_in / 3600)}h ${Math.floor((b.next_in % 3600) / 60)}m.`;
  };
  $("claim-bonus").addEventListener("click", async () => {
    try {
      const data = await API.post("/auth.php?action=claim_bonus");
      window.CURRENT_USER = data.user;
      renderBonus(data.user);
      toast(`+${fmt(data.user.bonus.amount)} chips added to your balance`, "success");
    } catch (err) { toast(err.message, "error"); }
  });
  renderBonus(window.CURRENT_USER);

  const openProfile = async () => {
    let u = window.CURRENT_USER;
    try { u = (await API.get("/auth.php?action=me")).user; window.CURRENT_USER = u; } catch (e) { console.debug("profile refresh failed", e); }
    const s = u.stats || {};
    const winRate = s.hands_played ? Math.round((s.hands_won / s.hands_played) * 100) : 0;
    const items = [
      ["Chip Balance", fmt(u.chips)], ["Hands Played", fmt(s.hands_played)], ["Hands Won", fmt(s.hands_won)], ["Win Rate", winRate + "%"],
      ["Best Hand", s.best_hand || "—"], ["Biggest Pot", fmt(s.biggest_pot)], ["Total Winnings", fmt(s.total_winnings)],
    ];
    $("stats-grid").innerHTML = items.map(([l, v], i) =>
      `<div class="stat glass" data-testid="stat-${i}"><div class="lbl">${l}</div><div class="val mono">${escapeHtml(v)}</div></div>`).join("");
    renderBonus(u);
    profileDialog.showModal();
  };
  $("profile-btn").addEventListener("click", openProfile);
  $("profile-card").addEventListener("click", openProfile);
})();
