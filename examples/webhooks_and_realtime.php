<?php
/**
 * Real-Time Biometric Webhooks & Live Event Listener Example
 * php-zkteco v2.0 Library
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\WebhookDispatcher;
use TahaRafi\ZKTeco\ZKTeco;

// ============================================================================
// PART A: Sending Real-Time Webhooks from ZKTeco Device
// ============================================================================

$zk = new ZKTeco('192.168.1.201', 4370, 0, Constants::PROTOCOL_AUTO);

// Register one or multiple Webhook endpoints with HMAC-SHA256 signing secret
$zk->addWebhook(
    url: 'https://your-app.com/webhooks/zkteco',
    secret: 'whsec_super_secret_key',
    events: [
        WebhookDispatcher::EVENT_ATTENDANCE_PUNCH,
        WebhookDispatcher::EVENT_USER_CREATED,
        WebhookDispatcher::EVENT_DOOR_UNLOCKED,
        WebhookDispatcher::EVENT_DEVICE_ALARM,
    ],
    headers: [
        'X-Organization-ID' => 'ORG-01',
    ]
);

// Optional: Attach a local PHP listener for instant logging or database inserts
$zk->onEvent(WebhookDispatcher::EVENT_ATTENDANCE_PUNCH, function (array $envelope): void {
    $punch = $envelope['data'];
    echo "⚡ [LIVE PUNCH] User {$punch['user_id']} | {$punch['state_label']} via {$punch['verify_label']} at {$punch['timestamp']}\n";
});

if ($zk->connect()) {
    echo "✅ Connected via " . strtoupper((string)$zk->getActiveProtocol()) . "! Listening for live punches (60s)...\n";

    // Listen for live punches for 60 seconds (pass 0 to listen forever in a daemon/supervisor worker)
    $zk->listenRealTime(function (array $data, string $event): bool {
        echo "   -> Dispatched Webhook Event: {$event}\n";
        return true; // Return false to stop listening loop early
    }, maxDurationSec: 60);

    $zk->disconnect();
}

// ============================================================================
// PART B: Receiving & Verifying Webhooks on Your Server Endpoint
// ============================================================================
/*
// In your webhook receiver controller (e.g. public/webhooks/zkteco.php):
try {
    $eventPayload = WebhookDispatcher::captureIncoming('whsec_super_secret_key');
    $eventName    = $eventPayload['event']; // e.g. 'attendance.punch'
    $punchData    = $eventPayload['data'];

    http_response_code(200);
    echo json_encode(['received' => true, 'id' => $eventPayload['id']]);
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()]);
}
*/
