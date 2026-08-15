<?php
/**
 * Basic Attendance & Users Sync Example
 * php-zkteco Library
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\ZKTeco;

// Device connection parameters
$deviceIp = '192.168.1.201'; // Device IP (e.g. 192.168.1.201 or your device IP)
$devicePort = 4370;            // UDP Port (Default: 4370)
$commKey = 0;                  // Security Comm Key (0 if not set)

echo "Connecting to ZKTeco Biometric Device at {$deviceIp}:{$devicePort}...\n";

$zk = new ZKTeco($deviceIp, $devicePort, $commKey);

if ($zk->connect()) {
    echo "✅ Successfully connected and authenticated!\n\n";

    // Disable device while extracting data to prevent race conditions
    $zk->disableDevice();

    // 1. Fetch all registered users
    $users = $zk->getUser();
    echo "Total Registered Users: " . count($users) . "\n";
    foreach (array_slice($users, 0, 5, true) as $uid => $user) {
        echo "  - [UID {$uid}] UserID: {$user[0]} | Name: {$user[1]} | Role: {$user[2]}\n";
    }

    // 2. Fetch all attendance records
    $attendance = $zk->getAttendance();
    echo "\nTotal Attendance Logs: " . count($attendance) . "\n";
    foreach (array_slice($attendance, -5) as $log) {
        $status = ($log[2] == 0 || $log[2] == 4) ? 'Check In' : 'Check Out';
        echo "  - UID: {$log[0]} | UserID: {$log[1]} | State: {$log[2]} ({$status}) | Time: {$log[3]}\n";
    }

    // Re-enable device and cleanly close socket
    $zk->enableDevice();
    $zk->disconnect();
    echo "\nSession cleanly disconnected.\n";
} else {
    echo "❌ Failed to connect to device!\n";
}
