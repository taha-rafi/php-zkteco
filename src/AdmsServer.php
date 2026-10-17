<?php
/**
 * ZKTeco ADMS / iClock HTTP Push Server & Command Queue Engine
 *
 * Handles inbound HTTP GET/POST requests from ZKTeco Cloud/ADMS-enabled devices
 * (/iclock/cdata, /iclock/getrequest, /iclock/devicecmd) and automatically
 * forwards incoming attendance punches and user enrollments to Webhooks.
 *
 * @package   TahaRafi\ZKTeco
 * @author    Taha Rafi <connect@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco;

class AdmsServer
{
    private ?WebhookDispatcher $webhookDispatcher = null;
    private ?string $commandQueueFile = null;
    private int $timezoneOffset = 0;

    /** @var callable|null */
    private $onAttendanceCallback = null;

    /** @var callable|null */
    private $onOperationLogCallback = null;

    /** @var callable|null */
    private $onHandshakeCallback = null;

    public function __construct(
        ?WebhookDispatcher $webhookDispatcher = null,
        ?string $commandQueueFile = null,
        int $timezoneOffset = 0
    ) {
        $this->webhookDispatcher = $webhookDispatcher;
        $this->commandQueueFile = $commandQueueFile;
        $this->timezoneOffset = $timezoneOffset;
    }

    /**
     * Attach a WebhookDispatcher to broadcast ADMS push events automatically
     */
    public function setWebhookDispatcher(WebhookDispatcher $dispatcher): self
    {
        $this->webhookDispatcher = $dispatcher;
        return $this;
    }

    /**
     * Register callback triggered when device POSTs attendance records (ATTLOG)
     * Signature: function(string $serialNumber, array $records): void
     */
    public function onAttendance(callable $callback): self
    {
        $this->onAttendanceCallback = $callback;
        return $this;
    }

    /**
     * Register callback triggered when device POSTs operation/user logs (OPERLOG)
     * Signature: function(string $serialNumber, array $operations): void
     */
    public function onOperationLog(callable $callback): self
    {
        $this->onOperationLogCallback = $callback;
        return $this;
    }

    /**
     * Register callback triggered when device performs initial handshake
     * Signature: function(string $serialNumber, array $query): void
     */
    public function onHandshake(callable $callback): self
    {
        $this->onHandshakeCallback = $callback;
        return $this;
    }

    /**
     * Handle an incoming ADMS HTTP request (/iclock/cdata, /iclock/getrequest, /iclock/devicecmd)
     *
     * @param string|null $method HTTP method override (defaults to $_SERVER['REQUEST_METHOD'])
     * @param string|null $uri Request URI path override (defaults to $_SERVER['REQUEST_URI'])
     * @param array<string, mixed>|null $query Query params override (defaults to $_GET)
     * @param string|null $rawBody Request body override (defaults to php://input)
     * @return array{status: int, headers: array<string, string>, body: string, parsed: array<string, mixed>}
     */
    public function handleRequest(
        ?string $method = null,
        ?string $uri = null,
        ?array $query = null,
        ?string $rawBody = null
    ): array {
        $method = strtoupper($method ?? (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = $uri ?? (string)($_SERVER['REQUEST_URI'] ?? '/iclock/cdata');
        $query = $query ?? $_GET;
        $rawBody = $rawBody ?? (string)@file_get_contents('php://input');

        $path = (string)parse_url($uri, PHP_URL_PATH);
        $serialNumber = trim((string)($query['SN'] ?? $query['sn'] ?? 'UNKNOWN'));

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Pragma'       => 'no-cache',
            'Cache-Control'=> 'no-store, no-cache, must-revalidate',
        ];

        // 1. Handshake GET /iclock/cdata?SN=xxx&options=all
        if ($method === 'GET' && str_contains($path, 'cdata')) {
            if ($this->onHandshakeCallback !== null) {
                ($this->onHandshakeCallback)($serialNumber, $query);
            }

            $responseLines = [
                "GET OPTION FROM: {$serialNumber}",
                "ATTLOGStamp=None",
                "OPERLOGStamp=9999",
                "ATTPHOTOStamp=None",
                "ErrorDelay=30",
                "Delay=10",
                "TransTimes=00:00;14:05",
                "TransInterval=1",
                "TransFlag=TransData AttLog\tOpLog\tAttPhoto\tEnrollUser\tChgUser\tEnrollFP\tChgFP\tFPImag",
                "TimeZone={$this->timezoneOffset}",
                "Realtime=1",
                "Encrypt=None",
            ];

            return [
                'status'  => 200,
                'headers' => $headers,
                'body'    => implode("\r\n", $responseLines) . "\r\n",
                'parsed'  => [
                    'type'          => 'handshake',
                    'serial_number' => $serialNumber,
                ],
            ];
        }

        // 2. Device Data Push: POST /iclock/cdata?SN=xxx&table=ATTLOG (or OPERLOG)
        if ($method === 'POST' && str_contains($path, 'cdata')) {
            $table = strtoupper(trim((string)($query['table'] ?? 'ATTLOG')));

            if ($table === 'ATTLOG') {
                $records = self::parseAttLogBody($rawBody, $serialNumber);

                if ($this->onAttendanceCallback !== null && !empty($records)) {
                    ($this->onAttendanceCallback)($serialNumber, $records);
                }

                if ($this->webhookDispatcher !== null && !empty($records)) {
                    $deviceMeta = [
                        'serial_number' => $serialNumber,
                        'protocol'      => 'adms_http_push',
                        'table'         => 'ATTLOG',
                    ];

                    foreach ($records as $record) {
                        $this->webhookDispatcher->dispatch(
                            WebhookDispatcher::EVENT_ATTENDANCE_PUNCH,
                            $record,
                            $deviceMeta
                        );
                    }

                    $this->webhookDispatcher->dispatch(
                        WebhookDispatcher::EVENT_ADMS_PUSH,
                        [
                            'table'         => 'ATTLOG',
                            'total_records' => count($records),
                            'records'       => $records,
                        ],
                        $deviceMeta
                    );
                }

                $count = count($records);
                return [
                    'status'  => 200,
                    'headers' => $headers,
                    'body'    => "OK: {$count}\r\n",
                    'parsed'  => [
                        'type'          => 'attlog',
                        'serial_number' => $serialNumber,
                        'count'         => $count,
                        'records'       => $records,
                    ],
                ];
            }

            if ($table === 'OPERLOG') {
                $operations = self::parseOperLogBody($rawBody, $serialNumber);

                if ($this->onOperationLogCallback !== null && !empty($operations)) {
                    ($this->onOperationLogCallback)($serialNumber, $operations);
                }

                if ($this->webhookDispatcher !== null && !empty($operations)) {
                    $this->webhookDispatcher->dispatch(
                        WebhookDispatcher::EVENT_ADMS_PUSH,
                        [
                            'table'      => 'OPERLOG',
                            'operations' => $operations,
                        ],
                        [
                            'serial_number' => $serialNumber,
                            'protocol'      => 'adms_http_push',
                            'table'         => 'OPERLOG',
                        ]
                    );
                }

                $count = count($operations);
                return [
                    'status'  => 200,
                    'headers' => $headers,
                    'body'    => "OK: {$count}\r\n",
                    'parsed'  => [
                        'type'          => 'operlog',
                        'serial_number' => $serialNumber,
                        'count'         => $count,
                        'operations'    => $operations,
                    ],
                ];
            }

            return [
                'status'  => 200,
                'headers' => $headers,
                'body'    => "OK\r\n",
                'parsed'  => [
                    'type'          => strtolower($table),
                    'serial_number' => $serialNumber,
                    'raw'           => $rawBody,
                ],
            ];
        }

        // 3. Device Polling for Server Commands: GET /iclock/getrequest?SN=xxx
        if ($method === 'GET' && str_contains($path, 'getrequest')) {
            $commands = $this->popQueuedCommands($serialNumber);
            $body = empty($commands) ? "OK\r\n" : implode("\r\n", $commands) . "\r\n";

            return [
                'status'  => 200,
                'headers' => $headers,
                'body'    => $body,
                'parsed'  => [
                    'type'          => 'getrequest',
                    'serial_number' => $serialNumber,
                    'commands'      => $commands,
                ],
            ];
        }

        // 4. Device Command Execution Result: POST /iclock/devicecmd?SN=xxx
        if ($method === 'POST' && str_contains($path, 'devicecmd')) {
            return [
                'status'  => 200,
                'headers' => $headers,
                'body'    => "OK\r\n",
                'parsed'  => [
                    'type'          => 'devicecmd',
                    'serial_number' => $serialNumber,
                    'raw'           => $rawBody,
                ],
            ];
        }

        return [
            'status'  => 200,
            'headers' => $headers,
            'body'    => "OK\r\n",
            'parsed'  => [
                'type'          => 'unknown',
                'serial_number' => $serialNumber,
            ],
        ];
    }

    /**
     * Parse raw ADMS ATTLOG tab-separated body into structured attendance records
     * Format per line: PIN \t Time \t Status \t Verify \t WorkCode \t Reserved1 \t Reserved2
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseAttLogBody(string $rawBody, string $serialNumber = ''): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawBody)) ?: [];
        $records = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Strip optional "ATTLOG " prefix if present
            if (str_starts_with($line, 'ATTLOG ')) {
                $line = trim(substr($line, 7));
            }

            $parts = preg_split('/\t+/', $line) ?: [];
            if (count($parts) < 2) {
                continue;
            }

            $userId = trim($parts[0]);
            $timestamp = trim($parts[1]);
            $state = isset($parts[2]) ? (int)$parts[2] : 0;
            $verifyType = isset($parts[3]) ? (int)$parts[3] : 1;
            $workCode = isset($parts[4]) ? (int)$parts[4] : 0;

            $records[] = [
                'uid'           => (int)(is_numeric($userId) ? $userId : 0),
                'user_id'       => $userId,
                'state'         => $state,
                'state_label'   => Constants::getStateLabel($state),
                'verify_type'   => $verifyType,
                'verify_label'  => Constants::getVerifyLabel($verifyType),
                'timestamp'     => $timestamp,
                'work_code'     => $workCode,
                'serial_number' => $serialNumber,
            ];
        }

        return $records;
    }

    /**
     * Parse raw ADMS OPERLOG body (e.g. USER PIN=101\tName=Ali\tPri=0...)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseOperLogBody(string $rawBody, string $serialNumber = ''): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawBody)) ?: [];
        $operations = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'USER ')) {
                $kvPart = substr($line, 5);
                $fields = [];
                foreach (explode("\t", $kvPart) as $pair) {
                    if (str_contains($pair, '=')) {
                        [$k, $v] = explode('=', $pair, 2);
                        $fields[trim($k)] = trim($v);
                    }
                }
                $operations[] = [
                    'operation'     => 'USER',
                    'user_id'       => $fields['PIN'] ?? '',
                    'name'          => $fields['Name'] ?? '',
                    'role'          => (int)($fields['Pri'] ?? 0),
                    'password'      => $fields['Passwd'] ?? '',
                    'card'          => $fields['Card'] ?? '',
                    'serial_number' => $serialNumber,
                ];
            } else {
                $operations[] = [
                    'operation'     => 'RAW',
                    'raw'           => $line,
                    'serial_number' => $serialNumber,
                ];
            }
        }

        return $operations;
    }

    // =========================================================================
    // Server -> Device Push Command Queue (ADMS /iclock/getrequest)
    // =========================================================================

    /**
     * Queue a raw ADMS command to be pushed to the device on its next /iclock/getrequest poll
     */
    public function queueCommand(string $serialNumber, string $commandString, ?int $cmdId = null): string
    {
        $cmdId = $cmdId ?? random_int(1000, 999999);
        $formatted = "C:{$cmdId}:{$commandString}";

        if ($this->commandQueueFile !== null) {
            $queue = $this->readQueueFile();
            $queue[$serialNumber][] = $formatted;
            @file_put_contents($this->commandQueueFile, json_encode($queue, JSON_PRETTY_PRINT), LOCK_EX);
        }

        return $formatted;
    }

    /**
     * Queue a user creation/update command (POST user to device via ADMS)
     */
    public function queueUpdateUser(
        string $serialNumber,
        string $userId,
        string $name,
        int $role = Constants::LEVEL_USER,
        string $password = '',
        string $cardNumber = ''
    ): string {
        $cmd = "DATA UPDATE USERINFO PIN={$userId}\tName={$name}\tPri={$role}\tPasswd={$password}\tCard={$cardNumber}";
        return $this->queueCommand($serialNumber, $cmd);
    }

    /**
     * Queue a user deletion command
     */
    public function queueDeleteUser(string $serialNumber, string $userId): string
    {
        return $this->queueCommand($serialNumber, "DATA DELETE USERINFO PIN={$userId}");
    }

    /**
     * Queue a door unlock command
     */
    public function queueUnlockDoor(string $serialNumber): string
    {
        return $this->queueCommand($serialNumber, "AC_UNLOCK");
    }

    /**
     * Queue a device reboot command
     */
    public function queueReboot(string $serialNumber): string
    {
        return $this->queueCommand($serialNumber, "REBOOT");
    }

    /**
     * Queue an attendance log clear command
     */
    public function queueClearAttendance(string $serialNumber): string
    {
        return $this->queueCommand($serialNumber, "CLEAR LOG");
    }

    /**
     * Pop all pending commands for a specific device serial number
     *
     * @return array<int, string>
     */
    public function popQueuedCommands(string $serialNumber): array
    {
        if ($this->commandQueueFile === null || !is_file($this->commandQueueFile)) {
            return [];
        }

        $queue = $this->readQueueFile();
        $commands = $queue[$serialNumber] ?? [];

        if (!empty($commands)) {
            unset($queue[$serialNumber]);
            @file_put_contents($this->commandQueueFile, json_encode($queue, JSON_PRETTY_PRINT), LOCK_EX);
        }

        return $commands;
    }

    private function readQueueFile(): array
    {
        if ($this->commandQueueFile === null || !is_file($this->commandQueueFile)) {
            return [];
        }
        $raw = (string)@file_get_contents($this->commandQueueFile);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
