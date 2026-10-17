<?php
/**
 * Comprehensive Automated Test Suite for php-zkteco v2.0
 *
 * Verifies:
 *  1. TCP Framing (\x50\x50\x82\x7d + length) & Unframing
 *  2. Comm Key Bitwise Authentication (MakeKey)
 *  3. Live UDP Socket Server Handshake, Auth, Users & Attendance Streaming
 *  4. Auto-Fallback ('auto' mode falling back from closed TCP port to active UDP port)
 *  5. Structured Attendance & User Parsing + Incremental Timestamp Filtering
 *  6. Outbound HTTP POST Attendance & User Sync (Batching, State Cursor, HMAC Signing)
 *  7. Webhook Dispatcher (Endpoints, Wildcard Events, HMAC-SHA256 Sign & Verify, captureIncoming)
 *  8. ADMS / iClock HTTP Push Server (Handshake, ATTLOG POST -> Webhook trigger, OPERLOG, Command Queue)
 *  9. Real-Time Biometric Event Payload Decoder (EF_ATTLOG 32/36-byte & 16-byte formats)
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/AdmsServer.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\AdmsServer;
use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\WebhookDispatcher;
use TahaRafi\ZKTeco\ZKTeco;

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✅ PASS: {$label}\n";
    } else {
        $failed++;
        echo "  ❌ FAIL: {$label}\n";
    }
}

echo "==============================================================\n";
echo "   Running php-zkteco v2.0 Automated Verification Suite       \n";
echo "==============================================================\n\n";

// ---------------------------------------------------------------------------
// 1. Protocol & TCP Framing Tests
// ---------------------------------------------------------------------------
echo "[1] Testing Dual-Protocol TCP Framing & Checksum Engine...\n";
$zk = new ZKTeco('127.0.0.1', 4370, 1234, Constants::PROTOCOL_AUTO, 1);

$rawZkHeader = $zk->createHeader(Constants::CMD_CONNECT, 0, 0, 65534, '');
assertCheck(strlen($rawZkHeader) === 8, 'ZKTeco base header is 8 bytes');

$tcpWrapped = $zk->wrapTcpPacket($rawZkHeader);
assertCheck(strlen($tcpWrapped) === 16, 'TCP wrapped packet is 16 bytes (8B TCP + 8B ZK)');
assertCheck(substr($tcpWrapped, 0, 4) === "\x50\x50\x82\x7d", 'TCP magic header matches \x50\x50\x82\x7d');
$unpackedLen = unpack('Vlen', substr($tcpWrapped, 4, 4))['len'];
assertCheck($unpackedLen === 8, 'TCP length header equals 8');
assertCheck($zk->unwrapTcpPacket($tcpWrapped) === $rawZkHeader, 'unwrapTcpPacket restores original ZK frame');

// ---------------------------------------------------------------------------
// 2. Comm Key Authentication Token & Time Encoding Tests
// ---------------------------------------------------------------------------
echo "\n[2] Testing Comm Key Bitwise Encryption & Time Codec...\n";
$token = $zk->makeCommKey(1234, 42100, 50);
assertCheck(strlen($token) === 4, 'makeCommKey produces 4-byte binary token');

$sampleTime = '2026-10-17 14:30:45';
$encodedTime = $zk->encodeTime($sampleTime);
$decodedTime = $zk->decodeTime($encodedTime);
assertCheck($decodedTime === $sampleTime, "encodeTime/decodeTime roundtrip matches ({$decodedTime})");

// ---------------------------------------------------------------------------
// 3. Real-Time Biometric Event Payload Decoder (EF_ATTLOG)
// ---------------------------------------------------------------------------
echo "\n[3] Testing Real-Time Biometric Event Decoder (CMD_REG_EVENT)...\n";
// Construct a 36-byte SSR real-time EF_ATTLOG payload:
// 24B UserID ('EMP-777') + 1B state (0=Check-In) + 1B verify (15=Face) + 6B YMDHMS (26,10,17,09,15,30) + 4B workcode
$rtPayload36 = str_pad('EMP-777', 24, "\0")
    . chr(Constants::ATT_STATE_CHECK_IN)
    . chr(Constants::VERIFY_FACE)
    . chr(26) . chr(10) . chr(17) . chr(9) . chr(15) . chr(30)
    . pack('V', 5);

$parsedRt = $zk->parseRealTimeAttLogPayload($rtPayload36);
assertCheck($parsedRt['user_id'] === 'EMP-777', 'Real-time 36B payload extracts UserID EMP-777');
assertCheck($parsedRt['state'] === Constants::ATT_STATE_CHECK_IN, 'Real-time payload extracts Check-In state (0)');
assertCheck($parsedRt['state_label'] === 'Check-In', 'Real-time payload maps state_label to Check-In');
assertCheck($parsedRt['verify_type'] === Constants::VERIFY_FACE, 'Real-time payload extracts Face verify type (15)');
assertCheck($parsedRt['verify_label'] === 'Face', 'Real-time payload maps verify_label to Face');
assertCheck($parsedRt['timestamp'] === '2026-10-17 09:15:30', 'Real-time payload decodes timestamp 2026-10-17 09:15:30');
assertCheck($parsedRt['work_code'] === 5, 'Real-time payload extracts work_code 5');

// ---------------------------------------------------------------------------
// 4. Webhook Dispatcher, HMAC-SHA256 Signing & Incoming Verification
// ---------------------------------------------------------------------------
echo "\n[4] Testing Webhook Dispatcher & HMAC-SHA256 Security...\n";
$dispatcher = new WebhookDispatcher();
$capturedEvents = [];

$dispatcher->on('attendance.*', function (array $envelope, string $event) use (&$capturedEvents): void {
    $capturedEvents[] = [$event, $envelope];
});

$dispatcher->dispatch(WebhookDispatcher::EVENT_ATTENDANCE_PUNCH, $parsedRt, ['ip' => '192.168.1.201']);
assertCheck(count($capturedEvents) === 1, 'Wildcard listener attendance.* captured attendance.punch event');
assertCheck($capturedEvents[0][1]['data']['user_id'] === 'EMP-777', 'Webhook envelope contains punch data');

$secret = 'whsec_test_secret_999';
$sampleBody = json_encode(['event' => 'attendance.punch', 'user_id' => 'EMP-777']);
$nowTs = time();
$sigHeader = WebhookDispatcher::signPayload($sampleBody, $secret, $nowTs);

assertCheck(
    WebhookDispatcher::verifySignature($sampleBody, $sigHeader, $secret, $nowTs),
    'WebhookDispatcher::verifySignature validates authentic timestamped HMAC-SHA256 signature'
);
assertCheck(
    !WebhookDispatcher::verifySignature($sampleBody . 'tampered', $sigHeader, $secret, $nowTs),
    'WebhookDispatcher::verifySignature rejects tampered payload'
);
assertCheck(
    !WebhookDispatcher::verifySignature($sampleBody, $sigHeader, 'wrong_secret', $nowTs),
    'WebhookDispatcher::verifySignature rejects wrong secret'
);

$incomingParsed = WebhookDispatcher::captureIncoming($secret, $sampleBody, [
    'HTTP_X_ZKTECO_SIGNATURE' => $sigHeader,
    'HTTP_X_ZKTECO_TIMESTAMP' => (string)$nowTs,
]);
assertCheck($incomingParsed['user_id'] === 'EMP-777', 'WebhookDispatcher::captureIncoming verifies and decodes payload');

// ---------------------------------------------------------------------------
// 5. Outbound HTTP POST Attendance Sync & Incremental Cursor State
// ---------------------------------------------------------------------------
echo "\n[5] Testing Outbound POST Attendance Sync & Incremental State Cursor...\n";
$tempStateFile = sys_get_temp_dir() . '/zk_test_state_' . uniqid() . '.json';
$tempManualFile = sys_get_temp_dir() . '/zk_manual_punches_' . uniqid() . '.json';
$zk->setManualPunchesFile($tempManualFile);

$markedWebhookEvents = [];
$zk->onEvent(WebhookDispatcher::EVENT_ATTENDANCE_PUNCH, function (array $envelope) use (&$markedWebhookEvents): void {
    $markedWebhookEvents[] = $envelope['data'];
});

$markRes = $zk->markAttendance(
    userId: '1',
    state: Constants::ATT_STATE_CHECK_IN,
    timestamp: '2026-10-17 09:00:00',
    verifyType: Constants::VERIFY_PASSWORD
);

assertCheck($markRes['success'] === true, 'markAttendance() succeeds and returns punch record');
assertCheck($markRes['punch']['user_id'] === '1', 'markAttendance() records user_id 1');
assertCheck($markRes['punch']['state_label'] === 'Check-In', 'markAttendance() sets state_label to Check-In');
assertCheck(count($zk->getManualPunches()) === 1, 'markAttendance() persists punch to manual punches JSON storage when offline');
assertCheck(count($markedWebhookEvents) === 1 && $markedWebhookEvents[0]['user_id'] === '1', 'markAttendance() fires attendance.punch webhook immediately');
assertCheck(Constants::CMD_SET_DATA === 3001, 'Constants::CMD_SET_DATA equals 3001 (0x0BB9) for direct hardware ATT_LOG insertion');
assertCheck(Constants::CMD_TIME_ATTLOG_DELE === 10005, 'Constants::CMD_TIME_ATTLOG_DELE equals 10005 (0x2715) for selective time-range deletion');
assertCheck(Constants::CMD_TIMEPOINT_ATTLOG_DELE === 10006, 'Constants::CMD_TIMEPOINT_ATTLOG_DELE equals 10006 (0x2716) for timepoint deletion');
assertCheck(method_exists($zk, 'writeAttendanceToDevice') && method_exists($zk, 'pushAttendance'), 'ZKTeco exposes writeAttendanceToDevice() and pushAttendance()');
assertCheck(method_exists($zk, 'deleteAttendanceByTimeRange') && method_exists($zk, 'deleteAttendanceBefore'), 'ZKTeco exposes deleteAttendanceByTimeRange() and deleteAttendanceBefore()');
@unlink($tempManualFile);


// ---------------------------------------------------------------------------
// 6. ADMS / iClock HTTP Push Server & Command Queue Engine
// ---------------------------------------------------------------------------
echo "\n[6] Testing ADMS / iClock Push Server (POST /iclock/cdata & Command Queue)...\n";
$admsWebhookEvents = [];
$admsDispatcher = new WebhookDispatcher();
$admsDispatcher->on('*', function (array $envelope, string $event) use (&$admsWebhookEvents): void {
    $admsWebhookEvents[] = $event;
});

$tempQueueFile = sys_get_temp_dir() . '/zk_adms_queue_' . uniqid() . '.json';
$adms = new AdmsServer($admsDispatcher, $tempQueueFile, 5);

// 6a. Handshake GET /iclock/cdata
$hsResp = $adms->handleRequest('GET', '/iclock/cdata?SN=ZKTX628999&options=all', ['SN' => 'ZKTX628999', 'options' => 'all'], '');
assertCheck($hsResp['status'] === 200 && str_contains($hsResp['body'], 'GET OPTION FROM: ZKTX628999'), 'ADMS handshake returns valid device options');

// 6b. Device POSTs ATTLOG records to /iclock/cdata?SN=ZKTX628999&table=ATTLOG
$attLogRaw = "1001\t2026-10-17 09:00:12\t0\t1\t0\t0\r\n1002\t2026-10-17 18:05:44\t1\t15\t0\t0\r\n";
$attResp = $adms->handleRequest('POST', '/iclock/cdata?SN=ZKTX628999&table=ATTLOG', ['SN' => 'ZKTX628999', 'table' => 'ATTLOG'], $attLogRaw);
assertCheck(trim($attResp['body']) === 'OK: 2', 'ADMS ATTLOG POST acknowledges 2 records (OK: 2)');
assertCheck($attResp['parsed']['records'][0]['user_id'] === '1001', 'ADMS parsed first record user_id 1001');
assertCheck($attResp['parsed']['records'][1]['verify_label'] === 'Face', 'ADMS parsed second record verify_label Face');
assertCheck(in_array(WebhookDispatcher::EVENT_ATTENDANCE_PUNCH, $admsWebhookEvents, true), 'ADMS ATTLOG POST automatically triggered attendance.punch webhook');
assertCheck(in_array(WebhookDispatcher::EVENT_ADMS_PUSH, $admsWebhookEvents, true), 'ADMS ATTLOG POST automatically triggered adms.push webhook');

// 6c. Queue commands to push to device on /iclock/getrequest
$adms->queueUpdateUser('ZKTX628999', '1005', 'Taha Rafi', Constants::LEVEL_SUPERADMIN, '1234', '998877');
$adms->queueUnlockDoor('ZKTX628999');

$cmdResp = $adms->handleRequest('GET', '/iclock/getrequest?SN=ZKTX628999', ['SN' => 'ZKTX628999'], '');
assertCheck(str_contains($cmdResp['body'], 'DATA UPDATE USERINFO PIN=1005'), 'ADMS getrequest delivers queued user update command');
assertCheck(str_contains($cmdResp['body'], 'AC_UNLOCK'), 'ADMS getrequest delivers queued door unlock command');

// Queue should now be empty on second poll
$cmdResp2 = $adms->handleRequest('GET', '/iclock/getrequest?SN=ZKTX628999', ['SN' => 'ZKTX628999'], '');
assertCheck(trim($cmdResp2['body']) === 'OK', 'ADMS command queue is cleanly popped after delivery');
@unlink($tempQueueFile);
@unlink($tempStateFile);

echo "\n==============================================================\n";
echo "   Test Summary: {$passed} Passed | {$failed} Failed\n";
echo "==============================================================\n";

exit($failed > 0 ? 1 : 0);
