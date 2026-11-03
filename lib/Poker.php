<?php
// Server-authoritative Texas Hold'em engine (PHP port). State is serialized to JSON between requests.

const POKER_RANKS = '23456789TJQKA';
const POKER_SUITS = 'shdc';
const CATEGORY_NAMES = [
    8 => 'Straight Flush', 7 => 'Four of a Kind', 6 => 'Full House', 5 => 'Flush',
    4 => 'Straight', 3 => 'Three of a Kind', 2 => 'Two Pair', 1 => 'Pair', 0 => 'High Card',
];

function poker_rank_value(string $r): int { return strpos(POKER_RANKS, $r) + 2; }

function poker_make_deck(): array {
    $deck = [];
    for ($i = 0; $i < 13; $i++) for ($j = 0; $j < 4; $j++) $deck[] = POKER_RANKS[$i] . POKER_SUITS[$j];
    shuffle($deck);
    return $deck;
}

function poker_straight_high(array $values): ?int {
    $vals = array_unique($values);
    if (in_array(14, $vals)) $vals[] = 1;
    rsort($vals);
    $run = 1;
    for ($i = 0; $i < count($vals) - 1; $i++) {
        if ($vals[$i] - 1 == $vals[$i + 1]) {
            $run++;
            if ($run >= 5) return $vals[$i - 3];
        } else $run = 1;
    }
    return null;
}

// Returns [category, kicker1..] tuple
function poker_eval5(array $cards): array {
    $values = array_map(fn($c) => poker_rank_value($c[0]), $cards);
    rsort($values);
    $suits = array_map(fn($c) => $c[1], $cards);
    $isFlush = count(array_unique($suits)) === 1;
    $straightHigh = poker_straight_high($values);
    $counts = array_count_values($values);
    $byCount = [];
    foreach ($counts as $v => $c) $byCount[] = [$c, $v];
    usort($byCount, fn($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);
    $pattern = array_column($byCount, 0);
    $ordered = array_column($byCount, 1);

    if ($isFlush && $straightHigh) return [8, $straightHigh];
    if ($pattern[0] == 4) return [7, $ordered[0], $ordered[1]];
    if ($pattern[0] == 3 && $pattern[1] == 2) return [6, $ordered[0], $ordered[1]];
    if ($isFlush) return array_merge([5], $values);
    if ($straightHigh) return [4, $straightHigh];
    if ($pattern[0] == 3) return array_merge([3], $ordered);
    if ($pattern[0] == 2 && $pattern[1] == 2) return [2, $ordered[0], $ordered[1], $ordered[2]];
    if ($pattern[0] == 2) return array_merge([1], $ordered);
    return array_merge([0], $values);
}

// Encode a tuple to a single comparable integer (category + 5 padded kickers, base 15)
function poker_score_key(array $t): int {
    $key = $t[0];
    for ($i = 1; $i <= 5; $i++) $key = $key * 15 + ($t[$i] ?? 0);
    return $key;
}

function poker_best_hand(array $cards): array {
    $n = count($cards);
    $best = -1;
    $bestCat = 0;
    for ($a = 0; $a < $n; $a++)
    for ($b = $a + 1; $b < $n; $b++)
    for ($c = $b + 1; $c < $n; $c++)
    for ($d = $c + 1; $d < $n; $d++)
    for ($e = $d + 1; $e < $n; $e++) {
        $t = poker_eval5([$cards[$a], $cards[$b], $cards[$c], $cards[$d], $cards[$e]]);
        $k = poker_score_key($t);
        if ($k > $best) { $best = $k; $bestCat = $t[0]; }
    }
    return [$best, CATEGORY_NAMES[$bestCat]];
}

class PokerPlayer {
    public $user_id; public $name; public $avatar; public $stack;
    public $hole = []; public $status = 'waiting'; public $round_bet = 0; public $committed = 0;
    public $acted = false; public $last_action = null; public $away = false; public $consec_timeouts = 0;

    public function __construct($user_id, $name, $stack, $avatar = null) {
        $this->user_id = (int)$user_id; $this->name = $name; $this->stack = (int)$stack; $this->avatar = $avatar;
    }

    public function publicData(bool $reveal): array {
        return [
            'user_id' => $this->user_id, 'name' => $this->name, 'avatar' => $this->avatar,
            'stack' => $this->stack, 'status' => $this->status, 'round_bet' => $this->round_bet,
            'committed' => $this->committed, 'last_action' => $this->last_action, 'away' => $this->away,
            'hole' => $reveal ? $this->hole : ($this->hole ? [null, null] : []),
        ];
    }

    public function toArray(): array { return get_object_vars($this); }

    public static function fromArray(array $a): PokerPlayer {
        $p = new PokerPlayer($a['user_id'], $a['name'], $a['stack'], $a['avatar'] ?? null);
        foreach (['hole', 'status', 'round_bet', 'committed', 'acted', 'last_action', 'away', 'consec_timeouts'] as $k) {
            if (array_key_exists($k, $a)) $p->$k = $a[$k];
        }
        return $p;
    }
}

class PokerGame {
    public $small_blind, $big_blind, $base_small_blind, $base_big_blind, $starting_stack;
    public $game_mode = 'cash'; public $allow_rebuys = true; public $turn_seconds = 20; public $level_hands = 10;
    public $level = 1; public $max_seats = 9;
    public $buy_in = 0; public $min_buyin = 0; public $max_buyin = 0; // buy_in: tournament entry; min/max: cash
    public $seats = []; // max_seats x (PokerPlayer|null)
    public $button = -1; public $phase = 'waiting'; public $community = []; public $deck = [];
    public $pot = 0; public $current_bet = 0; public $min_raise; public $to_act = null;
    public $pending = []; // seat indices
    public $hand_no = 0; public $last_winners = []; public $showdown_reveal = []; public $showdown_results = [];
    public $log = [];

    public function __construct($sb = 10, $bb = 20, $stack = 1000, $mode = 'cash', $rebuys = true, $turn = 20, $levelHands = 10, $maxSeats = 9) {
        $this->small_blind = $this->base_small_blind = (int)$sb;
        $this->big_blind = $this->base_big_blind = (int)$bb;
        $this->starting_stack = (int)$stack; $this->game_mode = $mode; $this->allow_rebuys = (bool)$rebuys;
        $this->turn_seconds = (int)$turn; $this->level_hands = (int)$levelHands; $this->min_raise = (int)$bb;
        $this->max_seats = max(2, min(10, (int)$maxSeats));
        $this->seats = array_fill(0, $this->max_seats, null);
    }

    // ---- (de)serialization ----
    public function toArray(): array {
        $a = get_object_vars($this);
        $a['seats'] = array_map(fn($p) => $p ? $p->toArray() : null, $this->seats);
        $a['pending'] = array_values($this->pending);
        $a['showdown_reveal'] = array_values($this->showdown_reveal);
        $a['showdown_results'] = (object)$this->showdown_results;
        return $a;
    }

    public static function fromArray(array $a): PokerGame {
        $g = new PokerGame($a['small_blind'], $a['big_blind'], $a['starting_stack'], $a['game_mode'], $a['allow_rebuys'], $a['turn_seconds'], $a['level_hands'], $a['max_seats'] ?? 9);
        foreach ($a as $k => $v) {
            if ($k === 'seats') $g->seats = array_map(fn($p) => $p ? PokerPlayer::fromArray($p) : null, $v);
            elseif ($k === 'showdown_results') $g->showdown_results = (array)$v;
            elseif (property_exists($g, $k)) $g->$k = $v;
        }
        return $g;
    }

    // ---- helpers ----
    public function seatedPlayers(): array {
        $out = [];
        foreach ($this->seats as $i => $p) if ($p !== null) $out[$i] = $p;
        return $out;
    }

    public function findSeat($user_id): ?int {
        foreach ($this->seats as $i => $p) if ($p && $p->user_id == $user_id) return $i;
        return null;
    }

    private function addLog(string $msg): void {
        $this->log[] = $msg;
        if (count($this->log) > 40) $this->log = array_slice($this->log, -40);
    }

    public function addLogPublic(string $msg): void { $this->addLog($msg); }

    private function pendingRemove(int $seat): void {
        $this->pending = array_values(array_filter($this->pending, fn($s) => $s !== $seat));
    }

    private function pendingAdd(int $seat): void { if (!in_array($seat, $this->pending)) $this->pending[] = $seat; }

    // ---- seating / lifecycle ----
    public function sit(int $seat, $user_id, string $name, $avatar = null, ?int $stack = null): array {
        if ($seat < 0 || $seat >= $this->max_seats) return [false, 'Invalid seat'];
        if ($this->seats[$seat] !== null) return [false, 'Seat taken'];
        if ($this->findSeat($user_id) !== null) return [false, 'Already seated'];
        $this->seats[$seat] = new PokerPlayer($user_id, $name, $stack ?? $this->starting_stack, $avatar);
        $this->addLog("$name took seat " . ($seat + 1));
        return [true, null];
    }

    public function leave($user_id): void {
        $seat = $this->findSeat($user_id);
        if ($seat === null) return;
        $name = $this->seats[$seat]->name;
        $inHand = !in_array($this->phase, ['waiting', 'showdown']);
        if ($inHand && in_array($this->seats[$seat]->status, ['active', 'allin'])) $this->seats[$seat]->status = 'folded';
        $this->seats[$seat] = null;
        $this->pendingRemove($seat);
        $this->addLog("$name left the table");
        if ($inHand) $this->checkRoundProgress();
    }

    // Adds chips to a seated player's stack between hands (money handling is done by Room)
    public function addChips($user_id, int $amount): array {
        if (!in_array($this->phase, ['waiting', 'showdown'])) return [false, 'Wait until the current hand ends'];
        $seat = $this->findSeat($user_id);
        if ($seat === null) return [false, 'You are not seated'];
        $p = $this->seats[$seat];
        $p->stack += $amount;
        $this->addLog($this->game_mode === 'tournament' ? "{$p->name} rebought for {$this->starting_stack}" : "{$p->name} added $amount chips");
        return [true, null];
    }

    public function markTimeout(int $seat): bool {
        $p = $this->seats[$seat];
        if ($p === null) return false;
        $p->consec_timeouts++;
        if ($p->consec_timeouts >= 2 && !$p->away) {
            $p->away = true;
            $this->addLog("{$p->name} is now away (idle)");
            return true;
        }
        return false;
    }

    public function clearTimeouts($user_id): void {
        $seat = $this->findSeat($user_id);
        if ($seat !== null) $this->seats[$seat]->consec_timeouts = 0;
    }

    public function setAway($user_id, bool $away): void {
        $seat = $this->findSeat($user_id);
        if ($seat === null) return;
        $p = $this->seats[$seat];
        $p->away = $away;
        if (!$away) { $p->consec_timeouts = 0; $this->addLog("{$p->name} is back"); }
    }

    // Tournament players are dealt in even when away (they get blinded off / auto-folded)
    public function eligibleForHand(): array {
        $out = [];
        foreach ($this->seatedPlayers() as $i => $p) if ($p->stack > 0 && (!$p->away || $this->game_mode === 'tournament')) $out[] = $i;
        return $out;
    }

    public function startHand(): array {
        $players = $this->eligibleForHand();
        if (count($players) < 2) return [false, 'Need at least 2 players with chips'];
        $this->hand_no++;
        if ($this->game_mode === 'tournament') {
            $newLevel = intdiv($this->hand_no - 1, $this->level_hands) + 1;
            if ($newLevel !== $this->level) {
                $this->level = $newLevel;
                $mult = 2 ** ($this->level - 1);
                $this->small_blind = $this->base_small_blind * $mult;
                $this->big_blind = $this->base_big_blind * $mult;
                $this->addLog("Blinds up! Level {$this->level}: {$this->small_blind}/{$this->big_blind}");
            }
        }
        $this->deck = poker_make_deck();
        $this->community = []; $this->pot = 0; $this->current_bet = 0; $this->min_raise = $this->big_blind;
        $this->last_winners = []; $this->showdown_reveal = []; $this->showdown_results = [];
        $this->phase = 'preflop';
        $this->button = $this->nextOccupied($this->button, $players);

        foreach ($this->seatedPlayers() as $p) {
            $p->hole = []; $p->round_bet = 0; $p->committed = 0; $p->acted = false; $p->last_action = null;
            $p->status = ($p->stack > 0 && (!$p->away || $this->game_mode === 'tournament')) ? 'active' : 'sitting_out';
        }
        $active = $this->actableSeats();
        if (count($active) === 2) {
            $sb = $this->button; $bb = $this->nextOccupied($this->button, $active);
        } else {
            $sb = $this->nextOccupied($this->button, $active); $bb = $this->nextOccupied($sb, $active);
        }
        $this->postBlind($sb, $this->small_blind);
        $this->postBlind($bb, $this->big_blind);
        $this->current_bet = $this->big_blind; $this->min_raise = $this->big_blind;
        for ($k = 0; $k < 2; $k++) foreach ($active as $i) $this->seats[$i]->hole[] = array_pop($this->deck);
        $first = count($active) === 2 ? $sb : $this->nextOccupied($bb, $active);
        $this->pending = array_values(array_filter($active, fn($i) => $this->seats[$i]->status === 'active'));
        $this->to_act = $first;
        $this->addLog("Hand #{$this->hand_no} started");
        return [true, null];
    }

    private function postBlind(int $seat, int $amount): void {
        $p = $this->seats[$seat];
        $pay = min($amount, $p->stack);
        $p->stack -= $pay; $p->round_bet += $pay; $p->committed += $pay; $this->pot += $pay;
        if ($p->stack === 0) $p->status = 'allin';
    }

    private function nextOccupied(int $from, array $seatList): int {
        $n = $this->max_seats;
        for ($step = 1; $step <= $n; $step++) {
            $idx = ($from + $step) % $n;
            if (in_array($idx, $seatList)) return $idx;
        }
        return $seatList[0];
    }

    private function actableSeats(): array {
        $out = [];
        foreach ($this->seatedPlayers() as $i => $p) if ($p->status === 'active') $out[] = $i;
        return $out;
    }

    private function advanceToAct(): void {
        if (!$this->pending) { $this->to_act = null; return; }
        $start = $this->to_act ?? $this->button;
        $n = $this->max_seats;
        for ($step = 1; $step <= $n; $step++) {
            $idx = ($start + $step) % $n;
            if (in_array($idx, $this->pending)) { $this->to_act = $idx; return; }
        }
        $this->to_act = null;
    }

    public function legalActions(?int $seat): ?array {
        if ($seat === null) return null;
        $p = $this->seats[$seat];
        if ($p === null || $this->to_act !== $seat || $p->status !== 'active') return null;
        $toCall = $this->current_bet - $p->round_bet;
        $actions = ['fold' => true];
        if ($toCall <= 0) $actions['check'] = true; else $actions['call'] = min($toCall, $p->stack);
        $maxTotal = $p->round_bet + $p->stack;
        $minTotal = $this->current_bet + $this->min_raise;
        if ($p->stack > $toCall) $actions['raise'] = ['min' => min($minTotal, $maxTotal), 'max' => $maxTotal];
        $actions['to_call'] = max(0, $toCall);
        return $actions;
    }

    public function act($user_id, string $action, $amount = 0): array {
        $seat = $this->findSeat($user_id);
        if ($seat === null || $this->to_act !== $seat) return [false, 'Not your turn'];
        $p = $this->seats[$seat];
        if ($p->status !== 'active') return [false, 'Cannot act'];
        $toCall = $this->current_bet - $p->round_bet;

        if ($action === 'fold') {
            $p->status = 'folded'; $p->last_action = 'Fold'; $this->addLog("{$p->name} folds");
        } elseif ($action === 'check') {
            if ($toCall > 0) return [false, 'Cannot check'];
            $p->last_action = 'Check'; $this->addLog("{$p->name} checks");
        } elseif ($action === 'call') {
            $pay = min($toCall, $p->stack);
            $this->commit($p, $pay);
            $p->last_action = $p->status === 'allin' ? 'All-in' : 'Call';
            $this->addLog("{$p->name} calls $pay");
        } elseif ($action === 'raise') {
            $total = (int)$amount;
            $maxTotal = $p->round_bet + $p->stack;
            $minTotal = $this->current_bet + $this->min_raise;
            if ($total >= $maxTotal) $total = $maxTotal;
            elseif ($total < $minTotal) return [false, "Raise must be at least $minTotal"];
            $inc = $total - $this->current_bet;
            $this->commit($p, $total - $p->round_bet);
            if ($inc >= $this->min_raise) $this->min_raise = $inc;
            $this->current_bet = max($this->current_bet, $p->round_bet);
            foreach ($this->actableSeats() as $i) if ($i !== $seat) $this->pendingAdd($i);
            $p->last_action = $p->status === 'allin' ? 'All-in' : "Raise $total";
            $this->addLog("{$p->name} " . ($p->status === 'allin' ? 'is all-in' : "raises to $total"));
        } else return [false, 'Unknown action'];

        $p->acted = true;
        $this->pendingRemove($seat);
        $this->checkRoundProgress();
        return [true, null];
    }

    private function commit(PokerPlayer $p, int $pay): void {
        $pay = min($pay, $p->stack);
        $p->stack -= $pay; $p->round_bet += $pay; $p->committed += $pay; $this->pot += $pay;
        if ($p->stack === 0) $p->status = 'allin';
    }

    private function checkRoundProgress(): void {
        $notFolded = 0;
        foreach ($this->seatedPlayers() as $p) if (in_array($p->status, ['active', 'allin'])) $notFolded++;
        if ($notFolded <= 1) { $this->endHandNoShowdown(); return; }
        $canAct = $this->actableSeats();
        if (!$this->pending || !$canAct) {
            if (count($canAct) <= 1 && $this->allMatched()) $this->advanceStreet(true);
            else $this->advanceStreet(false);
        } else $this->advanceToAct();
    }

    private function allMatched(): bool {
        foreach ($this->seatedPlayers() as $p) if ($p->status === 'active' && $p->round_bet != $this->current_bet) return false;
        return true;
    }

    private function advanceStreet(bool $runOut): void {
        foreach ($this->seatedPlayers() as $p) { $p->round_bet = 0; $p->acted = false; }
        $this->current_bet = 0; $this->min_raise = $this->big_blind;
        $canAct = $this->actableSeats();

        if ($this->phase === 'preflop') { for ($i = 0; $i < 3; $i++) $this->community[] = array_pop($this->deck); $this->phase = 'flop'; }
        elseif ($this->phase === 'flop') { $this->community[] = array_pop($this->deck); $this->phase = 'turn'; }
        elseif ($this->phase === 'turn') { $this->community[] = array_pop($this->deck); $this->phase = 'river'; }
        elseif ($this->phase === 'river') { $this->showdown(); return; }

        if ($runOut || count($canAct) <= 1) {
            $this->pending = []; $this->to_act = null;
            $this->advanceStreet(true);
            return;
        }
        $this->pending = $canAct;
        $this->to_act = $this->nextOccupied($this->button, $canAct);
    }

    private function buildPots(): array {
        $contribs = []; $notFolded = [];
        foreach ($this->seatedPlayers() as $i => $p) {
            if ($p->committed > 0) $contribs[$i] = $p->committed;
            if (in_array($p->status, ['active', 'allin'])) $notFolded[] = $i;
        }
        $pots = [];
        while (count(array_filter($contribs, fn($v) => $v > 0)) > 0) {
            $m = min(array_filter($contribs, fn($v) => $v > 0));
            $amount = 0; $eligible = [];
            foreach ($contribs as $i => $v) {
                if ($v > 0) {
                    $amount += $m; $contribs[$i] -= $m;
                    if (in_array($i, $notFolded)) $eligible[] = $i;
                }
            }
            $pots[] = [$amount, $eligible];
        }
        return $pots;
    }

    private function showdown(): void {
        $this->phase = 'showdown';
        $notFolded = [];
        foreach ($this->seatedPlayers() as $i => $p) if (in_array($p->status, ['active', 'allin'])) $notFolded[] = $i;
        $this->showdown_reveal = $notFolded;
        $pots = $this->buildPots();
        $scores = []; $names = [];
        foreach ($notFolded as $i) {
            [$score, $name] = poker_best_hand(array_merge($this->seats[$i]->hole, $this->community));
            $scores[$i] = $score; $names[$i] = $name;
            $this->showdown_results[(string)$this->seats[$i]->user_id] = $name;
        }
        $summary = [];
        foreach ($pots as [$amount, $eligible]) {
            if (!$eligible) continue;
            $best = max(array_map(fn($i) => $scores[$i], $eligible));
            $winners = array_values(array_filter($eligible, fn($i) => $scores[$i] === $best));
            $share = intdiv($amount, count($winners));
            $rem = $amount - $share * count($winners);
            foreach ($winners as $j => $w) {
                $add = $share + ($j < $rem ? 1 : 0);
                $this->seats[$w]->stack += $add;
                $summary[$w] = ($summary[$w] ?? 0) + $add;
            }
        }
        $this->last_winners = [];
        foreach ($summary as $i => $amt) {
            $this->last_winners[] = ['user_id' => $this->seats[$i]->user_id, 'name' => $this->seats[$i]->name, 'amount' => $amt, 'hand' => $names[$i]];
            $this->addLog("{$this->seats[$i]->name} wins $amt with {$names[$i]}");
        }
        $this->to_act = null; $this->pending = [];
    }

    private function endHandNoShowdown(): void {
        $this->phase = 'showdown';
        $winner = null;
        foreach ($this->seatedPlayers() as $i => $p) if (in_array($p->status, ['active', 'allin'])) { $winner = $i; break; }
        if ($winner !== null) {
            $amt = $this->pot;
            $this->seats[$winner]->stack += $amt;
            $this->last_winners = [['user_id' => $this->seats[$winner]->user_id, 'name' => $this->seats[$winner]->name, 'amount' => $amt, 'hand' => null]];
            $this->addLog("{$this->seats[$winner]->name} wins $amt");
        }
        $this->to_act = null; $this->pending = [];
    }

    public function stateFor($user_id): array {
        $seat = $this->findSeat($user_id);
        $players = [];
        foreach ($this->seatedPlayers() as $i => $p) {
            $reveal = in_array($i, $this->showdown_reveal) || $p->user_id == $user_id;
            $pub = $p->publicData($reveal);
            $pub['seat'] = $i; $pub['is_button'] = $i === $this->button; $pub['is_turn'] = $i === $this->to_act;
            $players[] = $pub;
        }
        return [
            'phase' => $this->phase, 'community' => $this->community, 'pot' => $this->pot,
            'current_bet' => $this->current_bet, 'min_raise' => $this->min_raise,
            'big_blind' => $this->big_blind, 'small_blind' => $this->small_blind, 'button' => $this->button,
            'to_act' => $this->to_act, 'hand_no' => $this->hand_no, 'players' => $players, 'your_seat' => $seat,
            'legal_actions' => $this->legalActions($seat), 'last_winners' => $this->last_winners,
            'log' => array_slice($this->log, -12), 'game_mode' => $this->game_mode, 'allow_rebuys' => $this->allow_rebuys,
            'turn_seconds' => $this->turn_seconds, 'level' => $this->level, 'starting_stack' => $this->starting_stack,
            'max_seats' => $this->max_seats, 'buy_in' => $this->buy_in, 'min_buyin' => $this->min_buyin, 'max_buyin' => $this->max_buyin,
            'can_rebuy' => $seat !== null && in_array($this->phase, ['waiting', 'showdown']) && (
                $this->game_mode === 'tournament' ? ($this->allow_rebuys && $this->seats[$seat]->stack <= 0) : $this->seats[$seat]->stack < $this->max_buyin),
            'blind_schedule' => $this->blindSchedule(),
        ];
    }

    private function blindSchedule(): ?array {
        if ($this->game_mode !== 'tournament') return null;
        $nextMult = 2 ** $this->level;
        return [
            'current_small' => $this->small_blind, 'current_big' => $this->big_blind,
            'next_small' => $this->base_small_blind * $nextMult, 'next_big' => $this->base_big_blind * $nextMult,
            'hands_to_next' => max(0, $this->level * $this->level_hands - $this->hand_no),
            'level_hands' => $this->level_hands, 'level' => $this->level,
        ];
    }
}
