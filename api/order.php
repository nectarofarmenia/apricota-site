<?php
/* Apricota: receives orders, questionnaires and sign-ups from the site.
   Saves them outside the web folder and emails them to the owner. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($ok, $code = 200) { http_response_code($code); echo json_encode(['ok' => $ok]); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(false, 405);
$raw = file_get_contents('php://input', false, null, 0, 65536);
$j = json_decode($raw ?: '', true);
$kinds = ['order' => 'New order', 'quiz' => 'New questionnaire', 'signup' => 'New sign-up'];
if (!is_array($j) || !isset($kinds[$j['kind'] ?? '']) || !is_array($j['data'] ?? null)) out(false, 400);
if (!empty($j['hp'])) out(true); // bot trap

$dir = dirname(__DIR__, 2) . '/apricota-data';   // outside public_html
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) out(false, 500);

/* simple rate limit: 40 requests per hour per visitor */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rl = $dir . '/rl_' . md5($ip . date('YmdH')) . '.txt';
$n = is_file($rl) ? (int)file_get_contents($rl) : 0;
if ($n >= 40) out(false, 429);
@file_put_contents($rl, (string)($n + 1), LOCK_EX);
foreach (glob($dir . '/rl_*.txt') ?: [] as $f) { if (filemtime($f) < time() - 7200) @unlink($f); }

$cfg = ['to' => 'nectarofarmenia@gmail.com', 'from' => 'noreply@apricota.am'];
if (is_file($dir . '/config.php')) { $c = include $dir . '/config.php'; if (is_array($c)) $cfg = array_merge($cfg, $c); }

$kind = $j['kind'];
$rec = ['kind' => $kind, 'at' => date('c'), 'ip' => $ip,
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200), 'data' => $j['data']];
if (file_put_contents($dir . '/' . ['order' => 'orders', 'quiz' => 'questionnaires', 'signup' => 'signups'][$kind] . '.jsonl',
        json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) out(false, 500);

/* email */
function flat($v, $p = '', &$lines = []) {
  if (count($lines) > 300) return $lines;
  if (is_array($v)) { foreach ($v as $k => $x) flat($x, $p === '' ? (string)$k : "$p.$k", $lines); }
  else { $s = is_bool($v) ? ($v ? 'yes' : 'no') : (string)$v;
         if ($s !== '') $lines[] = $p . ': ' . mb_substr(str_replace(["\r", "\n"], ' ', $s), 0, 500); }
  return $lines;
}
$d = $j['data'];
$o = $d['order'] ?? [];
$name = trim((string)($o['name'] ?? $d['name'] ?? (($d['first'] ?? '') . ' ' . ($d['last'] ?? '')))) ?: 'Customer';
$subj = $kinds[$kind] . ' · ' . $name;
if ($kind === 'order') $subj .= ' · ' . ($o['prog'] ?? '') . ' · ' . ($o['total'] ?? '');
$subj = preg_replace('/[\r\n]+/', ' ', mb_substr($subj, 0, 150));
$body = $kinds[$kind] . " on apricota.am\n" . date('Y-m-d H:i') . "\n\n" . implode("\n", flat($d));
$from = preg_replace('/[\r\n]+/', '', $cfg['from']);
$headers = "From: Apricota <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
$reply = filter_var($d['email'] ?? '', FILTER_VALIDATE_EMAIL);
if ($reply) $headers .= "\r\nReply-To: $reply";
@mail($cfg['to'], '=?UTF-8?B?' . base64_encode($subj) . '?=', $body, $headers);
out(true);
