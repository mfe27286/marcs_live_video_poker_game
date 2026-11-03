<?php
require_once __DIR__ . '/../lib/bootstrap.php';
$user = require_user();
$action = $_GET['action'] ?? '';

if ($action === 'create') {
    $in = json_input();
    $mode = ($in['game_mode'] ?? 'cash') === 'tournament' ? 'tournament' : 'cash';
    $minBuy = max(1, (int)($in['min_buyin'] ?? 500));
    $maxBuy = max($minBuy, (int)($in['max_buyin'] ?? 2000));
    $settings = [
        'small_blind' => max(1, (int)($in['small_blind'] ?? 10)),
        'big_blind' => max(2, (int)($in['big_blind'] ?? 20)),
        'starting_stack' => $mode === 'tournament' ? max(100, (int)($in['starting_stack'] ?? 1000)) : $maxBuy,
        'game_mode' => $mode,
        'allow_rebuys' => $mode === 'tournament' ? !empty($in['allow_rebuys']) : true,
        'turn_seconds' => max(5, min(120, (int)($in['turn_seconds'] ?? 20))),
        'max_seats' => max(2, min(10, (int)($in['max_seats'] ?? ($mode === 'tournament' ? 6 : 10)))),
        'buy_in' => $mode === 'tournament' ? max(0, (int)($in['buy_in'] ?? 500)) : 0,
        'min_buyin' => $mode === 'cash' ? $minBuy : 0,
        'max_buyin' => $mode === 'cash' ? $maxBuy : 0,
        'payout' => ($in['payout'] ?? 'winner') === 'top3' ? 'top3' : 'winner',
    ];
    $name = mb_substr(trim($in['name'] ?? '') ?: 'Poker Table', 0, 40);
    $code = Room::create(db(), (int)$user['id'], $name, $settings);
    json_out(['code' => $code, 'name' => $name]);
}

if ($action === 'info') {
    $room = Room::open(db(), $_GET['code'] ?? '');
    if (!$room) json_error('Room not found', 404);
    json_out([
        'code' => $room->code, 'name' => $room->name, 'host_id' => $room->host_id,
        'small_blind' => $room->game->small_blind, 'big_blind' => $room->game->big_blind,
        'player_count' => count($room->game->seatedPlayers()), 'phase' => $room->game->phase,
        'game_mode' => $room->game->game_mode, 'max_seats' => $room->game->max_seats, 'buy_in' => $room->game->buy_in,
        'min_buyin' => $room->game->min_buyin, 'max_buyin' => $room->game->max_buyin,
    ]);
}

json_error('Unknown action', 404);
