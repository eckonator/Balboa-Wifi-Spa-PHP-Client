<?php
header('Content-Type: application/json; charset=utf-8');

include('SpaClient.php');

const SPA_IP      = '192.168.178.xxx';
define('STATE_FILE', sys_get_temp_dir() . '/spa_state.json');
const PUMP_LABELS = [0 => 'Off', 1 => 'Low', 2 => 'High'];
const TARGET_TTL  = 900;  // seconds — give up retrying a target after 15 minutes

// ── State file ────────────────────────────────────────────────────────────────
// Stores pending targets AND connection-failure tracking for exponential backoff.
// Structure:
//   targets.setTemp  / pump1 / pump2 / light  → {value, until}
//   connect.failCount                          → consecutive failures
//   connect.nextRetryAt                        → unix timestamp, earliest next attempt

$state = [];
if (file_exists(STATE_FILE)) {
    $saved = json_decode(file_get_contents(STATE_FILE), true);
    if (is_array($saved)) {
        $state = $saved;
    }
}

$targets    = $state['targets']  ?? [];
$connectMeta = $state['connect'] ?? ['failCount' => 0, 'nextRetryAt' => 0];
$now        = time();

// ── Drop expired targets ──────────────────────────────────────────────────────
foreach ($targets as $key => $entry) {
    if (isset($entry['until']) && $entry['until'] < $now) {
        unset($targets[$key]);
    }
}

// ── Merge new GET parameters into targets ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['setTemp'])) {
        $v = (float)$_GET['setTemp'];
        if ($v >= 10 && $v <= 37 && ($v * 10 % 5 == 0)) {
            $targets['setTemp'] = ['value' => $v, 'until' => $now + TARGET_TTL];
        }
    }
    if (isset($_GET['setPump1'])) {
        $v = in_array($_GET['setPump1'], ['High', 'Low']) ? $_GET['setPump1'] : 'Off';
        $targets['pump1'] = ['value' => $v, 'until' => $now + TARGET_TTL];
    }
    if (isset($_GET['setPump2'])) {
        $v = in_array($_GET['setPump2'], ['High', 'Low']) ? $_GET['setPump2'] : 'Off';
        $targets['pump2'] = ['value' => $v, 'until' => $now + TARGET_TTL];
    }
    if (isset($_GET['setLight'])) {
        $v = $_GET['setLight'] === 'On' ? 'On' : 'Off';
        $targets['light'] = ['value' => $v, 'until' => $now + TARGET_TTL];
    }
}

// ── Exponential backoff: skip connection attempt if too soon after a failure ──
// Formula mirrors pybalboa: min(2^failCount, 300) seconds — max 5 minutes.
$backoffSeconds = min(pow(2, $connectMeta['failCount']), 300);
$tooSoon = $now < ($connectMeta['nextRetryAt'] ?? 0);

if ($tooSoon) {
    // Return last known offline status without touching the spa
    $state['targets'] = $targets;
    file_put_contents(STATE_FILE, json_encode($state));

    $pending = buildPending($targets, $now);
    echo json_encode([
        'time'           => '12:00',
        'priming'        => 0,
        'temp'           => 0,
        'setTemp'        => 0,
        'heating'        => 0,
        'pump1'          => 0,
        'pump2'          => 0,
        'light'          => 0,
        'faultCode'      => 0,
        'faultMessage'   => 'Spa offline',
        'retryIn'        => ($connectMeta['nextRetryAt'] - $now) . 's',
        'pendingTargets' => $pending,
    ]);
    exit;
}

// ── Connect and read current status ──────────────────────────────────────────
$spaClient   = new SpaClient(SPA_IP);
$status      = $spaClient->getStatusForFhem();
$spaOnline   = $status['faultCode'] !== 0;

if (!$spaOnline) {
    // Connection failed — increase backoff, schedule next retry
    $connectMeta['failCount']   = ($connectMeta['failCount'] ?? 0) + 1;
    // 30s × 2^(n-1): 30s → 60s → 120s → 300s (max 5 min)
    $connectMeta['nextRetryAt'] = $now + min(30 * (1 << ($connectMeta['failCount'] - 1)), 300);
} else {
    // Successful connection — reset backoff
    $connectMeta = ['failCount' => 0, 'nextRetryAt' => 0];

    // ── Apply pending targets that don't match current status ─────────────────
    if (isset($targets['setTemp']) && $status['setTemp'] != $targets['setTemp']['value']) {
        $spaClient->setTemperature($targets['setTemp']['value']);
    }
    if (isset($targets['pump1']) && (PUMP_LABELS[$status['pump1']] ?? 'Off') !== $targets['pump1']['value']) {
        $spaClient->setPump1($targets['pump1']['value']);
    }
    if (isset($targets['pump2']) && (PUMP_LABELS[$status['pump2']] ?? 'Off') !== $targets['pump2']['value']) {
        $spaClient->setPump2($targets['pump2']['value']);
    }
    if (isset($targets['light'])) {
        $currentLight = $status['light'] ? 'On' : 'Off';
        if ($currentLight !== $targets['light']['value']) {
            $spaClient->setLight($targets['light']['value'] === 'On');
        }
    }

    // ── Read final status and clear confirmed targets ─────────────────────────
    $spaClient->readAllMsg();
    $status = $spaClient->getStatusForFhem();

    if (isset($targets['setTemp']) && $status['setTemp'] == $targets['setTemp']['value']) {
        unset($targets['setTemp']);
    }
    if (isset($targets['pump1']) && (PUMP_LABELS[$status['pump1']] ?? 'Off') === $targets['pump1']['value']) {
        unset($targets['pump1']);
    }
    if (isset($targets['pump2']) && (PUMP_LABELS[$status['pump2']] ?? 'Off') === $targets['pump2']['value']) {
        unset($targets['pump2']);
    }
    if (isset($targets['light'])) {
        if (($status['light'] ? 'On' : 'Off') === $targets['light']['value']) {
            unset($targets['light']);
        }
    }
}

// ── Persist state ─────────────────────────────────────────────────────────────
$state = ['targets' => $targets, 'connect' => $connectMeta];
file_put_contents(STATE_FILE, json_encode($state));

$status['pendingTargets'] = buildPending($targets, $now);
echo json_encode($status);

// ── Helpers ───────────────────────────────────────────────────────────────────
function buildPending(array $targets, int $now): ?array
{
    if (empty($targets)) return null;
    $out = [];
    foreach ($targets as $key => $entry) {
        $out[$key] = ['value' => $entry['value'], 'retryFor' => ($entry['until'] - $now) . 's'];
    }
    return $out;
}
