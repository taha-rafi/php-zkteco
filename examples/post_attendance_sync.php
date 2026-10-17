<?php
/**
 * POST Attendance & Users to External API / Cloud ERP Example
 * php-zkteco v2.0 Library
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\ZKTeco;

$zk = new ZKTeco('192.168.1.201', 4370, 0, Constants::PROTOCOL_AUTO);

if (!$zk->connect()) {
    die("❌ Could not connect to ZKTeco device.\n");
}

echo "✅ Connected via " . strtoupper((string)$zk->getActiveProtocol()) . "!\n";

// 1. POST Attendance Logs to your Cloud Server / HRMS API
//    - Uses 'state_file' to remember the last synced timestamp so only NEW punches are POSTed!
//    - Automatically chunks large datasets into batches of 250 records
//    - Signs every POST request with HMAC-SHA256 (X-ZKTeco-Signature header)
$result = $zk->postAttendance('https://your-erp-domain.com/api/v1/biometric/attendance', [
    'token'            => 'your-api-bearer-token',
    'secret'           => 'whsec_your_hmac_signing_secret',
    'state_file'       => __DIR__ . '/.zk_attendance_cursor.json',
    'batch_size'       => 250,
    'retries'          => 3,
    'clear_after_post' => false, // Set true to wipe device logs ONLY if all POST batches return HTTP 2xx
]);

echo "Attendance POST Sync Result:\n";
echo "  - Success          : " . ($result['success'] ? 'YES' : 'NO') . "\n";
echo "  - New Records Sent : {$result['total_records']}\n";
echo "  - Batches Succeeded: {$result['batches_succeeded']} / {$result['batches_sent']}\n";
echo "  - Latest Timestamp : " . ($result['latest_timestamp'] ?? 'N/A') . "\n\n";

// 2. POST Enrolled Users from Device to Cloud API
$userSync = $zk->postUsers('https://your-erp-domain.com/api/v1/biometric/users', [
    'token'  => 'your-api-bearer-token',
    'secret' => 'whsec_your_hmac_signing_secret',
]);
echo "Users POST Sync: {$userSync['total_users']} users (" . ($userSync['success'] ? 'OK' : 'Failed') . ")\n\n";

// 3. Push/POST Users from Your Database TO the ZKTeco Machine (Bulk Sync)
$employeesToPush = [
    ['uid' => 101, 'user_id' => 'EMP101', 'name' => 'Taha Rafi', 'role' => Constants::LEVEL_SUPERADMIN, 'card_no' => 12345678],
    ['uid' => 102, 'user_id' => 'EMP102', 'name' => 'Ali Raza',  'role' => Constants::LEVEL_USER,       'card_no' => 87654321],
];

$pushStats = $zk->pushUsers($employeesToPush);
echo "Pushed Users to Machine: {$pushStats['succeeded']}/{$pushStats['total']} succeeded.\n";

$zk->disconnect();
