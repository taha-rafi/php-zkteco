<?php
/**
 * Example 04: Direct Hardware Attendance Punching (Write Directly into ZKTeco Machine ATT_LOG)
 *
 * Demonstrates:
 *  1. Writing an attendance punch (Check-In / Check-Out) directly into the physical ZKTeco
 *     machine's internal ATT_LOG database via CMD_SET_DATA (3001) + CMD_REFRESHDATA (1013)
 *  2. Bulk-pushing multiple attendance records into the physical machine via pushAttendance()
 *  3. Selective attendance deletion by time range (deleteAttendanceByTimeRange) without wiping all logs
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/AdmsServer.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\ZKTeco;

$ip      = '192.168.1.201';
$port    = 4370;
$commKey = 0;

$zk = new ZKTeco($ip, $port, $commKey, Constants::PROTOCOL_AUTO);

// Optional: Register a webhook to be notified whenever a punch is marked
$zk->addWebhook('https://your-hrms.example.com/webhooks/zkteco', 'your-hmac-secret', ['attendance.punch']);

if (!$zk->connectWithRetry(3)) {
    die("Could not connect to ZKTeco device at {$ip}:{$port}\n");
}

// 1. Mark an attendance punch directly inside the physical ZKTeco machine's ATT_LOG!
$result = $zk->markAttendance(
    userId: '1',                             // Employee User ID on the machine
    state: Constants::ATT_STATE_CHECK_OUT,   // Check-In (0), Check-Out (1), Break-Out (2), Break-In (3)
    timestamp: null,                         // null = current time (or pass 'YYYY-MM-DD HH:MM:SS')
    verifyType: Constants::VERIFY_FINGERPRINT,
    options: [
        'write_to_device' => true,           // Writes directly into the machine's internal ATT_LOG
        'device_feedback' => true,           // Shows on machine LCD & plays "Thank You" voice
    ]
);

print_r($result);

$zk->disconnect();
