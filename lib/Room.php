<?php
// Room persistence + server-side housekeeping (turn timers, stats, presence)

class Room {
    public $id; public $code; public $name; public $host_id; public $version;
    public PokerGame $game; public array $meta;
    private PDO $db; private bool $dirty = false;

    public static function open(PDO $db, string $code, bool $forUpdate = false): ?Room {
        $sql = 'SELECT * FROM rooms WHERE code = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $st = $db->prepare($sql);
        $st->execute([strtoupper($code)]);
        $row = $st->fetch();
        if (!$row) return null;
        $r = new Room();
        $r->db = $db; $r->id = (int)$row['id']; $r->code = $row['code']; $r->name = $row['name'];
        $r->host_id = (int)$row['host_id']; $r->version = (int)$row['version'];
        $r->game = PokerGame::fromArray(json_decode($row['state'], true));
        $r->meta = json_decode($row['meta'], true) ?: [];
        $r->meta += self::defaultMeta();
        return $r;
    }

    private static function defaultMeta(): array {
        return ['dealt_in' => [], 'recorded_hand' => 0, 'history' => [], 'turn_seat' => null, 'turn_deadline' => null, 'next_hand_at' => null,
            'payout' => 'winner', 'prize_pool' => 0, 'started' => false, 'finished' => false, 'finish_order' => [], 'results' => null, 'entrants' => []];
    }

    // ---- wallet (users.chips) ----
    public function wallet(int $uid): int {
        $st = $this->db->prepare('SELECT chips FROM users WHERE id = ?');
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    }

    private function debitWallet(int $uid, int $amount): bool {
        $st = $this->db->prepare('UPDATE users SET chips = chips - ? WHERE id = ? AND chips >= ?');
        $st->execute([$amount, $uid, $amount]);
        return $st->rowCount() > 0;
    }

    private function creditWallet(int $uid, int $amount): void {
        if ($amount <= 0) return;
        $this->db->prepare('UPDATE users SET chips = chips + ? WHERE id = ?')->execute([$amount, $uid]);
    }

    public function isTournament(): bool { return $this->game->game_mode === 'tournament'; }

    // ---- seating with money ----
    public function sitPlayer(int $uid, string $name, ?string $avatar, int $seat, int $amount): array {
        $g = $this->game;
        if ($g->findSeat($uid) !== null) return [false, 'Already seated'];
        if ($seat < 0 || $seat >= $g->max_seats || $g->seats[$seat] !== null) return [false, 'Seat not available'];
        if ($this->isTournament()) {
            if ($this->meta['started']) return [false, 'This tournament has already started'];
            $cost = $g->buy_in; $stack = $g->starting_stack;
        } else {
            if ($amount < $g->min_buyin || $amount > $g->max_buyin) return [false, "Buy-in must be between {$g->min_buyin} and {$g->max_buyin}"];
            $cost = $amount; $stack = $amount;
        }
        if ($cost > 0 && !$this->debitWallet($uid, $cost)) return [false, "Not enough chips — you need $cost (claim your free daily chips in the lobby)"];
        [$ok, $err] = $g->sit($seat, $uid, $name, $avatar, $stack);
        if (!$ok) { $this->creditWallet($uid, $cost); return [false, $err]; }
        if ($this->isTournament()) {
            $this->meta['prize_pool'] += $cost;
            if (!in_array($uid, $this->meta['entrants'])) $this->meta['entrants'][] = $uid;
        }
        $this->touch();
        $this->armTurnTimer();
        return [true, null];
    }

    // Leaving: cash players take their stack back to the wallet; tournament players forfeit (eliminated)
    public function leavePlayer(int $uid): void {
        $g = $this->game;
        $seat = $g->findSeat($uid);
        if ($seat === null) return;
        $p = $g->seats[$seat];
        if (!$this->isTournament()) {
            $this->creditWallet($uid, $p->stack);
        } elseif (!$this->meta['started']) {
            // leaving before the tournament starts refunds the entry fee
            $this->creditWallet($uid, $g->buy_in);
            $this->meta['prize_pool'] = max(0, $this->meta['prize_pool'] - $g->buy_in);
            $this->meta['entrants'] = array_values(array_filter($this->meta['entrants'], fn($e) => (int)$e !== $uid));
        } elseif (!$this->meta['finished'] && !in_array($uid, $this->meta['finish_order'])) {
            $this->meta['finish_order'][] = $uid;
        }
        $g->leave($uid);
        $this->touch();
        $this->recordHandStats();
        $this->armTurnTimer();
        if ($this->isTournament()) $this->checkTournamentEnd();
    }

    public function addChips(int $uid, int $amount): array {
        $g = $this->game;
        $seat = $g->findSeat($uid);
        if ($seat === null) return [false, 'You are not seated'];
        $p = $g->seats[$seat];
        if ($this->isTournament()) {
            if (!$g->allow_rebuys) return [false, 'Rebuys are disabled in this tournament'];
            if ($this->meta['finished']) return [false, 'The tournament is over'];
            if ($p->stack > 0) return [false, 'You can only rebuy when you are out of chips'];
            $cost = $g->buy_in; $add = $g->starting_stack;
        } else {
            $room = $g->max_buyin - $p->stack;
            if ($room <= 0) return [false, 'You are already at the maximum buy-in'];
            $add = min($amount, $room);
            if ($add <= 0) return [false, 'Enter an amount'];
            $cost = $add;
        }
        if (!in_array($g->phase, ['waiting', 'showdown'])) return [false, 'Wait until the current hand ends'];
        if ($cost > 0 && !$this->debitWallet($uid, $cost)) return [false, "Not enough chips — you need $cost"];
        [$ok, $err] = $g->addChips($uid, $add);
        if (!$ok) { $this->creditWallet($uid, $cost); return [false, $err]; }
        if ($this->isTournament()) $this->meta['prize_pool'] += $cost;
        $this->touch();
        return [true, null];
    }

    public static function create(PDO $db, int $hostId, string $name, array $s): string {
        $db->prepare('DELETE FROM rooms WHERE updated_at < (NOW() - INTERVAL 1 DAY)')->execute();
        $game = new PokerGame($s['small_blind'], $s['big_blind'], $s['starting_stack'], $s['game_mode'], $s['allow_rebuys'], $s['turn_seconds'], 10, $s['max_seats']);
        $game->buy_in = $s['buy_in']; $game->min_buyin = $s['min_buyin']; $game->max_buyin = $s['max_buyin'];
        $meta = self::defaultMeta();
        $meta['payout'] = $s['payout'];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 10; $try++) {
            $code = '';
            for ($i = 0; $i < 5; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            try {
                $st = $db->prepare('INSERT INTO rooms (code, name, host_id, state, meta) VALUES (?, ?, ?, ?, ?)');
                $st->execute([$code, $name, $hostId, json_encode($game->toArray()), json_encode($meta)]);
                return $code;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            }
        }
        throw new RuntimeException('Could not allocate room code');
    }

    public function touch(): void { $this->dirty = true; }

    public function save(): void {
        if (!$this->dirty) return;
        $this->version++;
        $st = $this->db->prepare('UPDATE rooms SET state = ?, meta = ?, version = ? WHERE id = ?');
        $st->execute([json_encode($this->game->toArray()), json_encode($this->meta), $this->version, $this->id]);
        $this->dirty = false;
    }

    // ---- automatic hand start ----
    const START_DELAY_MS = 4000;      // first hand / after someone sits
    const NEXT_HAND_DELAY_MS = 7000;  // after a showdown so players can see the result

    public function eligibleCount(): int { return count($this->game->eligibleForHand()); }

    public function startHand(): array {
        if ($this->isTournament() && $this->meta['finished']) return [false, 'The tournament is over'];
        [$ok, $err] = $this->game->startHand();
        if ($ok) {
            $dealt = [];
            foreach ($this->game->seatedPlayers() as $p) if ($p->status === 'active') $dealt[] = $p->user_id;
            $this->meta['dealt_in'] = $dealt;
            $this->meta['next_hand_at'] = null;
            if ($this->isTournament() && !$this->meta['started']) {
                $this->meta['started'] = true;
                $this->game->addLogPublic('Tournament started — registration closed. Prize pool: ' . $this->meta['prize_pool']);
            }
            $this->touch();
        }
        $this->armTurnTimer();
        return [$ok, $err];
    }

    // Host-triggered start (tournaments may start early with 2+ players)
    public function hostStart(): array {
        if ($this->isTournament() && !$this->meta['started'] && count($this->game->seatedPlayers()) < 2) return [false, 'Need at least 2 players'];
        return $this->startHand();
    }

    // Called on every request: schedules and fires the next hand
    public function autoStart(): void {
        $g = $this->game;
        $between = in_array($g->phase, ['waiting', 'showdown']);
        $at = $this->meta['next_hand_at'] ?? null;
        $ready = $between && $this->eligibleCount() >= 2 && !$this->meta['finished'];
        if ($ready && $this->isTournament() && !$this->meta['started']) {
            $ready = count($g->seatedPlayers()) >= $g->max_seats; // tournaments auto-start only when full
        }
        if (!$ready) {
            if ($at !== null) { $this->meta['next_hand_at'] = null; $this->touch(); }
            return;
        }
        if ($at === null) {
            $this->meta['next_hand_at'] = now_ms() + ($g->phase === 'showdown' ? self::NEXT_HAND_DELAY_MS : self::START_DELAY_MS);
            $this->touch();
            return;
        }
        if (now_ms() < $at) return;
        if ($this->isTournament()) {
            $this->eliminateBusted();
            if ($this->checkTournamentEnd()) return;
            if ($this->eligibleCount() < 2) { $this->meta['next_hand_at'] = null; $this->touch(); return; }
        }
        $this->startHand();
    }

    // ---- tournament lifecycle ----
    private function eliminateBusted(): void {
        $g = $this->game;
        $busted = [];
        foreach ($g->seatedPlayers() as $i => $p) if ($p->stack <= 0) $busted[$i] = $p;
        if (!$busted) return;
        uasort($busted, fn($a, $b) => $a->committed <=> $b->committed); // smaller stake busts lower
        foreach ($busted as $p) {
            $this->meta['finish_order'][] = $p->user_id;
            $place = count($this->meta['entrants']) - count($this->meta['finish_order']) + 1;
            $g->addLogPublic("{$p->name} is eliminated in place $place");
            $g->leave($p->user_id);
        }
        $this->touch();
    }

    // Ends the tournament when one player with chips remains (after it started). Returns true if finished.
    public function checkTournamentEnd(): bool {
        if (!$this->isTournament() || !$this->meta['started'] || $this->meta['finished']) return false;
        if (!in_array($this->game->phase, ['waiting', 'showdown'])) return false;
        $alive = [];
        foreach ($this->game->seatedPlayers() as $p) if ($p->stack > 0) $alive[] = $p;
        $canRebuy = $this->game->allow_rebuys && count(array_filter($this->game->seatedPlayers(), fn($p) => $p->stack <= 0)) > 0;
        if (count($alive) > 1 || $canRebuy) return false;
        // final standings: winner first, then reverse elimination order
        $standing = $alive ? [$alive[0]->user_id] : [];
        foreach (array_reverse($this->meta['finish_order']) as $uid) if (!in_array($uid, $standing)) $standing[] = $uid;
        $pool = (int)$this->meta['prize_pool'];
        $n = count($this->meta['entrants']);
        $shares = $this->meta['payout'] === 'top3' ? ($n >= 6 ? [0.5, 0.3, 0.2] : ($n >= 3 ? [0.65, 0.35] : [1.0])) : [1.0];
        $results = []; $paid = 0;
        foreach ($shares as $i => $share) {
            if (!isset($standing[$i])) break;
            $amt = $i === count($shares) - 1 ? $pool - $paid : (int)floor($pool * $share);
            $paid += $amt;
            $this->creditWallet($standing[$i], $amt);
            $st = $this->db->prepare('SELECT name FROM users WHERE id = ?'); $st->execute([$standing[$i]]);
            $results[] = ['user_id' => $standing[$i], 'name' => (string)$st->fetchColumn(), 'place' => $i + 1, 'amount' => $amt];
            $this->game->addLogPublic("{$results[$i]['name']} finishes #" . ($i + 1) . " and wins $amt chips");
        }
        $this->meta['finished'] = true;
        $this->meta['results'] = $results;
        $this->meta['next_hand_at'] = null;
        $this->touch();
        return true;
    }

    // ---- turn timer ----
    public function armTurnTimer(): void {
        $g = $this->game;
        if ($g->to_act === null || in_array($g->phase, ['waiting', 'showdown'])) {
            if ($this->meta['turn_deadline'] !== null || $this->meta['turn_seat'] !== null) $this->touch();
            $this->meta['turn_seat'] = null; $this->meta['turn_deadline'] = null;
            return;
        }
        if ($g->to_act === $this->meta['turn_seat']) return;
        $this->meta['turn_seat'] = $g->to_act;
        $p = $g->seats[$g->to_act];
        $instant = $p && $p->away && $this->isTournament(); // away tournament players are auto-folded at once
        $this->meta['turn_deadline'] = now_ms() + ($instant ? 0 : $g->turn_seconds * 1000);
        $this->touch();
    }

    // Server-enforced auto check/fold once the deadline has passed (called on every poll)
    public function applyTimeouts(): void {
        $g = $this->game;
        $deadline = $this->meta['turn_deadline'];
        if ($deadline === null || $g->to_act === null || in_array($g->phase, ['waiting', 'showdown'])) return;
        if (now_ms() < $deadline + 500) return;
        $seat = $g->to_act;
        $p = $g->seats[$seat];
        if ($p === null) { $this->armTurnTimer(); return; }
        $legal = $g->legalActions($seat);
        if (!$legal) return;
        $action = !empty($legal['check']) ? 'check' : 'fold';
        $g->act($p->user_id, $action);
        $g->addLogPublic("{$p->name} auto-{$action}s (timed out)");
        $g->markTimeout($seat);
        $this->touch();
        $this->recordHandStats();
        $this->armTurnTimer();
    }

    // Remove seated players who stopped polling (equivalent of a WebSocket disconnect)
    public function removeStalePlayers(int $timeoutSec): void {
        $st = $this->db->prepare('SELECT user_id FROM room_members WHERE room_id = ? AND last_seen >= ?');
        $st->execute([$this->id, time() - $timeoutSec]);
        $alive = array_map('intval', array_column($st->fetchAll(), 'user_id'));
        foreach ($this->game->seatedPlayers() as $p) {
            if (!in_array($p->user_id, $alive)) $this->leavePlayer($p->user_id);
        }
        $this->db->prepare('DELETE FROM room_members WHERE room_id = ? AND last_seen < ?')->execute([$this->id, time() - $timeoutSec]);
    }

    // ---- stats / history (runs once per finished hand) ----
    public function recordHandStats(): void {
        $g = $this->game;
        if ($g->phase !== 'showdown' || $this->meta['recorded_hand'] === $g->hand_no) return;
        $this->meta['recorded_hand'] = $g->hand_no;
        $this->touch();
        $winners = [];
        foreach ($g->last_winners as $w) $winners[(int)$w['user_id']] = $w;
        $shown = [];
        $reveal = $g->showdown_reveal; sort($reveal);
        foreach ($reveal as $i) if ($g->seats[$i] !== null) $shown[] = ['name' => $g->seats[$i]->name, 'hole' => $g->seats[$i]->hole];
        $this->meta['history'][] = [
            'hand_no' => $g->hand_no, 'community' => $g->community, 'winners' => $g->last_winners,
            'shown' => $shown, 'small_blind' => $g->small_blind, 'big_blind' => $g->big_blind,
        ];
        if (count($this->meta['history']) > 25) $this->meta['history'] = array_slice($this->meta['history'], -25);

        $rank = array_flip(CATEGORY_NAMES);
        foreach ($this->meta['dealt_in'] as $uid) {
            $uid = (int)$uid;
            $st = $this->db->prepare('SELECT best_hand, biggest_pot FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            if (!$u) continue;
            $won = isset($winners[$uid]);
            $handName = $g->showdown_results[(string)$uid] ?? ($won ? $winners[$uid]['hand'] : null);
            $sets = ['hands_played = hands_played + 1'];
            $params = [];
            if ($won) {
                $sets[] = 'hands_won = hands_won + 1';
                $sets[] = 'total_winnings = total_winnings + ?';
                $params[] = (int)$winners[$uid]['amount'];
                if ((int)$winners[$uid]['amount'] > (int)$u['biggest_pot']) { $sets[] = 'biggest_pot = ?'; $params[] = (int)$winners[$uid]['amount']; }
            }
            if ($handName && ($rank[$handName] ?? -1) > ($rank[$u['best_hand']] ?? -1)) { $sets[] = 'best_hand = ?'; $params[] = $handName; }
            $params[] = $uid;
            $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
    }

    // ---- presence / peers ----
    public function heartbeat(int $uid, string $name, ?string $avatar): void {
        $st = $this->db->prepare('INSERT INTO room_members (room_id, user_id, name, avatar, last_seen) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), name = VALUES(name)');
        $st->execute([$this->id, $uid, $name, $avatar, time()]);
    }

    public function peers(int $timeoutSec): array {
        $st = $this->db->prepare('SELECT user_id, name, avatar FROM room_members WHERE room_id = ? AND last_seen >= ? ORDER BY user_id');
        $st->execute([$this->id, time() - $timeoutSec]);
        return array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'name' => $r['name'], 'avatar' => $r['avatar']], $st->fetchAll());
    }

    public function removeMember(int $uid): void {
        $this->db->prepare('DELETE FROM room_members WHERE room_id = ? AND user_id = ?')->execute([$this->id, $uid]);
        $this->db->prepare('DELETE FROM signals WHERE room_id = ? AND (to_user = ? OR from_user = ?)')->execute([$this->id, $uid, $uid]);
    }

    // ---- events (chat, reactions) ----
    public function addEvent(int $uid, string $name, string $type, string $payload): void {
        $st = $this->db->prepare('INSERT INTO room_events (room_id, user_id, name, type, payload, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$this->id, $uid, $name, $type, $payload, time()]);
    }

    public function eventsSince(int $cursor): array {
        if ($cursor < 0) {
            $st = $this->db->prepare('SELECT COALESCE(MAX(id), 0) AS m FROM room_events WHERE room_id = ?');
            $st->execute([$this->id]);
            return [[], (int)$st->fetchColumn()];
        }
        $st = $this->db->prepare('SELECT id, user_id, name, type, payload FROM room_events WHERE room_id = ? AND id > ? ORDER BY id LIMIT 50');
        $st->execute([$this->id, $cursor]);
        $rows = $st->fetchAll();
        $last = $rows ? (int)end($rows)['id'] : $cursor;
        $events = array_map(fn($r) => ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'name' => $r['name'], 'type' => $r['type'], 'payload' => $r['payload']], $rows);
        return [$events, $last];
    }

    // ---- WebRTC signaling relay ----
    public function addSignal(int $from, int $to, string $type, $data): void {
        $st = $this->db->prepare('INSERT INTO signals (room_id, from_user, to_user, type, payload, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$this->id, $from, $to, $type, json_encode($data), time()]);
    }

    public function takeSignals(int $uid): array {
        $st = $this->db->prepare('SELECT id, from_user, type, payload FROM signals WHERE room_id = ? AND to_user = ? ORDER BY id LIMIT 100');
        $st->execute([$this->id, $uid]);
        $rows = $st->fetchAll();
        if ($rows) {
            $ids = array_column($rows, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare("DELETE FROM signals WHERE id IN ($in)")->execute($ids);
        }
        return array_map(fn($r) => ['type' => $r['type'], 'from' => (int)$r['from_user'], 'data' => json_decode($r['payload'], true)], $rows);
    }

    public function stateFor(int $uid): array {
        $state = $this->game->stateFor($uid);
        $state['turn_deadline'] = $this->meta['turn_deadline'];
        $state['next_hand_at'] = $this->meta['next_hand_at'] ?? null;
        $state['tournament'] = $this->isTournament() ? [
            'started' => (bool)$this->meta['started'], 'finished' => (bool)$this->meta['finished'],
            'prize_pool' => (int)$this->meta['prize_pool'], 'payout' => $this->meta['payout'],
            'entrants' => count($this->meta['entrants']), 'remaining' => count(array_filter($this->game->seatedPlayers(), fn($p) => $p->stack > 0)),
            'results' => $this->meta['results'],
        ] : null;
        $state['history'] = array_reverse(array_slice($this->meta['history'], -15));
        return $state;
    }
}
