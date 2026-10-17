<?php
/**
 * ZKTeco Standalone Pure PHP Socket, Push & Webhook Library
 *
 * Modern, high-performance PHP 8.x biometric communication client for ZKTeco devices.
 * Features:
 *  - Dual-Protocol Transport Engine (TCP + UDP + Auto-Fallback)
 *  - 100% Native Reverse-Engineered Comm Key Authentication (CMD_AUTH 1102)
 *  - Outbound HTTP POST Attendance & User Cloud Sync (with batching & incremental state)
 *  - Real-Time Live Punch Listener (CMD_REG_EVENT) & Signed Webhook Dispatcher
 *  - Bi-Directional User, RFID Card, Fingerprint Template & Access Control Management
 *
 * @package    TahaRafi\ZKTeco
 * @author     Taha Rafi <connect@taharafi.com>
 * @license    MIT
 * @version    2.0.0
 * @link       https://github.com/taha-rafi/php-zkteco
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco;

class ZKTeco
{
    public ?string $ip = null;
    public ?int $port = 4370;
    public $socket = null;
    public int $sessionId = 0;
    public int $replyId = 65534;
    public string $receivedData = '';
    public array $userData = [];
    public array $attendanceData = [];
    public int $timeoutSec = 5;
    public int $timeoutUsec = 0;
    public int $commKey = 0;

    /** @var string Requested transport mode: 'auto', 'tcp', or 'udp' */
    public string $protocol = Constants::PROTOCOL_AUTO;

    /** @var string|null Currently active transport protocol ('tcp' or 'udp') */
    private ?string $activeProtocol = null;

    private bool $isConnected = false;
    private ?WebhookDispatcher $webhookDispatcher = null;
    private ?string $manualPunchesFile = null;

    /**
     * ZKTeco Client Constructor
     *
     * @param string|null $ip Device IP Address
     * @param int|null $port Device Port (default: 4370)
     * @param int|null $commKey Security Comm Key / Password (default: 0)
     * @param string $protocol Transport protocol: 'auto' (TCP with UDP fallback), 'tcp', or 'udp'
     * @param int $timeoutSec Socket timeout in seconds (default: 5)
     */
    public function __construct(
        ?string $ip = null,
        ?int $port = 4370,
        ?int $commKey = 0,
        string $protocol = Constants::PROTOCOL_AUTO,
        int $timeoutSec = 5
    ) {
        if ($ip !== null) {
            $this->ip = $ip;
        }
        if ($port !== null) {
            $this->port = (int)$port;
        }
        if ($commKey !== null) {
            $this->commKey = (int)$commKey;
        }
        $this->timeoutSec = max(1, $timeoutSec);
        $this->setProtocol($protocol);
    }

    /**
     * Clean up socket resources on destruction
     */
    public function __destruct()
    {
        $this->disconnect();
        $this->receivedData = '';
        $this->userData = [];
        $this->attendanceData = [];
    }

    // =========================================================================
    // Protocol Configuration & Transport Layer (TCP + UDP + Auto-Fallback)
    // =========================================================================

    /**
     * Configure transport protocol ('auto', 'tcp', or 'udp')
     */
    public function setProtocol(string $protocol): self
    {
        $normalized = strtolower(trim($protocol));
        if (!in_array($normalized, [Constants::PROTOCOL_AUTO, Constants::PROTOCOL_TCP, Constants::PROTOCOL_UDP], true)) {
            throw new \InvalidArgumentException("Unsupported protocol '{$protocol}'. Use 'auto', 'tcp', or 'udp'.");
        }
        $this->protocol = $normalized;
        return $this;
    }

    /**
     * Get the currently active protocol ('tcp', 'udp', or null if not connected)
     */
    public function getActiveProtocol(): ?string
    {
        return $this->activeProtocol;
    }

    /**
     * Check if the client currently has an active authenticated session
     */
    public function isConnected(): bool
    {
        return $this->isConnected && $this->socket !== null;
    }

    /**
     * Scramble password and session ID into a 4-byte ZKTeco Comm Key Token
     * Reverse-engineered from ZKTeco commpro.c MakeKey algorithm
     *
     * @param int $key Machine Comm Key
     * @param int $sessionId Active Session ID
     * @param int $ticks Cryptographic tick modifier (default: 50)
     * @return string 4-byte packed binary token
     */
    public function makeCommKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            if (($key & (1 << $i)) != 0) {
                $k = ($k << 1) | 1;
            } else {
                $k = $k << 1;
            }
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;
        $b = pack('V', $k);
        $bytes = array_values(unpack('C4', $b));

        $k0 = $bytes[0] ^ ord('Z');
        $k1 = $bytes[1] ^ ord('K');
        $k2 = $bytes[2] ^ ord('S');
        $k3 = $bytes[3] ^ ord('O');

        $w0 = ($k1 << 8) | $k0;
        $w1 = ($k3 << 8) | $k2;
        $packed_words = pack('v2', $w1, $w0);
        $k_swapped = array_values(unpack('C4', $packed_words));

        $B = 0xFF & $ticks;
        $out0 = $k_swapped[0] ^ $B;
        $out1 = $k_swapped[1] ^ $B;
        $out2 = $B;
        $out3 = $k_swapped[3] ^ $B;

        return pack('C4', $out0, $out1, $out2, $out3);
    }

    /**
     * Connect to ZKTeco Biometric Machine using configured protocol ('auto' tries TCP then UDP)
     *
     * @param string|null $ip Optional IP override
     * @param int|null $port Optional Port override
     * @param int|null $commKey Optional Comm Key override
     * @param string|null $protocol Optional Protocol override ('auto', 'tcp', 'udp')
     * @return bool True if successfully connected and authenticated
     */
    public function connect(
        ?string $ip = null,
        ?int $port = null,
        ?int $commKey = null,
        ?string $protocol = null
    ): bool {
        if ($ip !== null) {
            $this->ip = $ip;
        }
        if ($port !== null) {
            $this->port = (int)$port;
        }
        if ($commKey !== null) {
            $this->commKey = (int)$commKey;
        }
        if ($protocol !== null) {
            $this->setProtocol($protocol);
        }

        if (!$this->ip || !$this->port) {
            return false;
        }

        $protocolsToTry = match ($this->protocol) {
            Constants::PROTOCOL_TCP => [Constants::PROTOCOL_TCP],
            Constants::PROTOCOL_UDP => [Constants::PROTOCOL_UDP],
            default                 => [Constants::PROTOCOL_TCP, Constants::PROTOCOL_UDP],
        };

        foreach ($protocolsToTry as $proto) {
            if ($this->connectWithTransport($proto)) {
                $this->activeProtocol = $proto;
                $this->isConnected = true;
                $this->emitWebhook(WebhookDispatcher::EVENT_DEVICE_CONNECTED, [
                    'protocol'   => $proto,
                    'session_id' => $this->sessionId,
                ]);
                return true;
            }
        }

        $this->isConnected = false;
        $this->activeProtocol = null;
        return false;
    }

    /**
     * Connect with automatic retry attempts and backoff delay
     */
    public function connectWithRetry(int $maxAttempts = 3, int $retryDelayMs = 500): bool
    {
        $maxAttempts = max(1, $maxAttempts);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->connect()) {
                return true;
            }
            if ($attempt < $maxAttempts && $retryDelayMs > 0) {
                usleep($retryDelayMs * 1000 * $attempt);
            }
        }
        return false;
    }

    /**
     * Perform low-level socket initialization, handshake, and Comm Key authentication for a specific transport
     */
    private function connectWithTransport(string $transport): bool
    {
        $this->closeRawSocket();
        $this->sessionId = 0;
        $this->replyId = Constants::USHRT_MAX - 1;
        $this->receivedData = '';
        $this->activeProtocol = $transport;

        if ($transport === Constants::PROTOCOL_TCP) {
            $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$this->socket) {
                return false;
            }
            $this->applySocketTimeouts();
            @socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1);

            if (!@$this->connectTcpSocketWithTimeout($this->ip, $this->port, $this->timeoutSec)) {
                $this->closeRawSocket();
                return false;
            }
        } else {
            $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$this->socket) {
                return false;
            }
            $this->applySocketTimeouts();
        }

        try {
            $buf = $this->createHeader(Constants::CMD_CONNECT, 0, 0, $this->replyId, '');
            if (!$this->sendPacket($buf)) {
                $this->closeRawSocket();
                return false;
            }

            $reply = $this->recvPacket(1024);
            if ($reply === false || strlen($reply) < 8) {
                $this->closeRawSocket();
                return false;
            }

            $this->receivedData = $reply;
            $u = unpack('vcommand/vchecksum/vsession/vreply', substr($this->receivedData, 0, 8));
            $this->sessionId = (int)$u['session'];
            $this->replyId = (int)$u['reply'];
            $cmdCode = (int)$u['command'];

            // Authenticate if machine responds with CMD_ACK_UNAUTH (2005)
            if ($cmdCode === Constants::CMD_ACK_UNAUTH) {
                $authPayload = $this->makeCommKey($this->commKey, $this->sessionId);
                $authPacket = $this->createHeader(Constants::CMD_AUTH, 0, $this->sessionId, $this->replyId, $authPayload);

                if (!$this->sendPacket($authPacket)) {
                    $this->closeRawSocket();
                    return false;
                }

                $authReply = $this->recvPacket(1024);
                if ($authReply === false || strlen($authReply) < 8) {
                    $this->closeRawSocket();
                    return false;
                }
                $this->receivedData = $authReply;
                $uAuth = unpack('vcommand/vchecksum/vsession/vreply', substr($this->receivedData, 0, 8));
                $this->replyId = (int)$uAuth['reply'];
                if ((int)$uAuth['command'] !== Constants::CMD_ACK_OK) {
                    $this->closeRawSocket();
                    return false;
                }
                return true;
            }

            if ($this->checkValid($this->receivedData)) {
                return true;
            }

            $this->closeRawSocket();
            return false;
        } catch (\Throwable $e) {
            $this->closeRawSocket();
            return false;
        }
    }

    /**
     * Non-blocking TCP connect with strict timeout enforcement
     */
    private function connectTcpSocketWithTimeout(string $ip, int $port, int $timeoutSec): bool
    {
        if (!$this->socket) {
            return false;
        }

        @socket_set_nonblock($this->socket);
        $connected = @socket_connect($this->socket, $ip, $port);

        if (!$connected) {
            $err = socket_last_error($this->socket);
            // EINPROGRESS (115), EALREADY (114), WSAEWOULDBLOCK (10035)
            if (!in_array($err, [114, 115, 10035, 10036], true) && defined('SOCKET_EINPROGRESS') && $err !== SOCKET_EINPROGRESS && defined('SOCKET_EWOULDBLOCK') && $err !== SOCKET_EWOULDBLOCK) {
                @socket_set_block($this->socket);
                return false;
            }

            $write = [$this->socket];
            $read = null;
            $except = [$this->socket];
            $ready = @socket_select($read, $write, $except, max(1, $timeoutSec), $this->timeoutUsec);

            if ($ready === false || $ready <= 0 || !empty($except)) {
                @socket_set_block($this->socket);
                return false;
            }

            $soError = @socket_get_option($this->socket, SOL_SOCKET, SO_ERROR);
            if ($soError !== 0) {
                @socket_set_block($this->socket);
                return false;
            }
        }

        @socket_set_block($this->socket);
        $this->applySocketTimeouts();
        return true;
    }

    /**
     * Wrap a ZKTeco frame in an 8-byte TCP header (\x50\x50\x82\x7d + 4-byte length)
     */
    public function wrapTcpPacket(string $zkPacket): string
    {
        return Constants::TCP_MAGIC_HEADER . pack('V', strlen($zkPacket)) . $zkPacket;
    }

    /**
     * Unwrap an 8-byte TCP header from a packet if present
     */
    public function unwrapTcpPacket(string $rawPacket): string
    {
        if (strlen($rawPacket) >= 8 && str_starts_with($rawPacket, Constants::TCP_MAGIC_HEADER)) {
            return substr($rawPacket, 8);
        }
        return $rawPacket;
    }

    /**
     * Send a ZKTeco packet over the active transport (TCP or UDP)
     */
    private function sendPacket(string $zkPacket): bool
    {
        if (!$this->socket || !$this->ip || !$this->port) {
            return false;
        }

        if ($this->activeProtocol === Constants::PROTOCOL_TCP) {
            $tcpFrame = $this->wrapTcpPacket($zkPacket);
            $total = strlen($tcpFrame);
            $sent = 0;
            while ($sent < $total) {
                $n = @socket_write($this->socket, substr($tcpFrame, $sent), $total - $sent);
                if ($n === false || $n === 0) {
                    return false;
                }
                $sent += $n;
            }
            return true;
        }

        $res = @socket_sendto($this->socket, $zkPacket, strlen($zkPacket), 0, $this->ip, $this->port);
        return $res !== false;
    }

    /**
     * Receive a single ZKTeco packet from the active transport (TCP stream or UDP datagram)
     */
    private function recvPacket(int $maxUdpBytes = 1032): string|false
    {
        if (!$this->socket) {
            return false;
        }

        if ($this->activeProtocol === Constants::PROTOCOL_TCP) {
            // Read 8-byte TCP header (\x50\x50\x82\x7d + 4-byte payload length)
            $tcpHeader = $this->readExactTcpBytes(8);
            if ($tcpHeader === false || strlen($tcpHeader) < 8) {
                return false;
            }

            if (substr($tcpHeader, 0, 4) !== Constants::TCP_MAGIC_HEADER) {
                // Fallback if device sent unframed bytes
                $rest = '';
                @socket_recv($this->socket, $rest, $maxUdpBytes, 0);
                return $tcpHeader . $rest;
            }

            $lenUnpack = unpack('Vlen', substr($tcpHeader, 4, 4));
            $payloadLen = (int)($lenUnpack['len'] ?? 0);
            if ($payloadLen <= 0 || $payloadLen > 16 * 1024 * 1024) {
                return false;
            }

            return $this->readExactTcpBytes($payloadLen);
        }

        $buf = '';
        $bytes = @socket_recvfrom($this->socket, $buf, $maxUdpBytes, 0, $this->ip, $this->port);
        if ($bytes === false || $buf === '') {
            return false;
        }

        return $this->unwrapTcpPacket($buf);
    }

    /**
     * Read exact number of bytes from a TCP stream socket
     */
    private function readExactTcpBytes(int $length): string|false
    {
        $buffer = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = '';
            $read = @socket_recv($this->socket, $chunk, $remaining, 0);
            if ($read === false || $read === 0 || $chunk === '') {
                return $buffer !== '' ? $buffer : false;
            }
            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }

    private function closeRawSocket(): void
    {
        if ($this->socket) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Disconnect and terminate session with device
     *
     * @return bool True if cleanly exited
     */
    public function disconnect(): bool
    {
        if (!$this->socket || !$this->ip || !$this->port) {
            $this->isConnected = false;
            return false;
        }

        $command = Constants::CMD_EXIT;
        $replyId = $this->getLastReplyId();
        $buf = $this->createHeader($command, 0, $this->sessionId, $replyId, '');
        $this->sendPacket($buf);

        try {
            $reply = $this->recvPacket(1024);
            if ($reply !== false) {
                $this->receivedData = $reply;
            }
            $valid = $this->checkValid($this->receivedData);
            $this->closeRawSocket();
            $this->isConnected = false;
            $this->emitWebhook(WebhookDispatcher::EVENT_DEVICE_DISCONNECTED, [
                'session_id' => $this->sessionId,
            ]);
            return $valid;
        } catch (\Throwable $e) {
            $this->closeRawSocket();
            $this->isConnected = false;
            return false;
        }
    }

    /**
     * Set socket receive & send timeout
     */
    public function setTimeout(int $sec = 5, int $usec = 0): void
    {
        $this->timeoutSec = $sec;
        $this->timeoutUsec = $usec;
        $this->applySocketTimeouts();
    }

    private function applySocketTimeouts(): void
    {
        if ($this->socket) {
            $timeout = ['sec' => $this->timeoutSec, 'usec' => $this->timeoutUsec];
            @socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
            @socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);
        }
    }

    /**
     * Ping device port (supports TCP fsockopen and UDP handshake probe)
     */
    public function ping(int $timeout = 1): int|string
    {
        $time1 = microtime(true);
        $pfile = @fsockopen($this->ip, $this->port, $errno, $errstr, $timeout);
        if ($pfile) {
            $time2 = microtime(true);
            fclose($pfile);
            return (int)round(($time2 - $time1) * 1000);
        }

        // Fallback UDP probe if TCP port is closed on a UDP-only firmware
        $udpSock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($udpSock) {
            @socket_set_option($udpSock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => max(1, $timeout), 'usec' => 0]);
            $probe = $this->createHeader(Constants::CMD_CONNECT, 0, 0, Constants::USHRT_MAX - 1, '');
            $time1 = microtime(true);
            @socket_sendto($udpSock, $probe, strlen($probe), 0, $this->ip, $this->port);
            $reply = '';
            $ip = $this->ip;
            $port = $this->port;
            $bytes = @socket_recvfrom($udpSock, $reply, 1024, 0, $ip, $port);
            @socket_close($udpSock);
            if ($bytes !== false && strlen($reply) >= 8) {
                $time2 = microtime(true);
                return (int)round(($time2 - $time1) * 1000);
            }
        }

        return 'down';
    }

    private function reverseHex(string $input): string
    {
        $output = '';
        for ($i = strlen($input); $i >= 0; $i--) {
            $output .= substr($input, $i, 2);
            $i--;
        }
        return $output;
    }

    public function encodeTime(string $time): int
    {
        $str = str_replace([":", " "], ["-", "-"], $time);
        $arr = explode("-", $str);
        $year = (int)($arr[0] ?? 2000);
        $month = (int)ltrim((string)($arr[1] ?? 1), '0');
        $day = (int)ltrim((string)($arr[2] ?? 1), '0');
        $hour = (int)ltrim((string)($arr[3] ?? 0), '0');
        $minute = (int)ltrim((string)($arr[4] ?? 0), '0');
        $second = (int)ltrim((string)($arr[5] ?? 0), '0');
        return (($year % 100) * 12 * 31 + (($month - 1) * 31) + $day - 1) * 86400 + ($hour * 60 + $minute) * 60 + $second;
    }

    public function decodeTime(int|float $t): string
    {
        $second = (int)$t % 60;
        $t = (int)floor($t / 60);

        $minute = (int)$t % 60;
        $t = (int)floor($t / 60);

        $hour = (int)$t % 24;
        $t = (int)floor($t / 24);

        $day = (int)$t % 31 + 1;
        $t = (int)floor($t / 31);

        $month = (int)$t % 12 + 1;
        $t = (int)floor($t / 12);

        $year = (int)$t + 2000;

        return sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $minute, $second);
    }

    private function checkSum(array $p): string
    {
        $l = count($p);
        $chksum = 0;
        $i = $l;
        $j = 1;
        while ($i > 1) {
            $u = unpack('S', pack('C2', $p['c' . $j], $p['c' . ($j + 1)]));
            $chksum += $u[1];
            if ($chksum > Constants::USHRT_MAX) {
                $chksum -= Constants::USHRT_MAX;
            }
            $i -= 2;
            $j += 2;
        }
        if ($i) {
            $chksum += $p['c' . count($p)];
        }
        while ($chksum > Constants::USHRT_MAX) {
            $chksum -= Constants::USHRT_MAX;
        }
        if ($chksum > 0) {
            $chksum = -$chksum;
        } else {
            $chksum = abs($chksum);
        }
        $chksum -= 1;
        while ($chksum < 0) {
            $chksum += Constants::USHRT_MAX;
        }
        return pack('S', $chksum);
    }

    public function createHeader(int $command, int $chksum, int $sessionId, int $replyId, string $commandString = ''): string
    {
        $buf = pack('SSSS', $command, $chksum, $sessionId, $replyId) . $commandString;
        $buf = unpack('C' . (8 + strlen($commandString)) . 'c', $buf);
        $u = unpack('S', $this->checkSum($buf));
        if (is_array($u)) {
            $u = reset($u);
        }
        $chksum = (int)$u;
        $replyId += 1;
        if ($replyId >= Constants::USHRT_MAX) {
            $replyId -= Constants::USHRT_MAX;
        }
        return pack('SSSS', $command, $chksum, $sessionId, $replyId) . $commandString;
    }

    private function checkValid(string $reply): bool
    {
        if (strlen($reply) < 4) {
            return false;
        }
        $u = unpack('vcommand', substr($reply, 0, 2));
        $command = (int)$u['command'];
        return ($command === Constants::CMD_ACK_OK || $command === Constants::CMD_ACK_UNAUTH || $command === Constants::CMD_ACK_DATA);
    }

    private function getLastReplyId(): int
    {
        if (strlen($this->receivedData) >= 8) {
            $u = unpack('vcommand/vchecksum/vsession/vreply', substr($this->receivedData, 0, 8));
            return (int)$u['reply'];
        }
        return $this->replyId;
    }

    /**
     * Execute a ZKTeco command over the active transport (TCP or UDP)
     */
    public function execCommand(int $command, string $commandString = '', int $offsetData = 8): string|false
    {
        $replyId = $this->getLastReplyId();
        $buf = $this->createHeader($command, 0, $this->sessionId, $replyId, $commandString);

        if (!$this->sendPacket($buf)) {
            return false;
        }

        try {
            $reply = $this->recvPacket(1032);
            if ($reply === false || strlen($reply) < 8) {
                $this->receivedData = '';
                return false;
            }

            $this->receivedData = $reply;
            $u = unpack('vcommand/vchecksum/vsession/vreply', substr($this->receivedData, 0, 8));
            $this->sessionId = (int)$u['session'];
            $this->replyId = (int)$u['reply'];

            if ((int)$u['command'] === Constants::CMD_ACK_ERROR) {
                return false;
            }

            return substr($this->receivedData, $offsetData);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Stream multi-packet dataset (Users, Attendance, Templates) reliably over TCP or UDP
     */
    private function readDataStream(int $command, string $commandString = ''): string|false
    {
        $replyId = $this->getLastReplyId();
        $buf = $this->createHeader($command, 0, $this->sessionId, $replyId, $commandString);

        if (!$this->sendPacket($buf)) {
            return false;
        }

        $firstReply = $this->recvPacket(65536);
        if ($firstReply === false || strlen($firstReply) < 8) {
            return false;
        }

        $this->receivedData = $firstReply;
        $header = unpack('vcommand/vchecksum/vsession/vreply', substr($firstReply, 0, 8));
        $cmdAck = (int)$header['command'];
        $this->replyId = (int)$header['reply'];

        // Case 1: Single-packet direct data response (CMD_DATA 1501 or CMD_ACK_DATA 2002)
        if ($cmdAck === Constants::CMD_DATA || $cmdAck === Constants::CMD_ACK_DATA) {
            $payload = substr($firstReply, 8);
            // If the first 4 bytes equal the remaining length, strip the 4-byte length prefix
            if (strlen($payload) >= 4) {
                $lenCheck = unpack('Vsize', substr($payload, 0, 4))['size'];
                if ($lenCheck === strlen($payload) - 4 || $lenCheck === strlen($payload)) {
                    return substr($payload, 4);
                }
            }
            return $payload;
        }

        // Case 2: Multi-packet stream initiated with CMD_PREPARE_DATA (1500)
        if ($cmdAck === Constants::CMD_PREPARE_DATA && strlen($firstReply) >= 12) {
            $uSize = unpack('Vsize', substr($firstReply, 8, 4));
            $totalBytes = (int)$uSize['size'];

            if ($totalBytes <= 0) {
                return '';
            }

            $streamChunks = [];
            $receivedBytes = 0;

            while ($receivedBytes < $totalBytes) {
                $chunkPacket = $this->recvPacket(65536);
                if ($chunkPacket === false || strlen($chunkPacket) < 8) {
                    break;
                }

                $chunkHeader = unpack('vcommand/vchecksum/vsession/vreply', substr($chunkPacket, 0, 8));
                $chunkCmd = (int)$chunkHeader['command'];
                $this->replyId = (int)$chunkHeader['reply'];

                if ($chunkCmd === Constants::CMD_ACK_OK) {
                    // Stream finished early with ACK_OK
                    break;
                }

                $chunkPayload = substr($chunkPacket, 8);
                $streamChunks[] = $chunkPayload;
                $receivedBytes += strlen($chunkPayload);
            }

            // Read trailing CMD_ACK_OK (2000) packet
            $finalAck = $this->recvPacket(1024);
            if ($finalAck !== false && strlen($finalAck) >= 8) {
                $ackHeader = unpack('vcommand/vchecksum/vsession/vreply', substr($finalAck, 0, 8));
                $this->replyId = (int)$ackHeader['reply'];
                $this->receivedData = $finalAck;
            }

            $fullStream = implode('', $streamChunks);
            // Strip 4-byte size integer header at the start of the data stream if present
            if (strlen($fullStream) >= 4) {
                return substr($fullStream, 4);
            }
            return $fullStream;
        }

        return '';
    }

    // =========================================================================
    // Device Hardware Controls & Information
    // =========================================================================

    public function restartDevice(): string|false
    {
        return $this->execCommand(Constants::CMD_RESTART, chr(0) . chr(0));
    }

    public function shutdownDevice(): string|false
    {
        return $this->execCommand(Constants::CMD_POWEROFF, chr(0) . chr(0));
    }

    public function sleepDevice(): string|false
    {
        return $this->execCommand(Constants::CMD_SLEEP, chr(0) . chr(0));
    }

    public function resumeDevice(): string|false
    {
        return $this->execCommand(Constants::CMD_RESUME, chr(0) . chr(0));
    }

    public function testVoice(int $voiceIndex = 0): string|false
    {
        return $this->execCommand(Constants::CMD_TESTVOICE, pack('v', $voiceIndex));
    }

    /**
     * Remotely unlock door relay for access-control enabled ZKTeco terminals
     *
     * @param int $delaySeconds Duration in seconds to keep door unlocked (default: 3s)
     */
    public function unlockDoor(int $delaySeconds = 3): string|false
    {
        $res = $this->execCommand(Constants::CMD_UNLOCK, pack('v', max(1, $delaySeconds) * 10));
        if ($res !== false) {
            $this->emitWebhook(WebhookDispatcher::EVENT_DOOR_UNLOCKED, [
                'delay_seconds' => $delaySeconds,
            ]);
        }
        return $res;
    }

    public function enableDevice(): string|false
    {
        return $this->execCommand(Constants::CMD_ENABLEDEVICE);
    }

    public function disableDevice(): string|false
    {
        return $this->execCommand(Constants::CMD_DISABLEDEVICE, chr(0) . chr(0));
    }

    public function clearLCD(): string|false
    {
        return $this->execCommand(Constants::CMD_CLEAR_LCD);
    }

    public function writeLCD(int $rank, string $text): string|false
    {
        $byte1 = chr($rank % 256);
        $byte2 = chr((int)($rank >> 8));
        $byte3 = chr(0);
        return $this->execCommand(Constants::CMD_WRITE_LCD, $byte1 . $byte2 . $byte3 . ' ' . $text);
    }

    public function getVersion(): string|false
    {
        $res = $this->execCommand(Constants::CMD_VERSION);
        return $res !== false ? trim(explode("\0", $res, 2)[0]) : false;
    }

    public function getSerialNumber(): string|false
    {
        return $this->getDeviceOption('~SerialNumber');
    }

    public function getDeviceName(): string|false
    {
        return $this->getDeviceOption('~DeviceName');
    }

    public function getOSVersion(): string|false
    {
        return $this->getDeviceOption('~OS');
    }

    public function getPlatform(): string|false
    {
        return $this->getDeviceOption('~Platform');
    }

    public function getFirmwareVersion(): string|false
    {
        return $this->getDeviceOption('~ZKFPVersion');
    }

    public function getPinWidth(): string|false
    {
        return $this->getDeviceOption('~PIN2Width');
    }

    /**
     * Read any option key from the device (CMD_OPTIONS_RRQ)
     */
    public function getDeviceOption(string $optionKey): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, $optionKey . "\0");
        if ($return !== false && $return !== '') {
            $clean = trim(explode("\0", (string)$return, 2)[0]);
            $arr = explode('=', $clean, 2);
            return trim($arr[1] ?? $clean);
        }
        return false;
    }

    /**
     * Write/Push a configuration option key=value to the device (CMD_OPTIONS_WRQ)
     */
    public function setDeviceOption(string $optionKey, string $value): string|false
    {
        return $this->execCommand(Constants::CMD_OPTIONS_WRQ, "{$optionKey}={$value}\0");
    }

    public function getTime(): string|false
    {
        $raw = $this->execCommand(Constants::CMD_GET_TIME);
        if ($raw !== false && strlen($raw) >= 4) {
            $u = unpack('Vtime', substr($raw, 0, 4));
            return $this->decodeTime((int)$u['time']);
        }
        return false;
    }

    public function setTime(string $timestamp): string|false
    {
        $commandString = pack('I', $this->encodeTime($timestamp));
        return $this->execCommand(Constants::CMD_SET_TIME, $commandString);
    }

    // =========================================================================
    // User, RFID Card & Fingerprint Template Management (GET & POST/PUSH)
    // =========================================================================

    /**
     * Retrieve all enrolled users from the device (Backward-compatible indexed format)
     *
     * @return array<int, array{0: string, 1: string, 2: int, 3: string, 4?: int}>|false
     *         [uid => [userId, name, role, password, cardNo]]
     */
    public function getUser(): array|false
    {
        try {
            $userDataStr = $this->readDataStream(Constants::CMD_USERTEMP_RRQ, chr(5));
            if ($userDataStr === false) {
                return false;
            }

            $users = [];
            $len = strlen($userDataStr);
            if ($len === 0) {
                return [];
            }

            // Detect record size: 72-byte (SSR / TFT) or 28-byte (legacy)
            $recordSize = ($len % 72 === 0 || $len >= 72) ? 72 : 28;

            while (strlen($userDataStr) >= $recordSize) {
                $rec = substr($userDataStr, 0, $recordSize);
                $userDataStr = substr($userDataStr, $recordSize);

                if ($recordSize === 72) {
                    $u = unpack('vuid/Crole/a8password/a24name/Vcardno/Cpad/a8group/a24userid', $rec);
                    $uid = (int)$u['uid'];
                    $role = (int)$u['role'];
                    $password = trim(explode("\0", (string)$u['password'], 2)[0]);
                    $name = trim(explode("\0", (string)$u['name'], 2)[0]);
                    $cardNo = (int)$u['cardno'];
                    $userid = trim(explode("\0", (string)$u['userid'], 2)[0]);
                } else {
                    $u = unpack('vuid/Crole/a5password/a8name/Vcardno/Cpad/Cgroup/vtimezone/Vuserid', $rec);
                    $uid = (int)$u['uid'];
                    $role = (int)$u['role'];
                    $password = trim(explode("\0", (string)$u['password'], 2)[0]);
                    $name = trim(explode("\0", (string)$u['name'], 2)[0]);
                    $cardNo = (int)$u['cardno'];
                    $userid = (string)$u['userid'];
                }

                if ($name === '') {
                    $name = (string)$uid;
                }
                if ($userid === '') {
                    $userid = (string)$uid;
                }

                $users[$uid] = [$userid, $name, $role, $password, $cardNo];
            }

            return $users;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Alias for getUser()
     */
    public function getUsers(): array|false
    {
        return $this->getUser();
    }

    /**
     * Retrieve all enrolled users as clean associative arrays
     *
     * @return array<int, array{uid: int, user_id: string, name: string, role: int, password: string, card_no: int}>|false
     */
    public function getUsersStructured(): array|false
    {
        $rawUsers = $this->getUser();
        if ($rawUsers === false) {
            return false;
        }

        $structured = [];
        foreach ($rawUsers as $uid => $u) {
            $structured[] = [
                'uid'      => (int)$uid,
                'user_id'  => (string)$u[0],
                'name'     => (string)$u[1],
                'role'     => (int)$u[2],
                'password' => (string)$u[3],
                'card_no'  => (int)($u[4] ?? 0),
            ];
        }

        return $structured;
    }

    /**
     * Add or update user profile on the device (supports RFID card number)
     */
    public function setUser(
        int $uid,
        string $userid,
        string $name,
        string $password = '',
        int $role = Constants::LEVEL_USER,
        int $cardNo = 0
    ): string|false {
        $name = substr($name, 0, 24);
        $command = Constants::CMD_USER_WRQ;
        $byte1 = chr($uid % 256);
        $byte2 = chr((int)($uid >> 8));
        $cardBytes = pack('V', $cardNo);

        $commandString = $byte1
            . $byte2
            . chr($role)
            . str_pad(substr($password, 0, 8), 8, chr(0))
            . str_pad($name, 24, chr(0))
            . $cardBytes
            . chr(1)
            . str_pad('', 8, chr(0))
            . str_pad(substr($userid, 0, 24), 24, chr(0));

        $res = $this->execCommand($command, $commandString);
        if ($res !== false) {
            $this->emitWebhook(WebhookDispatcher::EVENT_USER_CREATED, [
                'uid'     => $uid,
                'user_id' => $userid,
                'name'    => $name,
                'role'    => $role,
                'card_no' => $cardNo,
            ]);
        }
        return $res;
    }

    /**
     * Bulk push/sync multiple users to the ZKTeco device in a single call
     *
     * @param array<int, array{uid: int, user_id: string, name: string, password?: string, role?: int, card_no?: int}> $users
     * @return array{total: int, succeeded: int, failed: int}
     */
    public function pushUsers(array $users): array
    {
        $succeeded = 0;
        $failed = 0;

        $this->disableDevice();
        try {
            foreach ($users as $user) {
                $uid = (int)($user['uid'] ?? 0);
                $userId = (string)($user['user_id'] ?? $user['userid'] ?? $uid);
                $name = (string)($user['name'] ?? $userId);
                $password = (string)($user['password'] ?? '');
                $role = (int)($user['role'] ?? Constants::LEVEL_USER);
                $cardNo = (int)($user['card_no'] ?? $user['cardno'] ?? 0);

                if ($uid <= 0) {
                    $failed++;
                    continue;
                }

                $ok = $this->setUser($uid, $userId, $name, $password, $role, $cardNo);
                if ($ok !== false) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }
        } finally {
            $this->enableDevice();
        }

        return [
            'total'     => count($users),
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ];
    }

    /**
     * Delete user from the biometric machine
     */
    public function deleteUser(int $uid): string|false
    {
        $byte1 = chr($uid % 256);
        $byte2 = chr((int)($uid >> 8));
        $res = $this->execCommand(Constants::CMD_DELETE_USER, $byte1 . $byte2);
        if ($res !== false) {
            $this->emitWebhook(WebhookDispatcher::EVENT_USER_DELETED, ['uid' => $uid]);
        }
        return $res;
    }

    /**
     * Delete a specific fingerprint template for a user (CMD_DELETE_USERTEMP)
     */
    public function deleteUserTemplate(int $uid, int $fingerId): string|false
    {
        return $this->execCommand(Constants::CMD_DELETE_USERTEMP, pack('vC', $uid, $fingerId));
    }

    public function clearAdmin(): string|false
    {
        return $this->execCommand(Constants::CMD_CLEAR_ADMIN);
    }

    /**
     * Trigger biometric verification mode on the physical ZKTeco machine (CMD_STARTVERIFY)
     *
     * @param int|null $uid Optional internal UID to prompt verification for a specific user
     */
    public function startVerify(?int $uid = null): bool
    {
        $payload = $uid !== null ? pack('v', $uid) : '';
        return $this->execCommand(Constants::CMD_STARTVERIFY, $payload) !== false;
    }

    /**
     * Remotely trigger fingerprint enrollment on the ZKTeco sensor for a user (CMD_STARTENROLL)
     *
     * @param string|int $userId Employee User ID string (up to 24 chars)
     * @param int $fingerId Finger index (0-9)
     * @param int $flag Overwrite/enrollment flag (default: 1)
     */
    public function startEnroll(string|int $userId, int $fingerId = 0, int $flag = 1): bool
    {
        $this->cancelCapture();
        $payload = str_pad(substr((string)$userId, 0, 24), 24, "\0") . pack('CC', $fingerId, $flag);
        return $this->execCommand(Constants::CMD_STARTENROLL, $payload) !== false;
    }

    /**
     * Cancel active capture/enrollment state on the device (CMD_CANCELCAPTURE)
     */
    public function cancelCapture(): bool
    {
        return $this->execCommand(Constants::CMD_CANCELCAPTURE) !== false;
    }

    // =========================================================================
    // Attendance Marking (POST Punch), Streaming (GET) & Cloud Sync (POST)
    // =========================================================================

    /**
     * Configure a local JSON file to persist manual/remote attendance punches
     * so they are automatically merged with hardware punches when calling getAttendance().
     */
    public function setManualPunchesFile(string $filePath): self
    {
        $this->manualPunchesFile = $filePath;
        return $this;
    }

    /**
     * Get all stored manual/remote punches from the configured manual punches file
     *
     * @return array<int, array{uid: int, user_id: string, state: int, state_label: string, verify_type: int, verify_label: string, timestamp: string, source: string}>
     */
    public function getManualPunches(?string $filePath = null): array
    {
        $targetFile = $filePath ?? $this->manualPunchesFile;
        if ($targetFile === null || !is_file($targetFile)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($targetFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Refresh the ZKTeco machine's internal database cache and UI (CMD_REFRESHDATA = 1013)
     */
    public function refreshData(): bool
    {
        return $this->execCommand(Constants::CMD_REFRESHDATA) !== false;
    }

    /**
     * Write an attendance punch record directly into the physical ZKTeco machine's internal ATT_LOG database!
     *
     * Uses reverse-engineered native ZKTeco command CMD_SET_DATA (3001) on ZMM/New-Architecture SQLite
     * devices (e.g. TX628, ZMM200_TFT, ZMM220, uFace, SpeedFace, iClock) with automatic CMD_REFRESHDATA (1013).
     *
     * @param string|int $userId Employee User ID / PIN2 (e.g. '1' or '1001')
     * @param int $state Punch state (Constants::ATT_STATE_CHECK_IN = 0, ATT_STATE_CHECK_OUT = 1, etc.)
     * @param string|null $timestamp Optional 'YYYY-MM-DD HH:MM:SS' (defaults to current time)
     * @param int $verifyType Verification method (default: Constants::VERIFY_FINGERPRINT = 1)
     * @param int $workCode Optional work code ID (default: 0)
     * @return bool True if the physical ZKTeco machine accepted and stored the attendance record
     */
    public function writeAttendanceToDevice(
        string|int $userId,
        int $state = Constants::ATT_STATE_CHECK_IN,
        ?string $timestamp = null,
        int $verifyType = Constants::VERIFY_FINGERPRINT,
        int $workCode = 0
    ): bool {
        if (!$this->isConnected()) {
            return false;
        }

        $userIdStr = trim((string)$userId);
        $ts = $timestamp !== null && trim($timestamp) !== ''
            ? trim($timestamp)
            : date('Y-m-d H:i:s');

        // Normalize 'YYYY-MM-DD HH:MM:SS' to ZKTeco ATT_LOG ISO format 'YYYY-MM-DDTHH:MM:SS'
        $isoTime = str_replace(' ', 'T', $ts);

        $payload = "TableName=ATT_LOG\r\n"
            . "User_PIN={$userIdStr}\tVerify_Type={$verifyType}\tVerify_Time={$isoTime}\tStatus={$state}\tWork_Code_ID={$workCode}\0";

        $res = $this->execCommand(Constants::CMD_SET_DATA, $payload);
        if ($res !== false && strlen($this->receivedData) >= 8) {
            $hdr = unpack('vcmd', substr($this->receivedData, 0, 2));
            if ((int)$hdr['cmd'] === Constants::CMD_ACK_OK) {
                $this->refreshData();
                return true;
            }
        }

        return false;
    }

    /**
     * Bulk write/push multiple attendance records directly into the physical ZKTeco machine's internal ATT_LOG!
     *
     * @param array<int, array{user_id: string|int, state?: int, timestamp?: string, verify_type?: int, work_code?: int}> $records
     * @return array{total: int, succeeded: int, failed: int}
     */
    public function pushAttendance(array $records): array
    {
        $succeeded = 0;
        $failed = 0;

        if (!$this->isConnected()) {
            return ['total' => count($records), 'succeeded' => 0, 'failed' => count($records)];
        }

        $this->disableDevice();
        try {
            foreach ($records as $rec) {
                $userId = $rec['user_id'] ?? $rec['userid'] ?? '';
                if ((string)$userId === '') {
                    $failed++;
                    continue;
                }
                $state = (int)($rec['state'] ?? Constants::ATT_STATE_CHECK_IN);
                $timestamp = isset($rec['timestamp']) ? (string)$rec['timestamp'] : date('Y-m-d H:i:s');
                $verifyType = (int)($rec['verify_type'] ?? Constants::VERIFY_FINGERPRINT);
                $workCode = (int)($rec['work_code'] ?? 0);

                $isoTime = str_replace(' ', 'T', trim($timestamp));
                $payload = "TableName=ATT_LOG\r\n"
                    . "User_PIN={$userId}\tVerify_Type={$verifyType}\tVerify_Time={$isoTime}\tStatus={$state}\tWork_Code_ID={$workCode}\0";

                $res = $this->execCommand(Constants::CMD_SET_DATA, $payload);
                $ok = false;
                if ($res !== false && strlen($this->receivedData) >= 8) {
                    $hdr = unpack('vcmd', substr($this->receivedData, 0, 2));
                    $ok = ((int)$hdr['cmd'] === Constants::CMD_ACK_OK);
                }

                if ($ok) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }
            if ($succeeded > 0) {
                $this->refreshData();
            }
        } finally {
            $this->enableDevice();
        }

        return [
            'total'     => count($records),
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ];
    }

    /**
     * Mark / Create an Attendance Punch (Check-In / Check-Out) for an Employee
     *
     * Supports:
     *  1. Direct Hardware ATT_LOG Write into the physical ZKTeco machine (CMD_SET_DATA 3001 + CMD_REFRESHDATA 1013)!
     *  2. Live Hardware LCD & Voice Prompt feedback on the ZKTeco machine if connected
     *  3. Instant Webhook Dispatch ('attendance.punch') to all registered webhooks
     *  4. Direct HTTP POST to a remote API if $options['post_url'] is provided
     *  5. Local JSON persistence (via $options['storage_file'] or setManualPunchesFile())
     *
     * @param string|int $userId Employee User ID (e.g. '1' or '1001')
     * @param int $state Punch state (Constants::ATT_STATE_CHECK_IN = 0, ATT_STATE_CHECK_OUT = 1, etc.)
     * @param string|null $timestamp Optional 'YYYY-MM-DD HH:MM:SS' (defaults to current time)
     * @param int $verifyType Verification method (default: Constants::VERIFY_FINGERPRINT = 1)
     * @param array<string, mixed> $options ['uid' => int, 'write_to_device' => bool, 'work_code' => int, 'storage_file' => ?string, 'post_url' => ?string, 'token' => ?string, 'secret' => ?string, 'device_feedback' => bool]
     * @return array<string, mixed> Created punch record and hardware/webhook/API delivery status
     */
    public function markAttendance(
        string|int $userId,
        int $state = Constants::ATT_STATE_CHECK_IN,
        ?string $timestamp = null,
        int $verifyType = Constants::VERIFY_FINGERPRINT,
        array $options = []
    ): array {
        $userIdStr = trim((string)$userId);
        $timestampStr = $timestamp !== null && trim($timestamp) !== ''
            ? str_replace('T', ' ', trim($timestamp))
            : date('Y-m-d H:i:s');
        $uid = (int)($options['uid'] ?? (is_numeric($userIdStr) ? $userIdStr : 0));
        $workCode = (int)($options['work_code'] ?? 0);

        // 1. Write directly into the physical ZKTeco machine's internal ATT_LOG database if connected!
        $deviceWritten = false;
        if ($this->isConnected() && ($options['write_to_device'] ?? true)) {
            $deviceWritten = $this->writeAttendanceToDevice($userIdStr, $state, $timestampStr, $verifyType, $workCode);
        }

        $punchRecord = [
            'uid'            => $uid,
            'user_id'        => $userIdStr,
            'state'          => $state,
            'state_label'    => Constants::getStateLabel($state),
            'verify_type'    => $verifyType,
            'verify_label'   => Constants::getVerifyLabel($verifyType),
            'timestamp'      => $timestampStr,
            'source'         => (string)($options['source'] ?? ($deviceWritten ? 'hardware_write' : 'manual_punch')),
            'device_written' => $deviceWritten,
        ];

        // 2. Persist to manual punches storage file if configured
        $storageFile = isset($options['storage_file']) ? (string)$options['storage_file'] : $this->manualPunchesFile;
        if ($storageFile !== null && $storageFile !== '') {
            $existing = $this->getManualPunches($storageFile);
            if ($uid <= 0) {
                $punchRecord['uid'] = count($existing) + 1;
            }
            $existing[] = $punchRecord;
            $dir = dirname($storageFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents($storageFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        // 3. Trigger physical machine LCD & Voice confirmation if connected and enabled
        $deviceFeedbackTriggered = false;
        if ($this->isConnected() && ($options['device_feedback'] ?? true)) {
            $lcdText = substr("ID:{$userIdStr} {$punchRecord['state_label']}", 0, 16);
            $this->writeLCD(0, $lcdText);
            $this->testVoice(0); // Voice 0: "Thank You"
            $deviceFeedbackTriggered = true;
        }

        // 4. Emit 'attendance.punch' event to all registered Webhooks & local listeners
        $webhookResults = [];
        if ($this->webhookDispatcher !== null) {
            $webhookResults = $this->webhookDispatcher->dispatch(
                WebhookDispatcher::EVENT_ATTENDANCE_PUNCH,
                $punchRecord,
                $this->getDeviceContextMeta()
            );
        }

        // 5. Optional direct HTTP POST to an API endpoint
        $postDelivery = null;
        if (!empty($options['post_url'])) {
            $postDelivery = $this->getWebhookDispatcher()->sendPost(
                (string)$options['post_url'],
                [
                    'event'     => WebhookDispatcher::EVENT_ATTENDANCE_PUNCH,
                    'synced_at' => date('c'),
                    'device'    => $this->getDeviceContextMeta(),
                    'punch'     => $punchRecord,
                ],
                array_merge($options, ['event' => WebhookDispatcher::EVENT_ATTENDANCE_PUNCH])
            );
        }

        return [
            'success'         => true,
            'punch'           => $punchRecord,
            'device_written'  => $deviceWritten,
            'device_feedback' => $deviceFeedbackTriggered,
            'webhooks'        => $webhookResults,
            'post_delivery'   => $postDelivery,
        ];
    }

    /**
     * Pull all attendance records from biometric storage (Backward-compatible indexed format)
     *
     * @param bool $includeManual Automatically merge offline punches saved via markAttendance()
     * @return array<int, array{0: int, 1: string, 2: int, 3: string, 4: int}>|false
     *         Array of [uid, userid, state, timestamp, verifyType]
     */
    public function getAttendance(bool $includeManual = true): array|false
    {
        try {
            $stream = $this->readDataStream(Constants::CMD_ATTLOG_RRQ, '');
            if ($stream === false) {
                return false;
            }

            $attendance = [];
            $seenKeys = [];
            $len = strlen($stream);

            if ($len > 0) {
                // Detect 40-byte SSR record vs 16-byte compact record
                $recordSize = ($len % 40 === 0 || $len >= 40) ? 40 : 16;

                while (strlen($stream) >= $recordSize) {
                    $rec = substr($stream, 0, $recordSize);
                    $stream = substr($stream, $recordSize);

                    if ($recordSize === 40) {
                        // 40-byte format: uid(2) + userid(24) + verifyType(1) + time(4) + state/punch(1) + space(8)
                        $u = unpack('vuid/a24userid/Cverify/Vtime/Cstate/a8space', $rec);
                        $uid = (int)$u['uid'];
                        $rawUserId = explode("\0", (string)$u['userid'], 2)[0];
                        $userId = trim((string)preg_replace('/[^\x20-\x7E]/', '', $rawUserId));
                        $verifyType = (int)$u['verify'];
                        $rawState = (int)$u['state'];
                        // On TX628/ZMM200_TFT, 255 (0xFF) represents default/auto Check-In (0)
                        $state = ($rawState === 255) ? Constants::ATT_STATE_CHECK_IN : $rawState;
                        $timestamp = $this->decodeTime((int)$u['time']);
                    } else {
                        // 16-byte compact format
                        $u = unpack('vuid/Vuserid/Cverify/Vtime/Cstate/a4space', $rec);
                        $uid = (int)$u['uid'];
                        $userId = (string)$u['userid'];
                        $verifyType = (int)$u['verify'];
                        $rawState = (int)$u['state'];
                        $state = ($rawState === 255) ? Constants::ATT_STATE_CHECK_IN : $rawState;
                        $timestamp = $this->decodeTime((int)$u['time']);
                    }

                    // Index 0..3 preserved for 100% backward compatibility; index 4 adds verifyType
                    $attendance[] = [$uid, $userId, $state, $timestamp, $verifyType];
                    $seenKeys[$userId . '|' . $timestamp] = true;
                }
            }

            if ($includeManual && $this->manualPunchesFile !== null) {
                foreach ($this->getManualPunches() as $mp) {
                    $mpUserId = (string)($mp['user_id'] ?? '');
                    $mpTs = (string)($mp['timestamp'] ?? '');
                    if (isset($seenKeys[$mpUserId . '|' . $mpTs])) {
                        continue;
                    }
                    $attendance[] = [
                        (int)($mp['uid'] ?? 0),
                        $mpUserId,
                        (int)($mp['state'] ?? Constants::ATT_STATE_CHECK_IN),
                        $mpTs,
                        (int)($mp['verify_type'] ?? Constants::VERIFY_PASSWORD),
                    ];
                }
            }

            return $attendance;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pull attendance records as structured associative arrays with optional timestamp filter
     *
     * @param string|null $since Optional 'YYYY-MM-DD HH:MM:SS' filter (only returns records strictly after $since)
     * @param bool $includeManual Automatically merge punches saved via markAttendance()
     * @return array<int, array{uid: int, user_id: string, state: int, state_label: string, verify_type: int, verify_label: string, timestamp: string}>|false
     */
    public function getAttendanceStructured(?string $since = null, bool $includeManual = true): array|false
    {
        $raw = $this->getAttendance($includeManual);
        if ($raw === false) {
            return false;
        }

        $sinceTs = $since !== null ? strtotime($since) : null;
        $records = [];

        foreach ($raw as $row) {
            $timestamp = (string)$row[3];
            if ($sinceTs !== null && $sinceTs !== false) {
                $rowTs = strtotime($timestamp);
                if ($rowTs !== false && $rowTs <= $sinceTs) {
                    continue;
                }
            }

            $state = (int)$row[2];
            $verifyType = (int)($row[4] ?? Constants::VERIFY_FINGERPRINT);

            $records[] = [
                'uid'          => (int)$row[0],
                'user_id'      => (string)$row[1],
                'state'        => $state,
                'state_label'  => Constants::getStateLabel($state),
                'verify_type'  => $verifyType,
                'verify_label' => Constants::getVerifyLabel($verifyType),
                'timestamp'    => $timestamp,
            ];
        }

        return $records;
    }

    /**
     * Pull attendance from the ZKTeco device and POST it directly to an external API / HRMS / Server
     *
     * Supported $options:
     *  - 'since'            => ?string ('YYYY-MM-DD HH:MM:SS' filter)
     *  - 'state_file'       => ?string (Path to JSON cursor file so only NEW logs are POSTed on each run)
     *  - 'batch_size'       => int     (Number of records per HTTP POST request, default: 500)
     *  - 'token'            => ?string (Bearer token for Authorization header)
     *  - 'secret'           => ?string (HMAC-SHA256 secret for X-ZKTeco-Signature header)
     *  - 'headers'          => array   (Custom HTTP headers)
     *  - 'retries'          => int     (Retry attempts per batch, default: 3)
     *  - 'timeout'          => int     (HTTP timeout in seconds, default: 15)
     *  - 'clear_after_post' => bool    (Wipe device attendance ONLY if all POST batches succeed)
     *  - 'records'          => ?array  (Optional pre-fetched structured records)
     *
     * @return array{success: bool, total_records: int, batches_sent: int, batches_succeeded: int, latest_timestamp: ?string, deliveries: array}
     */
    public function postAttendance(string $url, array $options = []): array
    {
        $stateFile = isset($options['state_file']) ? (string)$options['state_file'] : null;
        $since = isset($options['since']) ? (string)$options['since'] : null;
        $deviceKey = ($this->ip ?? 'device') . ':' . ($this->port ?? 4370);

        if ($since === null && $stateFile !== null && is_file($stateFile)) {
            $stateData = json_decode((string)@file_get_contents($stateFile), true);
            if (is_array($stateData) && !empty($stateData[$deviceKey]['last_timestamp'])) {
                $since = (string)$stateData[$deviceKey]['last_timestamp'];
            }
        }

        if (isset($options['records']) && is_array($options['records'])) {
            $records = $options['records'];
        } else {
            $this->disableDevice();
            try {
                $records = $this->getAttendanceStructured($since);
            } finally {
                $this->enableDevice();
            }
        }

        if ($records === false) {
            return [
                'success'           => false,
                'total_records'     => 0,
                'batches_sent'      => 0,
                'batches_succeeded' => 0,
                'latest_timestamp'  => $since,
                'deliveries'        => [],
                'error'             => 'Failed to read attendance from device.',
            ];
        }

        $totalRecords = count($records);
        if ($totalRecords === 0) {
            return [
                'success'           => true,
                'total_records'     => 0,
                'batches_sent'      => 0,
                'batches_succeeded' => 0,
                'latest_timestamp'  => $since,
                'deliveries'        => [],
            ];
        }

        $batchSize = max(1, (int)($options['batch_size'] ?? 500));
        $batches = array_chunk($records, $batchSize);
        $batchTotal = count($batches);
        $dispatcher = $this->getWebhookDispatcher();
        $deviceMeta = $this->getDeviceContextMeta();

        $deliveries = [];
        $batchesSucceeded = 0;
        $latestTimestamp = $since;

        foreach ($batches as $idx => $batchRecords) {
            foreach ($batchRecords as $rec) {
                if (!empty($rec['timestamp']) && ($latestTimestamp === null || strcmp($rec['timestamp'], $latestTimestamp) > 0)) {
                    $latestTimestamp = (string)$rec['timestamp'];
                }
            }

            $payload = [
                'event'         => WebhookDispatcher::EVENT_ATTENDANCE_SYNC,
                'synced_at'     => date('c'),
                'device'        => $deviceMeta,
                'batch_index'   => $idx + 1,
                'batch_total'   => $batchTotal,
                'total_records' => count($batchRecords),
                'records'       => $batchRecords,
            ];

            $delivery = $dispatcher->sendPost($url, $payload, array_merge($options, [
                'event' => WebhookDispatcher::EVENT_ATTENDANCE_SYNC,
            ]));

            $deliveries[] = $delivery;
            if ($delivery['success']) {
                $batchesSucceeded++;
            }
        }

        $allSucceeded = ($batchesSucceeded === $batchTotal);

        // Persist incremental sync cursor if all batches succeeded
        if ($allSucceeded && $stateFile !== null && $latestTimestamp !== null) {
            $stateData = is_file($stateFile)
                ? (json_decode((string)@file_get_contents($stateFile), true) ?: [])
                : [];
            $stateData[$deviceKey] = [
                'last_timestamp' => $latestTimestamp,
                'updated_at'     => date('c'),
                'records_synced' => $totalRecords,
            ];
            @file_put_contents($stateFile, json_encode($stateData, JSON_PRETTY_PRINT), LOCK_EX);
        }

        // Optionally clear device logs if requested and all POSTs succeeded
        if ($allSucceeded && !empty($options['clear_after_post'])) {
            $this->clearAttendance();
        }

        return [
            'success'           => $allSucceeded,
            'total_records'     => $totalRecords,
            'batches_sent'      => $batchTotal,
            'batches_succeeded' => $batchesSucceeded,
            'latest_timestamp'  => $latestTimestamp,
            'deliveries'        => $deliveries,
        ];
    }

    /**
     * Pull enrolled users from the device and POST them to an external API / Server
     */
    public function postUsers(string $url, array $options = []): array
    {
        $this->disableDevice();
        try {
            $users = $this->getUsersStructured();
        } finally {
            $this->enableDevice();
        }

        if ($users === false) {
            return [
                'success'     => false,
                'total_users' => 0,
                'delivery'    => null,
                'error'       => 'Failed to read users from device.',
            ];
        }

        $payload = [
            'event'       => WebhookDispatcher::EVENT_USER_SYNC,
            'synced_at'   => date('c'),
            'device'      => $this->getDeviceContextMeta(),
            'total_users' => count($users),
            'users'       => $users,
        ];

        $delivery = $this->getWebhookDispatcher()->sendPost($url, $payload, array_merge($options, [
            'event' => WebhookDispatcher::EVENT_USER_SYNC,
        ]));

        return [
            'success'     => $delivery['success'],
            'total_users' => count($users),
            'delivery'    => $delivery,
        ];
    }

    /**
     * Clear all attendance logs on the device storage
     */
    public function clearAttendance(): string|false
    {
        $res = $this->execCommand(Constants::CMD_CLEAR_ATTLOG);
        if ($res !== false) {
            $this->emitWebhook(WebhookDispatcher::EVENT_ATTENDANCE_CLEAR, [
                'cleared_at' => date('c'),
            ]);
        }
        return $res;
    }

    /**
     * Selectively delete attendance logs from the physical ZKTeco machine within a timestamp range
     * (CMD_TIME_ATTLOG_DELE = 10005) without wiping the rest of the device's attendance history!
     *
     * @param string $startTime 'YYYY-MM-DD HH:MM:SS'
     * @param string $endTime   'YYYY-MM-DD HH:MM:SS'
     * @return bool True if the device acknowledged the selective delete
     */
    public function deleteAttendanceByTimeRange(string $startTime, string $endTime): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $tStart = $this->encodeTime(str_replace('T', ' ', trim($startTime)));
        $tEnd = $this->encodeTime(str_replace('T', ' ', trim($endTime)));
        $res = $this->execCommand(Constants::CMD_TIME_ATTLOG_DELE, pack('VV', $tStart, $tEnd));
        if ($res !== false && strlen($this->receivedData) >= 8) {
            $hdr = unpack('vcmd', substr($this->receivedData, 0, 2));
            if ((int)$hdr['cmd'] === Constants::CMD_ACK_OK) {
                $this->refreshData();
                return true;
            }
        }
        return false;
    }

    /**
     * Selectively delete attendance logs on or before a specific timestamp from the physical ZKTeco machine
     * (CMD_TIMEPOINT_ATTLOG_DELE = 10006)
     *
     * @param string $beforeTime 'YYYY-MM-DD HH:MM:SS'
     * @return bool True if the device acknowledged the selective delete
     */
    public function deleteAttendanceBefore(string $beforeTime): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $tPoint = $this->encodeTime(str_replace('T', ' ', trim($beforeTime)));
        $res = $this->execCommand(Constants::CMD_TIMEPOINT_ATTLOG_DELE, pack('V', $tPoint));
        if ($res !== false && strlen($this->receivedData) >= 8) {
            $hdr = unpack('vcmd', substr($this->receivedData, 0, 2));
            if ((int)$hdr['cmd'] === Constants::CMD_ACK_OK) {
                $this->refreshData();
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Webhooks & Real-Time Live Biometric Event Listener (CMD_REG_EVENT)
    // =========================================================================

    /**
     * Get or initialize the WebhookDispatcher attached to this ZKTeco client
     */
    public function getWebhookDispatcher(): WebhookDispatcher
    {
        if ($this->webhookDispatcher === null) {
            $this->webhookDispatcher = new WebhookDispatcher();
        }
        return $this->webhookDispatcher;
    }

    /**
     * Set a custom WebhookDispatcher instance
     */
    public function setWebhookDispatcher(WebhookDispatcher $dispatcher): self
    {
        $this->webhookDispatcher = $dispatcher;
        return $this;
    }

    /**
     * Register a webhook URL to receive real-time events & sync notifications from this device
     *
     * @param string $url Target HTTPS/HTTP webhook endpoint
     * @param string|null $secret Optional HMAC-SHA256 secret for X-ZKTeco-Signature
     * @param array<int, string> $events Event filter (e.g. ['attendance.punch', 'door.unlocked'] or ['*'])
     * @param array<string, string> $headers Custom headers (e.g. ['Authorization' => 'Bearer ...'])
     */
    public function addWebhook(
        string $url,
        ?string $secret = null,
        array $events = ['*'],
        array $headers = []
    ): self {
        $this->getWebhookDispatcher()->addEndpoint($url, $secret, $events, $headers);
        return $this;
    }

    /**
     * Register a local PHP listener callback for device events
     */
    public function onEvent(string $event, callable $callback): self
    {
        $this->getWebhookDispatcher()->on($event, $callback);
        return $this;
    }

    /**
     * Subscribe to real-time live biometric events (CMD_REG_EVENT = 500)
     * Automatically triggers $onPunch callback and dispatches Webhooks on every finger/face/card scan!
     *
     * @param callable|null $onEvent Callback receiving (array $eventData): ?bool (return false to stop loop)
     * @param int $maxDurationSec Maximum seconds to listen (0 = listen indefinitely)
     * @param int $eventMask Bitmask of Constants::EF_* events (default: Constants::EF_ALL)
     * @return bool True if event registration succeeded
     */
    public function listenRealTime(
        ?callable $onEvent = null,
        int $maxDurationSec = 0,
        int $eventMask = Constants::EF_ALL
    ): bool {
        // Register real-time event mask on the device
        $regReply = $this->execCommand(Constants::CMD_REG_EVENT, pack('V', $eventMask));
        if ($regReply === false) {
            return false;
        }

        $startTime = time();
        while (true) {
            if ($maxDurationSec > 0 && (time() - $startTime) >= $maxDurationSec) {
                break;
            }

            $packet = $this->recvPacket(1032);
            if ($packet === false || strlen($packet) < 8) {
                continue;
            }

            $header = unpack('vcommand/vchecksum/vsession/vreply', substr($packet, 0, 8));
            $cmd = (int)$header['command'];
            $eventType = (int)$header['session']; // In CMD_REG_EVENT, session field holds the EF_* event code

            if ($cmd !== Constants::CMD_REG_EVENT) {
                continue;
            }

            $payload = substr($packet, 8);

            // Acknowledge real-time event back to the machine (CMD_ACK_OK)
            $ack = $this->createHeader(Constants::CMD_ACK_OK, 0, $this->sessionId, 0, '');
            $this->sendPacket($ack);

            if (($eventType & Constants::EF_ATTLOG) === Constants::EF_ATTLOG && strlen($payload) >= 16) {
                $punchData = $this->parseRealTimeAttLogPayload($payload);
                $this->emitWebhook(WebhookDispatcher::EVENT_ATTENDANCE_PUNCH, $punchData);

                if ($onEvent !== null) {
                    $shouldContinue = $onEvent($punchData, WebhookDispatcher::EVENT_ATTENDANCE_PUNCH);
                    if ($shouldContinue === false) {
                        break;
                    }
                }
            } elseif (($eventType & Constants::EF_ALARM) === Constants::EF_ALARM) {
                $alarmData = ['event_code' => $eventType, 'raw_hex' => bin2hex($payload)];
                $this->emitWebhook(WebhookDispatcher::EVENT_DEVICE_ALARM, $alarmData);

                if ($onEvent !== null) {
                    $shouldContinue = $onEvent($alarmData, WebhookDispatcher::EVENT_DEVICE_ALARM);
                    if ($shouldContinue === false) {
                        break;
                    }
                }
            } elseif (($eventType & Constants::EF_UNLOCK) === Constants::EF_UNLOCK) {
                $unlockData = ['event_code' => $eventType, 'timestamp' => date('Y-m-d H:i:s')];
                $this->emitWebhook(WebhookDispatcher::EVENT_DOOR_UNLOCKED, $unlockData);

                if ($onEvent !== null) {
                    $shouldContinue = $onEvent($unlockData, WebhookDispatcher::EVENT_DOOR_UNLOCKED);
                    if ($shouldContinue === false) {
                        break;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Decode real-time EF_ATTLOG binary payload into a structured attendance punch array
     *
     * @return array{user_id: string, state: int, state_label: string, verify_type: int, verify_label: string, timestamp: string, work_code: int}
     */
    public function parseRealTimeAttLogPayload(string $payload): array
    {
        $len = strlen($payload);

        // 32, 36, or 40-byte SSR real-time event format (24-byte ASCII UserID + state + verify + 6-byte time)
        if ($len >= 32) {
            $userId = trim(explode("\0", substr($payload, 0, 24), 2)[0]);
            $state = ord($payload[24]);
            $verifyType = ord($payload[25]);
            $year   = 2000 + ord($payload[26]);
            $month  = ord($payload[27]);
            $day    = ord($payload[28]);
            $hour   = ord($payload[29]);
            $minute = ord($payload[30]);
            $second = ord($payload[31]);
            $workCode = $len >= 36 ? (int)unpack('Vwc', substr($payload, 32, 4))['wc'] : 0;

            return [
                'user_id'      => $userId,
                'state'        => $state,
                'state_label'  => Constants::getStateLabel($state),
                'verify_type'  => $verifyType,
                'verify_label' => Constants::getVerifyLabel($verifyType),
                'timestamp'    => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
                'work_code'    => $workCode,
            ];
        }

        // 16-byte compact real-time event format
        $u = unpack('vuid/Cstate/Cverify/Cyear/Cmonth/Cday/Chour/Cminute/Csecond', substr($payload, 0, 10));
        $state = (int)$u['state'];
        $verifyType = (int)$u['verify'];

        return [
            'user_id'      => (string)$u['uid'],
            'state'        => $state,
            'state_label'  => Constants::getStateLabel($state),
            'verify_type'  => $verifyType,
            'verify_label' => Constants::getVerifyLabel($verifyType),
            'timestamp'    => sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                2000 + (int)$u['year'],
                (int)$u['month'],
                (int)$u['day'],
                (int)$u['hour'],
                (int)$u['minute'],
                (int)$u['second']
            ),
            'work_code'    => 0,
        ];
    }

    private function getDeviceContextMeta(): array
    {
        return [
            'ip'       => $this->ip,
            'port'     => $this->port,
            'protocol' => $this->activeProtocol ?? $this->protocol,
        ];
    }

    private function emitWebhook(string $event, array $data): void
    {
        if ($this->webhookDispatcher !== null) {
            $this->webhookDispatcher->dispatch($event, $data, $this->getDeviceContextMeta());
        }
    }
}
