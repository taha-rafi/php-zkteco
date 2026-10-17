<?php
/**
 * ZKTeco Webhook Dispatcher & Signature Verification Engine
 *
 * Dispatches real-time biometric events, attendance batches, and device lifecycle
 * notifications to remote HTTP/HTTPS endpoints with HMAC-SHA256 signing and retries.
 *
 * @package   TahaRafi\ZKTeco
 * @author    Taha Rafi <connect@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco;

class WebhookDispatcher
{
    // Standard Webhook Event Constants
    public const EVENT_ATTENDANCE_PUNCH = 'attendance.punch';
    public const EVENT_ATTENDANCE_SYNC  = 'attendance.sync';
    public const EVENT_ATTENDANCE_CLEAR = 'attendance.cleared';
    public const EVENT_USER_CREATED     = 'user.created';
    public const EVENT_USER_DELETED     = 'user.deleted';
    public const EVENT_USER_SYNC        = 'user.sync';
    public const EVENT_DEVICE_CONNECTED = 'device.connected';
    public const EVENT_DEVICE_DISCONNECTED = 'device.disconnected';
    public const EVENT_DOOR_UNLOCKED    = 'door.unlocked';
    public const EVENT_DEVICE_ALARM     = 'device.alarm';
    public const EVENT_ADMS_PUSH        = 'adms.push';

    /** @var array<int, array{url: string, secret: ?string, events: array<int, string>, headers: array<string, string>}> */
    private array $endpoints = [];

    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    private int $timeoutSec;
    private int $maxRetries;
    private int $retryDelayMs;
    private bool $verifySsl;

    /** @var array<int, array<string, mixed>> */
    private array $deliveryLogs = [];

    public function __construct(
        int $timeoutSec = 10,
        int $maxRetries = 3,
        int $retryDelayMs = 400,
        bool $verifySsl = true
    ) {
        $this->timeoutSec = max(1, $timeoutSec);
        $this->maxRetries = max(1, $maxRetries);
        $this->retryDelayMs = max(0, $retryDelayMs);
        $this->verifySsl = $verifySsl;
    }

    /**
     * Register a remote webhook endpoint URL
     *
     * @param string $url Target HTTPS/HTTP webhook URL
     * @param string|null $secret Optional HMAC-SHA256 signing secret
     * @param array<int, string> $events Subscribed events (default ['*'] for all events)
     * @param array<string, string> $headers Additional HTTP headers (e.g. Authorization)
     */
    public function addEndpoint(
        string $url,
        ?string $secret = null,
        array $events = ['*'],
        array $headers = []
    ): self {
        $this->endpoints[] = [
            'url'     => $url,
            'secret'  => $secret,
            'events'  => empty($events) ? ['*'] : $events,
            'headers' => $headers,
        ];
        return $this;
    }

    /**
     * Register a local PHP callback listener for an event (or '*' for all)
     */
    public function on(string $event, callable $callback): self
    {
        $this->listeners[$event][] = $callback;
        return $this;
    }

    /**
     * Return all configured endpoints
     */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * Return delivery history logs from this instance
     */
    public function getDeliveryLogs(): array
    {
        return $this->deliveryLogs;
    }

    /**
     * Clear delivery history logs
     */
    public function clearDeliveryLogs(): void
    {
        $this->deliveryLogs = [];
    }

    /**
     * Dispatch an event to all matching local listeners and remote webhook URLs
     *
     * @param string $event Event name (e.g. 'attendance.punch')
     * @param array<string, mixed> $data Event payload data
     * @param array<string, mixed> $deviceMeta Device context metadata
     * @return array<int, array<string, mixed>> Delivery results per webhook endpoint
     */
    public function dispatch(string $event, array $data, array $deviceMeta = []): array
    {
        $timestamp = time();
        $deliveryId = $this->generateDeliveryId();

        $envelope = [
            'id'         => $deliveryId,
            'event'      => $event,
            'created_at' => date('c', $timestamp),
            'timestamp'  => $timestamp,
            'device'     => $deviceMeta,
            'data'       => $data,
        ];

        // 1. Trigger local PHP listeners (supports exact match, '*', and prefix wildcards like 'attendance.*')
        foreach ($this->listeners as $listenPattern => $callbacks) {
            if ($this->matchesEvent([(string)$listenPattern], $event)) {
                foreach ($callbacks as $listener) {
                    try {
                        $listener($envelope, $event);
                    } catch (\Throwable $e) {
                        // Prevent local listener exceptions from halting webhook delivery
                    }
                }
            }
        }

        // 2. Dispatch to matching remote HTTP webhook endpoints
        $results = [];
        foreach ($this->endpoints as $endpoint) {
            if (!$this->matchesEvent($endpoint['events'], $event)) {
                continue;
            }

            $result = $this->sendWebhookRequest(
                $endpoint['url'],
                $envelope,
                $event,
                $deliveryId,
                $timestamp,
                $endpoint['secret'],
                $endpoint['headers']
            );

            $this->deliveryLogs[] = $result;
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Send a direct HTTP POST JSON request with optional HMAC signing and retry logic
     *
     * @param string $url Target URL
     * @param array<string, mixed> $payload JSON-serializable payload
     * @param array<string, mixed> $options ['headers' => [], 'token' => null, 'secret' => null, 'event' => 'attendance.post', 'retries' => 3, 'timeout' => 10]
     * @return array{url: string, success: bool, status_code: int, attempts: int, response: string, error: ?string, delivery_id: string}
     */
    public function sendPost(string $url, array $payload, array $options = []): array
    {
        $timestamp = time();
        $deliveryId = $this->generateDeliveryId();
        $event = (string)($options['event'] ?? self::EVENT_ATTENDANCE_SYNC);
        $secret = isset($options['secret']) ? (string)$options['secret'] : null;
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];

        if (!empty($options['token'])) {
            $token = (string)$options['token'];
            if (!str_starts_with(strtolower($token), 'bearer ') && !str_starts_with(strtolower($token), 'basic ')) {
                $token = 'Bearer ' . $token;
            }
            $headers['Authorization'] = $token;
        }

        $origRetries = $this->maxRetries;
        $origTimeout = $this->timeoutSec;

        if (isset($options['retries'])) {
            $this->maxRetries = max(1, (int)$options['retries']);
        }
        if (isset($options['timeout'])) {
            $this->timeoutSec = max(1, (int)$options['timeout']);
        }

        try {
            $result = $this->sendWebhookRequest(
                $url,
                $payload,
                $event,
                $deliveryId,
                $timestamp,
                $secret,
                $headers
            );
            $this->deliveryLogs[] = $result;
            return $result;
        } finally {
            $this->maxRetries = $origRetries;
            $this->timeoutSec = $origTimeout;
        }
    }

    /**
     * Compute HMAC-SHA256 signature for a webhook payload
     */
    public static function signPayload(string $jsonPayload, string $secret, ?int $timestamp = null): string
    {
        $signingString = $timestamp !== null
            ? $timestamp . '.' . $jsonPayload
            : $jsonPayload;

        return 'sha256=' . hash_hmac('sha256', $signingString, $secret);
    }

    /**
     * Verify an incoming webhook signature using timing-safe comparison
     *
     * @param string $rawBody Raw HTTP request body (php://input)
     * @param string $signatureHeader Value of X-ZKTeco-Signature header
     * @param string $secret Shared webhook secret
     * @param int|null $timestamp Value of X-ZKTeco-Timestamp header (if replay protection is used)
     * @param int $toleranceSec Maximum allowed clock skew in seconds (default 300s / 5m)
     */
    public static function verifySignature(
        string $rawBody,
        string $signatureHeader,
        string $secret,
        ?int $timestamp = null,
        int $toleranceSec = 300
    ): bool {
        if ($signatureHeader === '' || $secret === '') {
            return false;
        }

        if ($timestamp !== null && $toleranceSec > 0) {
            if (abs(time() - $timestamp) > $toleranceSec) {
                return false;
            }
            $expectedTimestamped = self::signPayload($rawBody, $secret, $timestamp);
            if (hash_equals($expectedTimestamped, trim($signatureHeader))) {
                return true;
            }
        }

        $expectedRaw = self::signPayload($rawBody, $secret, null);
        return hash_equals($expectedRaw, trim($signatureHeader));
    }

    /**
     * Helper for webhook receiver endpoints to capture, verify, and parse incoming webhook POSTs
     *
     * @param string|null $secret Optional shared secret to enforce signature verification
     * @param string|null $rawBodyOverride Optional raw body (defaults to php://input)
     * @param array<string, mixed>|null $serverOverride Optional $_SERVER override for testing
     * @return array<string, mixed> Decoded webhook payload
     * @throws \RuntimeException If signature verification or JSON parsing fails
     */
    public static function captureIncoming(
        ?string $secret = null,
        ?string $rawBodyOverride = null,
        ?array $serverOverride = null
    ): array {
        $rawBody = $rawBodyOverride ?? (string)@file_get_contents('php://input');
        $server = $serverOverride ?? $_SERVER;

        if ($secret !== null && $secret !== '') {
            $sigHeader = (string)($server['HTTP_X_ZKTECO_SIGNATURE'] ?? $server['HTTP_X_ZKTECO_SIGNATURE_RAW'] ?? '');
            $tsHeader = isset($server['HTTP_X_ZKTECO_TIMESTAMP']) ? (int)$server['HTTP_X_ZKTECO_TIMESTAMP'] : null;

            if (!self::verifySignature($rawBody, $sigHeader, $secret, $tsHeader)) {
                throw new \RuntimeException('Invalid ZKTeco webhook signature.');
            }
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON in ZKTeco webhook body.');
        }

        return $decoded;
    }

    private function matchesEvent(array $subscribedEvents, string $event): bool
    {
        foreach ($subscribedEvents as $pattern) {
            if ($pattern === '*' || $pattern === $event) {
                return true;
            }
            // Support prefix wildcard e.g. 'attendance.*'
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1);
                if (str_starts_with($event, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function sendWebhookRequest(
        string $url,
        array $payload,
        string $event,
        string $deliveryId,
        int $timestamp,
        ?string $secret,
        array $customHeaders
    ): array {
        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type'        => 'application/json',
            'Accept'              => 'application/json',
            'User-Agent'          => 'php-zkteco/2.0 (+https://github.com/taha-rafi/php-zkteco)',
            'X-ZKTeco-Event'      => $event,
            'X-ZKTeco-Delivery'   => $deliveryId,
            'X-ZKTeco-Timestamp'  => (string)$timestamp,
        ];

        if ($secret !== null && $secret !== '') {
            $headers['X-ZKTeco-Signature'] = self::signPayload($json, $secret, $timestamp);
            $headers['X-ZKTeco-Signature-Raw'] = self::signPayload($json, $secret, null);
        }

        foreach ($customHeaders as $k => $v) {
            $headers[(string)$k] = (string)$v;
        }

        $attempts = 0;
        $statusCode = 0;
        $responseBody = '';
        $lastError = null;

        while ($attempts < $this->maxRetries) {
            $attempts++;
            [$statusCode, $responseBody, $lastError] = $this->executeHttpPost($url, $json, $headers);

            // Success on any 2xx status code
            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'url'         => $url,
                    'event'       => $event,
                    'delivery_id' => $deliveryId,
                    'success'     => true,
                    'status_code' => $statusCode,
                    'attempts'    => $attempts,
                    'response'    => $responseBody,
                    'error'       => null,
                ];
            }

            // Do not retry on 4xx client errors (except 408 Request Timeout / 429 Too Many Requests)
            if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 408 && $statusCode !== 429) {
                break;
            }

            if ($attempts < $this->maxRetries && $this->retryDelayMs > 0) {
                usleep($this->retryDelayMs * 1000 * $attempts);
            }
        }

        return [
            'url'         => $url,
            'event'       => $event,
            'delivery_id' => $deliveryId,
            'success'     => false,
            'status_code' => $statusCode,
            'attempts'    => $attempts,
            'response'    => $responseBody,
            'error'       => $lastError ?? ($statusCode > 0 ? "HTTP {$statusCode}" : 'Connection failed'),
        ];
    }

    /**
     * Execute HTTP POST using cURL (if available) or native PHP stream context
     *
     * @param array<string, string> $headers
     * @return array{0: int, 1: string, 2: ?string} [statusCode, responseBody, error]
     */
    private function executeHttpPost(string $url, string $body, array $headers): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => $headerLines,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeoutSec,
                    CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSec),
                    CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                    CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                ]);

                $resp = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($resp === false) {
                    return [$status, '', $err ?: 'cURL request failed'];
                }
                return [$status, (string)$resp, null];
            }
        }

        // Pure PHP Stream Fallback (Zero Extension Dependency)
        $contextOptions = [
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headerLines) . "\r\n",
                'content'       => $body,
                'timeout'       => $this->timeoutSec,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => $this->verifySsl,
                'verify_peer_name' => $this->verifySsl,
            ],
        ];

        $context = stream_context_create($contextOptions);
        $http_response_header = [];
        $resp = @file_get_contents($url, false, $context);

        $status = 0;
        if (!empty($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $hdr, $m)) {
                    $status = (int)$m[1];
                }
            }
        }

        if ($resp === false) {
            $err = error_get_last()['message'] ?? 'Stream HTTP request failed';
            return [$status, '', $err];
        }

        return [$status, (string)$resp, null];
    }

    private function generateDeliveryId(): string
    {
        try {
            return 'zk_evt_' . bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            return 'zk_evt_' . uniqid('', true);
        }
    }
}
