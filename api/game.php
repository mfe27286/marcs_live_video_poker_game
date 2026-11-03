<?php
// Game endpoint: polling + all table actions. Every call locks the room row so state changes are serialized.
require_once __DIR__ . '/../lib/bootstrap.php';
$user = require_user();
$uid = (int)$user['id'];
$uname = $user['name'];
$avatar = $user['avatar'];
$action = $_GET['action'] ?? 'poll';
$code = strtoupper(trim($_GET['code'] ?? ''));
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_input() : $_GET;
$presenceTimeout = (int)($CONFIG['presence_timeout'] ?? 20);

$db = db();
$db->beginTransaction();
$room = Room::open($db, $code, true);
if (!$room) { $db->rollBack(); json_error('Room not found', 404); }
$game = $room->game;
$error = null;

// housekeeping that would normally run in background tasks
if ($action !== 'leave') $room->heartbeat($uid, $uname, $avatar);
$room->removeStalePlayers($presenceTimeout);
$room->applyTimeouts();
$room->autoStart();

// away auto-return: any deliberate action brings the player back
if (in_array($action, ['act', 'sit', 'rebuy', 'reaction', 'chat', 'start'])) {
    $s = $game->findSeat($uid);
    if ($s !== null && $game->seats[$s]->away) { $game->setAway($uid, false); $room->touch(); }
}

switch ($action) {
    case 'poll':
        break;

    case 'sit':
        [$ok, $err] = $room->sitPlayer($uid, $uname, $avatar, (int)($in['seat'] ?? -1), (int)($in['amount'] ?? 0));
        if (!$ok) $error = $err;
        break;

    case 'leave':
        $room->leavePlayer($uid);
        $room->removeMember($uid);
        break;

    case 'rebuy':
        [$ok, $err] = $room->addChips($uid, (int)($in['amount'] ?? 0));
        if (!$ok) $error = $err;
        break;

    case 'start':
        if ($uid !== $room->host_id) { $error = 'Only the host can start'; break; }
        if (!in_array($game->phase, ['waiting', 'showdown'])) break;
        [$ok, $err] = $room->hostStart();
        if (!$ok) $error = $err;
        break;

    case 'act':
        [$ok, $err] = $game->act($uid, (string)($in['action_type'] ?? ''), $in['amount'] ?? 0);
        if (!$ok) $error = $err; else { $game->clearTimeouts($uid); $room->touch(); }
        $room->recordHandStats();
        $room->armTurnTimer();
        break;

    case 'return':
        $game->setAway($uid, false);
        $room->touch();
        break;

    case 'reaction':
        $room->addEvent($uid, $uname, 'reaction', mb_substr((string)($in['emoji'] ?? ''), 0, 8));
        break;

    case 'chat':
        $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 200));
        if ($text !== '') $room->addEvent($uid, $uname, 'chat', $text);
        break;

    case 'signal':
        $type = (string)($in['type'] ?? '');
        $to = (int)($in['to'] ?? 0);
        if (in_array($type, ['rtc_offer', 'rtc_answer', 'rtc_ice']) && $to > 0) $room->addSignal($uid, $to, $type, $in['data'] ?? null);
        break;

    default:
        $db->rollBack();
        json_error('Unknown action', 404);
}

$room->save();
$db->commit();

if ($error) json_error($error);

// ---- build poll response ----
$clientVersion = (int)($in['v'] ?? $_GET['v'] ?? 0);
$eventCursor = isset($_GET['ev']) ? (int)$_GET['ev'] : (isset($in['ev']) ? (int)$in['ev'] : -1);
[$events, $newCursor] = $room->eventsSince($eventCursor);

json_out([
    'ok' => true,
    'version' => $room->version,
    'state' => $room->version !== $clientVersion ? $room->stateFor($uid) : null,
    'host_id' => $room->host_id,
    'room_name' => $room->name,
    'peers' => $room->peers($presenceTimeout),
    'events' => $events,
    'event_cursor' => $newCursor,
    'signals' => $room->takeSignals($uid),
    'server_now' => now_ms(),
    'wallet' => $room->wallet($uid),
]);
