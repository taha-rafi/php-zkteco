<?php
/**
 * ZKTeco Client Quick Test CLI Script (v2.0 Dual-Protocol + POST + Webhooks)
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Constants.php';
require_once __DIR__ . '/src/WebhookDispatcher.php';
require_once __DIR__ . '/src/AdmsServer.php';
require_once __DIR__ . '/src/ZKTeco.php';

use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\ZKTeco;

$ip       = $argv[1] ?? '192.168.1.201';
$port     = (int)($argv[2] ?? 4370);
$commKey  = (int)($argv[3] ?? 0);
$protocol = $argv[4] ?? Constants::PROTOCOL_AUTO; // 'auto', 'tcp', or 'udp'
$postUrl  = $argv[5] ?? null;

echo "==============================================================\n";
echo "   php-zkteco v2.0 (Dual TCP/UDP + POST Sync + Webhooks)      \n";
echo "   Author: Taha Rafi (Software Engineer)                      \n";
echo "==============================================================\n";
echo "Connecting to: {$ip}:{$port} | Comm Key: {$commKey} | Mode: " . strtoupper($protocol) . "\n\n";

$zk = new ZKTeco($ip, $port, $commKey, $protocol);

if (!$zk->connect()) {
    echo "❌ Connection Failed over " . strtoupper($protocol) . "!\n";
    exit(1);
}

echo "✅ Connected & Authenticated Successfully via [" . strtoupper((string)$zk->getActiveProtocol()) . "]!\n\n";

echo "Device Information:\n";
echo "  - Active Protocol : " . strtoupper((string)$zk->getActiveProtocol()) . "\n";
echo "  - Device Name     : " . ($zk->getDeviceName() ?: 'N/A') . "\n";
echo "  - Serial No       : " . ($zk->getSerialNumber() ?: 'N/A') . "\n";
echo "  - Firmware        : " . ($zk->getFirmwareVersion() ?: 'N/A') . "\n";
echo "  - Device Time     : " . ($zk->getTime() ?: 'N/A') . "\n";
echo "  - Platform        : " . ($zk->getPlatform() ?: 'N/A') . "\n\n";

$zk->disableDevice();

echo "Pulling Users...\n";
$users = $zk->getUsersStructured();
$userCount = is_array($users) ? count($users) : 0;
echo "✅ Users Retrieved: {$userCount}\n";
if ($userCount > 0) {
    foreach (array_slice($users, 0, 5) as $u) {
        echo "   - [UID {$u['uid']}] ID: {$u['user_id']} | Name: {$u['name']} | Role: {$u['role']}\n";
    }
}

echo "\nPulling Attendance Logs...\n";
$attendance = $zk->getAttendanceStructured();
$attCount = is_array($attendance) ? count($attendance) : 0;
echo "✅ Attendance Logs: {$attCount}\n";
if ($attCount > 0) {
    echo "   Latest 5 Punches:\n";
    foreach (array_slice($attendance, -5) as $a) {
        echo "   - [UID {$a['uid']}] UserID: {$a['user_id']} | {$a['state_label']} ({$a['state']}) | {$a['verify_label']} | {$a['timestamp']}\n";
    }
}
echo "\n";

$zk->enableDevice();

if ($postUrl && is_array($attendance)) {
    echo "POSTing Attendance Logs to: {$postUrl}...\n";
    $postRes = $zk->postAttendance($postUrl, [
        'records'    => $attendance,
        'batch_size' => 500,
    ]);
    echo "✅ POST Sync Status: " . ($postRes['success'] ? 'SUCCESS' : 'FAILED')
        . " ({$postRes['batches_succeeded']}/{$postRes['batches_sent']} batches)\n\n";
}

$zk->disconnect();

echo "Session Cleanly Disconnected.\n";
echo "==============================================================\n";
