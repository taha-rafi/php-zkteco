<?php
/**
 * Device Hardware Diagnostics & Control Example
 * php-zkteco Library
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\ZKTeco;

$zk = new ZKTeco('192.168.1.201', 4370, 0);

if ($zk->connect()) {
    echo "✅ Connected to device.\n\n";

    echo "--- Device Hardware Information ---\n";
    echo "Device Model     : " . $zk->getDeviceName() . "\n";
    echo "Serial Number    : " . $zk->getSerialNumber() . "\n";
    echo "Firmware Version : " . $zk->getFirmwareVersion() . "\n";
    echo "OS Version       : " . $zk->getOSVersion() . "\n";
    echo "Platform         : " . $zk->getPlatform() . "\n";
    echo "Current Time     : " . $zk->getTime() . "\n";

    // Synchronize Hardware Time with Server Time
    $serverTime = date('Y-m-d H:i:s');
    echo "\nSyncing Device Time to Server Time ({$serverTime})...\n";
    $zk->setTime($serverTime);
    echo "Updated Device Time: " . $zk->getTime() . "\n";

    // Play Voice Confirmation Test
    echo "Testing voice prompt on device speaker...\n";
    $zk->testVoice();

    // Restart Device (Optional - commented out)
    // echo "Rebooting device...\n";
    // $zk->restartDevice();

    $zk->disconnect();
    echo "\nCompleted.\n";
} else {
    echo "❌ Connection failed.\n";
}
