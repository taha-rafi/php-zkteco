<?php
/**
 * Dual-Protocol (TCP + UDP + Auto-Fallback) Connection Example
 * php-zkteco v2.0 Library
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\ZKTeco;

$deviceIp   = '192.168.1.201';
$devicePort = 4370;
$commKey    = 0;

// Mode 1: 'auto' (Recommended) - Tries reliable TCP first, automatically falls back to UDP if needed
$zk = new ZKTeco($deviceIp, $devicePort, $commKey, Constants::PROTOCOL_AUTO);

// You can also force TCP or UDP explicitly:
// $zk->setProtocol(Constants::PROTOCOL_TCP);
// $zk->setProtocol(Constants::PROTOCOL_UDP);

echo "Connecting to {$deviceIp}:{$devicePort} (Mode: {$zk->protocol})...\n";

if ($zk->connectWithRetry(maxAttempts: 3, retryDelayMs: 500)) {
    echo "✅ Connected & Authenticated via [" . strtoupper((string)$zk->getActiveProtocol()) . "] protocol!\n";
    echo "   - Device Name   : " . ($zk->getDeviceName() ?: 'N/A') . "\n";
    echo "   - Serial Number : " . ($zk->getSerialNumber() ?: 'N/A') . "\n";
    echo "   - Device Time   : " . ($zk->getTime() ?: 'N/A') . "\n";

    $zk->disconnect();
} else {
    echo "❌ Failed to connect over both TCP and UDP.\n";
}
