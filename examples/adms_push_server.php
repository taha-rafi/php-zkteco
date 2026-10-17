<?php
/**
 * ZKTeco ADMS / iClock Cloud Push Receiver & Webhook Bridge Example
 *
 * Point your ZKTeco device's "Cloud Server (ADMS)" IP/Domain to this script
 * (e.g. http://your-server.com/iclock/cdata). The device will automatically
 * POST attendance logs in real time without needing port forwarding!
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/WebhookDispatcher.php';
require_once __DIR__ . '/../src/AdmsServer.php';

use TahaRafi\ZKTeco\AdmsServer;
use TahaRafi\ZKTeco\WebhookDispatcher;

// 1. Configure Webhook Dispatcher so every ADMS device push also fires webhooks
$webhooks = new WebhookDispatcher();
$webhooks->addEndpoint(
    url: 'https://your-erp.com/webhooks/zkteco-adms',
    secret: 'whsec_adms_secret',
    events: ['attendance.punch', 'adms.push']
);

// 2. Initialize ADMS Push Server with a local JSON queue file for outbound device commands
$adms = new AdmsServer(
    webhookDispatcher: $webhooks,
    commandQueueFile: __DIR__ . '/.adms_command_queue.json',
    timezoneOffset: 5 // UTC+5 (Pakistan Standard Time)
);

// 3. Handle incoming real-time attendance POSTed by the ZKTeco machine
$adms->onAttendance(function (string $serialNumber, array $records): void {
    // Save $records to your MySQL/PostgreSQL database here
    error_log("[ADMS] Device {$serialNumber} pushed " . count($records) . " attendance record(s).");
});

// Optional: Queue commands to be pushed to the device on its next poll:
// $adms->queueUpdateUser('ABCD123456', '105', 'Taha Rafi');
// $adms->queueUnlockDoor('ABCD123456');

// 4. Process request and output ZKTeco-compliant response
$response = $adms->handleRequest();

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
echo $response['body'];
