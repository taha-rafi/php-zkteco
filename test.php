<?php
/**
 * ZKTeco Client Quick Test CLI Script
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Constants.php';
require_once __DIR__ . '/src/ZKTeco.php';

use TahaRafi\ZKTeco\ZKTeco;

$ip = $argv[1] ?? '192.168.1.201';
$port = (int)($argv[2] ?? 4370);
$commKey = (int)($argv[3] ?? 0);

echo "====================================================\n";
echo "   php-zkteco v1.0 (Pure Native PHP Socket Engine)  \n";
echo "   Author: Taha Rafi (Software Engineer)            \n";
echo "====================================================\n";
echo "Connecting to: {$ip}:{$port} (Comm Key: {$commKey})\n\n";

$zk = new ZKTeco($ip, $port, $commKey);

if (!$zk->connect()) {
    echo "❌ Connection Failed!\n";
    exit(1);
}

echo "✅ Connected & Authenticated Successfully!\n\n";

echo "Device Information:\n";
echo "  - Device Name : " . ($zk->getDeviceName() ?: 'N/A') . "\n";
echo "  - Serial No   : " . ($zk->getSerialNumber() ?: 'N/A') . "\n";
echo "  - Firmware    : " . ($zk->getFirmwareVersion() ?: 'N/A') . "\n";
echo "  - Device Time : " . ($zk->getTime() ?: 'N/A') . "\n";
echo "  - Platform    : " . ($zk->getPlatform() ?: 'N/A') . "\n\n";

$zk->disableDevice();

echo "Pulling Users...\n";
$users = $zk->getUser();
$userCount = is_array($users) ? count($users) : 0;
echo "✅ Users Retrieved: {$userCount}\n";

echo "Pulling Attendance Logs...\n";
$attendance = $zk->getAttendance();
$attCount = is_array($attendance) ? count($attendance) : 0;
echo "✅ Attendance Logs: {$attCount}\n\n";

$zk->enableDevice();
$zk->disconnect();

echo "Session Cleanly Disconnected.\n";
echo "====================================================\n";
