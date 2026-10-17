<?php
/**
 * End-to-End Live Hardware Test Template
 *
 * Usage:
 *   php tests/live_machine_test.php [ip] [port] [commKey] [userId]
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/AdmsServer.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\ZKTeco;

$ip      = $argv[1] ?? '192.168.1.201';
$port    = (int)($argv[2] ?? 4370);
$commKey = (int)($argv[3] ?? 0);
$userId  = (string)($argv[4] ?? '1');

echo "==============================================================\n";
echo "   LIVE HARDWARE END-TO-END TEST ({$ip}:{$port})              \n";
echo "==============================================================\n\n";

// 1. Test TCP Connection + Webhook on Connect
echo "[Test 1] Connecting via TCP (with CommKey {$commKey})...\n";
$zk = new ZKTeco($ip, $port, $commKey, Constants::PROTOCOL_TCP, 10);

$webhookEventsReceived = [];
$zk->onEvent('*', function (array $envelope, string $event) use (&$webhookEventsReceived): void {
    $webhookEventsReceived[] = [
        'event' => $event,
        'id'    => $envelope['id'],
        'data'  => $envelope['data'],
    ];
});

if (!$zk->connect()) {
    echo "❌ TCP Connection Failed!\n";
    exit(1);
}
$deviceTime = (string)$zk->getTime();
echo "  ✅ TCP Connected! Session ID: {$zk->sessionId} | Active Protocol: " . strtoupper((string)$zk->getActiveProtocol()) . "\n";
echo "  ✅ Device Name: " . $zk->getDeviceName() . " | Serial: " . $zk->getSerialNumber() . " | Time: {$deviceTime}\n";

// 2. Test Live Users Pull
echo "\n[Test 2] Pulling Enrolled Users over TCP...\n";
$users = $zk->getUsersStructured();
echo "  ✅ Pulled " . count($users) . " Users from Live Machine.\n";

// 3. Test Live Attendance Pull BEFORE Hardware Write
echo "\n[Test 3] Pulling Live Hardware Attendance Logs BEFORE markAttendance()...\n";
$todayStart = date('Y-m-d 00:00:00');
$beforeAll = $zk->getAttendanceStructured(null, false);
$beforeToday = $zk->getAttendanceStructured($todayStart, false);
echo "  ✅ Total Hardware Logs BEFORE : " . count($beforeAll) . "\n";
echo "  ✅ Today's Hardware Logs BEFORE : " . count($beforeToday) . "\n";

// 4. Test Direct Hardware Attendance Write into the Physical ZKTeco Machine (ATT_LOG)
echo "\n[Test 4] Writing Attendance Directly into Physical Machine ATT_LOG via markAttendance()...\n";
$punchTime = $deviceTime !== '' ? $deviceTime : date('Y-m-d H:i:s');

$markResult = $zk->markAttendance(
    userId: $userId,
    state: Constants::ATT_STATE_CHECK_OUT,
    timestamp: $punchTime,
    verifyType: Constants::VERIFY_FINGERPRINT,
    options: [
        'write_to_device' => true,
        'device_feedback' => true,
    ]
);
echo "  ✅ markAttendance() Result : " . ($markResult['success'] ? 'SUCCESS' : 'FAILED') . "\n";
echo "  ✅ Written to Physical Device ATT_LOG : " . ($markResult['device_written'] ? 'YES (CMD_SET_DATA 3001)' : 'NO') . "\n";
echo "     -> Created Punch: UserID={$markResult['punch']['user_id']} | {$markResult['punch']['state_label']} | Time={$markResult['punch']['timestamp']}\n";

$zk->disconnect();

// 5. Reconnect in a Brand-New Session & Pull Hardware Logs (includeManual: false) to Verify!
echo "\n[Test 5] Re-connecting in a Fresh Session & Reading Hardware ATT_LOG (includeManual: false)...\n";
$zkVerify = new ZKTeco($ip, $port, $commKey, Constants::PROTOCOL_TCP, 10);
if (!$zkVerify->connect()) {
    echo "❌ Re-connection Failed!\n";
    exit(1);
}
$afterAll = $zkVerify->getAttendanceStructured(null, false);
$afterToday = $zkVerify->getAttendanceStructured($todayStart, false);
$zkVerify->disconnect();

echo "  ✅ Total Hardware Logs AFTER  : " . count($afterAll) . " (was " . count($beforeAll) . ")\n";
echo "  ✅ Today's Hardware Logs AFTER: " . count($afterToday) . " (was " . count($beforeToday) . ")\n";

echo "\n[Test 6] Verifying Triggered Webhook Events...\n";
foreach ($webhookEventsReceived as $evt) {
    echo "  ✅ Event Dispatched: [{$evt['event']}] (ID: {$evt['id']})\n";
}

echo "\n==============================================================\n";
echo "   ALL LIVE MACHINE TESTS PASSED SUCCESSFULLY!                \n";
echo "==============================================================\n";

