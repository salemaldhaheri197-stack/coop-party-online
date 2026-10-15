<?php
// Coop Party online: tiny room server. Rooms are JSON files in ./rooms (must be writable).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$dir = __DIR__ . '/rooms';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); @file_put_contents("$dir/.htaccess", "Deny from all\n"); }
if (!is_writable($dir)) out(['err' => 'Server cannot write to the rooms folder. Set its permissions to 755 or 777.']);
$raw = file_get_contents('php://input');
if (strlen($raw) > 30000) out(['err' => 'Too large']);
$in = json_decode($raw, true);
if (!is_array($in)) $in = [];
$a = $in['a'] ?? '';
$id = preg_replace('/[^a-f0-9]/', '', (string)($in['id'] ?? ''));

function out($x) { echo json_encode($x); exit; }
function clean_name($s) {
  $s = trim(preg_replace('/[\x00-\x1F<>&"\']/', '', (string)$s));
  $s = function_exists('mb_substr') ? mb_substr($s, 0, 10) : substr($s, 0, 10);
  return $s === '' ? 'Player' : $s;
}
function new_player($name, $cpu) { return ['name' => $name, 'cpu' => $cpu, 'coins' => 10, 'stars' => 0, 'pos' => 0]; }
function you($r, $id) { $i = $id === '' ? false : array_search($id, $r['ids'], true); return $i === false ? -1 : $i; }
function reply($r, $id) { return ['ver' => $r['ver'], 'state' => $r['state'], 'you' => you($r, $id)]; }
// Opens a room under an exclusive lock, runs $fn, saves if it returns true.
function with_room($code, $id, $fn) {
  global $dir;
  $code = strtoupper((string)$code);
  if (!preg_match('/^[A-Z]{4}$/', $code)) out(['err' => 'Room codes are 4 letters.']);
  $f = "$dir/$code.json";
  if (!file_exists($f)) out(['err' => 'Room not found.']);
  $h = fopen($f, 'c+'); flock($h, LOCK_EX);
  $r = json_decode(stream_get_contents($h), true);
  if (!is_array($r)) { flock($h, LOCK_UN); fclose($h); out(['err' => 'Room is damaged.']); }
  $res = $fn($r);
  if (isset($GLOBALS['newid'])) $id = $GLOBALS['newid'];
  if (is_string($res)) { flock($h, LOCK_UN); fclose($h); out(['err' => $res] + reply($r, $id)); }
  if ($res === true) { $r['ver']++; ftruncate($h, 0); rewind($h); fwrite($h, json_encode($r)); fflush($h); }
  flock($h, LOCK_UN); fclose($h);
  out(reply($r, $id) + ['code' => $code, 'id' => $id]);
}

if ($a === 'create') {
  foreach (glob("$dir/*.json") ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
  $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  do { $code = ''; for ($i = 0; $i < 4; $i++) $code .= $letters[random_int(0, strlen($letters) - 1)]; } while (file_exists("$dir/$code.json"));
  $id = bin2hex(random_bytes(8));
  $r = ['ver' => 1, 'ids' => [$id], 'state' => [
    'phase' => 'lobby', 'players' => [new_player(clean_name($in['name'] ?? ''), false)],
    'round' => 1, 'rounds' => 8, 'turn' => 0, 'star' => 12, 'die' => 0, 'msg' => '', 'fx' => '', 'mg' => null]];
  file_put_contents("$dir/$code.json", json_encode($r), LOCK_EX);
  out(reply($r, $id) + ['code' => $code, 'id' => $id]);
}
if ($a === 'join') {
  $name = clean_name($in['name'] ?? '');
  with_room($in['room'] ?? '', $id, function (&$r) use ($id, $name) {
    if (you($r, $id) >= 0) return false;                      // already in: rejoin
    if ($r['state']['phase'] !== 'lobby') return 'That game has already started.';
    if (count($r['state']['players']) >= 4) return 'That room is full.';
    $id = bin2hex(random_bytes(8));
    $r['ids'][] = $id; $r['state']['players'][] = new_player($name, false);
    $GLOBALS['newid'] = $id;
    return true;
  });
}
if ($a === 'cpu') {
  with_room($in['room'] ?? '', $id, function (&$r) use ($id) {
    if (you($r, $id) !== 0) return 'Only the host can add CPU players.';
    if ($r['state']['phase'] !== 'lobby') return 'The game has already started.';
    if (count($r['state']['players']) >= 4) return 'The room is full.';
    $names = ['Zuzu', 'Pim', 'Dot', 'Rook'];
    $r['ids'][] = ''; $r['state']['players'][] = new_player($names[count($r['state']['players'])], true);
    return true;
  });
}
if ($a === 'get') {
  $ver = (int)($in['ver'] ?? 0);
  with_room($in['room'] ?? '', $id, function (&$r) use ($ver) {
    if ($r['ver'] === $ver) out(['ver' => $ver]);
    return false;
  });
}
if ($a === 'push') {
  $ver = (int)($in['ver'] ?? 0); $state = $in['state'] ?? null;
  with_room($in['room'] ?? '', $id, function (&$r) use ($id, $ver, $state) {
    if (you($r, $id) < 0) return 'You are not in this room.';
    if ($r['ver'] !== $ver) return 'stale';
    if (!is_array($state) || !isset($state['players']) || count($state['players']) !== count($r['ids'])) return 'Bad state.';
    $r['state'] = $state;
    return true;
  });
}
if ($a === 'score') {
  $idx = (int)($in['idx'] ?? -1); $val = (int)($in['val'] ?? 0);
  with_room($in['room'] ?? '', $id, function (&$r) use ($id, $idx, $val) {
    $me = you($r, $id);
    if ($me < 0 || ($me !== $idx && $me !== 0)) return 'Not allowed.';
    if ($r['state']['phase'] !== 'mini' || $idx < 0 || $idx >= count($r['ids'])) return false;
    if (isset($r['state']['mg']['scores']["p$idx"])) return false;
    if (!is_array($r['state']['mg']['scores'] ?? null)) $r['state']['mg']['scores'] = [];
    $r['state']['mg']['scores']["p$idx"] = $val;
    return true;
  });
}
out(['err' => 'Unknown action.']);
