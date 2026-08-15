<?php
/**
 * ZKTeco Standalone Pure PHP Socket Library
 *
 * Modern, high-performance PHP 8.x biometric communication client for ZKTeco devices.
 * Features 100% Native UDP/TCP Socket Protocol, Reverse-Engineered Comm Key Authentication,
 * User Management, Attendance Log Streaming, and Device Hardware Controls.
 *
 * @package    TahaRafi\ZKTeco
 * @author     Taha Rafi <contact@taharafi.com>
 * @license    MIT
 * @version    1.0.0
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

    /**
     * ZKTeco Client Constructor
     *
     * @param string|null $ip Device IP Address
     * @param int|null $port Device UDP Port (default: 4370)
     * @param int|null $commKey Security Comm Key / Password (default: 0)
     */
    public function __construct(?string $ip = null, ?int $port = 4370, ?int $commKey = 0)
    {
        if ($ip !== null) {
            $this->ip = $ip;
        }
        if ($port !== null) {
            $this->port = (int)$port;
        }
        if ($commKey !== null) {
            $this->commKey = (int)$commKey;
        }

        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->setTimeout($this->timeoutSec, $this->timeoutUsec);
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

    /**
     * Scramble password and session ID into a 4-byte ZKTeco Comm Key Token
     * Reverse-engineered from ZKTeco commpro.c MakeKey algorithm
     *
     * @param int $key Machine Comm Key
     * @param int $sessionId Active UDP Session ID
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
     * Connect to ZKTeco Biometric Machine and perform Handshake & Comm Key Auth
     *
     * @param string|null $ip Optional IP override
     * @param int|null $port Optional Port override
     * @param int|null $commKey Optional Comm Key override
     * @return bool True if successfully connected and authenticated
     */
    public function connect(?string $ip = null, ?int $port = null, ?int $commKey = null): bool
    {
        if ($ip !== null) $this->ip = $ip;
        if ($port !== null) $this->port = (int)$port;
        if ($commKey !== null) $this->commKey = (int)$commKey;

        if (!$this->ip || !$this->port) {
            return false;
        }

        if (!$this->socket) {
            $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            $this->setTimeout($this->timeoutSec, $this->timeoutUsec);
        }

        $command = Constants::CMD_CONNECT;
        $commandString = '';
        $chksum = 0;
        $sessionId = 0;
        $replyId = Constants::USHRT_MAX - 1;

        $buf = $this->createHeader($command, $chksum, $sessionId, $replyId, $commandString);
        @socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);

        try {
            $this->receivedData = '';
            @socket_recvfrom($this->socket, $this->receivedData, 1024, 0, $this->ip, $this->port);

            if (strlen($this->receivedData) >= 8) {
                $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->receivedData, 0, 8));
                $this->sessionId = hexdec($u['h6'] . $u['h5']);
                $cmdCode = hexdec($u['h2'] . $u['h1']);

                // Authenticate if machine responds with CMD_ACK_UNAUTH (2005)
                if ($cmdCode == Constants::CMD_ACK_UNAUTH) {
                    $authPayload = $this->makeCommKey($this->commKey, $this->sessionId);
                    $replyId = hexdec($u['h8'] . $u['h7']);
                    $buf = $this->createHeader(Constants::CMD_AUTH, 0, $this->sessionId, $replyId, $authPayload);
                    @socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
                    @socket_recvfrom($this->socket, $this->receivedData, 1024, 0, $this->ip, $this->port);
                }

                return $this->checkValid($this->receivedData);
            }
            return false;
        } catch (\Throwable $e) {
            return false;
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
            return false;
        }

        $command = Constants::CMD_EXIT;
        $commandString = '';
        $chksum = 0;
        $sessionId = $this->sessionId;

        $replyId = 0;
        if (strlen($this->receivedData) >= 8) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->receivedData, 0, 8));
            $replyId = hexdec($u['h8'] . $u['h7']);
        }

        $buf = $this->createHeader($command, $chksum, $sessionId, $replyId, $commandString);
        @socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);

        try {
            @socket_recvfrom($this->socket, $this->receivedData, 1024, 0, $this->ip, $this->port);
            $valid = $this->checkValid($this->receivedData);
            @socket_close($this->socket);
            $this->socket = null;
            return $valid;
        } catch (\Throwable $e) {
            if ($this->socket) {
                @socket_close($this->socket);
                $this->socket = null;
            }
            return false;
        }
    }

    /**
     * Set socket receive timeout
     */
    public function setTimeout(int $sec = 5, int $usec = 0): void
    {
        $this->timeoutSec = $sec;
        $this->timeoutUsec = $usec;
        if ($this->socket) {
            $timeout = ['sec' => $this->timeoutSec, 'usec' => $this->timeoutUsec];
            @socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        }
    }

    /**
     * Ping device port
     */
    public function ping(int $timeout = 1): int|string
    {
        $time1 = microtime(true);
        $pfile = @fsockopen($this->ip, $this->port, $errno, $errstr, $timeout);
        if (!$pfile) {
            return 'down';
        }
        $time2 = microtime(true);
        fclose($pfile);
        return (int)round(($time2 - $time1) * 1000);
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

    public function decodeTime(int|float $data): string
    {
        $second = (int)($data % 60);
        $data = (int)($data / 60);
        $minute = (int)($data % 60);
        $data = (int)($data / 60);
        $hour = (int)($data % 24);
        $data = (int)($data / 24);
        $day = (int)($data % 31 + 1);
        $data = (int)($data / 31);
        $month = (int)($data % 12 + 1);
        $data = (int)($data / 12);
        $year = (int)floor($data + 2000);
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
        $chksum = $u;
        $replyId += 1;
        if ($replyId >= Constants::USHRT_MAX) {
            $replyId -= Constants::USHRT_MAX;
        }
        return pack('SSSS', $command, $chksum, $sessionId, $replyId) . $commandString;
    }

    private function checkValid(string $reply): bool
    {
        if (strlen($reply) < 4) return false;
        $u = unpack('H2h1/H2h2', substr($reply, 0, 4));
        $command = hexdec($u['h2'] . $u['h1']);
        return ($command == Constants::CMD_ACK_OK || $command == Constants::CMD_ACK_UNAUTH);
    }

    public function execCommand(int $command, string $commandString = '', int $offsetData = 8): string|false
    {
        $chksum = 0;
        $sessionId = $this->sessionId;
        $replyId = 0;
        if (strlen($this->receivedData) >= 8) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->receivedData, 0, 8));
            $replyId = hexdec($u['h8'] . $u['h7']);
        }
        $buf = $this->createHeader($command, $chksum, $sessionId, $replyId, $commandString);
        @socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);

        try {
            $this->receivedData = '';
            @socket_recvfrom($this->socket, $this->receivedData, 1024, 0, $this->ip, $this->port);
            if (strlen($this->receivedData) >= 8) {
                $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($this->receivedData, 0, 8));
                $this->sessionId = hexdec($u['h6'] . $u['h5']);
                return substr($this->receivedData, $offsetData);
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getSizePayload(): int|false
    {
        if (strlen($this->receivedData) < 12) return false;
        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->receivedData, 0, 8));
        $command = hexdec($u['h2'] . $u['h1']);
        if ($command == Constants::CMD_PREPARE_DATA) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4', substr($this->receivedData, 8, 4));
            return hexdec($u['h4'] . $u['h3'] . $u['h2'] . $u['h1']);
        }
        return false;
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

    public function testVoice(): string|false
    {
        return $this->execCommand(Constants::CMD_TESTVOICE, chr(0) . chr(0));
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
        return $this->execCommand(Constants::CMD_VERSION);
    }

    public function getSerialNumber(): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, '~SerialNumber');
        if ($return) {
            $arr = explode("=", (string)$return, 2);
            return trim($arr[1] ?? (string)$return);
        }
        return false;
    }

    public function getDeviceName(): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, '~DeviceName');
        if ($return) {
            $arr = explode("=", (string)$return, 2);
            return trim($arr[1] ?? (string)$return);
        }
        return false;
    }

    public function getOSVersion(): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, '~OS');
        if ($return) {
            $arr = explode("=", (string)$return, 2);
            return trim($arr[1] ?? (string)$return);
        }
        return false;
    }

    public function getPlatform(): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, '~Platform');
        if ($return) {
            $arr = explode("=", (string)$return, 2);
            return trim($arr[1] ?? (string)$return);
        }
        return false;
    }

    public function getFirmwareVersion(): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, '~ZKFPVersion');
        if ($return) {
            $arr = explode("=", (string)$return, 2);
            return trim($arr[1] ?? (string)$return);
        }
        return false;
    }

    public function getPinWidth(): string|false
    {
        $return = $this->execCommand(Constants::CMD_OPTIONS_RRQ, '~PIN2Width');
        if ($return) {
            $arr = explode("=", (string)$return, 2);
            return trim($arr[1] ?? (string)$return);
        }
        return false;
    }

    public function getTime(): string|false
    {
        $raw = $this->execCommand(Constants::CMD_GET_TIME);
        if ($raw) {
            return $this->decodeTime(hexdec($this->reverseHex(bin2hex($raw))));
        }
        return false;
    }

    public function setTime(string $timestamp): string|false
    {
        $commandString = pack('I', $this->encodeTime($timestamp));
        return $this->execCommand(Constants::CMD_SET_TIME, $commandString);
    }

    // =========================================================================
    // User & Enrollment Management Methods
    // =========================================================================

    /**
     * Retrieve all enrolled users from the device
     *
     * @return array [uid => [userId, name, role, password]]
     */
    public function getUser(): array|false
    {
        $this->userData = [];
        $command = Constants::CMD_USERTEMP_RRQ;
        $commandString = chr(5);
        $chksum = 0;
        $sessionId = $this->sessionId;

        $replyId = 0;
        if (strlen($this->receivedData) >= 8) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->receivedData, 0, 8));
            $replyId = hexdec($u['h8'] . $u['h7']);
        }

        $buf = $this->createHeader($command, $chksum, $sessionId, $replyId, $commandString);
        @socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);

        try {
            $this->receivedData = '';
            @socket_recvfrom($this->socket, $this->receivedData, 1024, 0, $this->ip, $this->port);
            $bytes = $this->getSizePayload();

            if ($bytes) {
                while ($bytes > 0) {
                    $chunk = '';
                    @socket_recvfrom($this->socket, $chunk, 1032, 0, $this->ip, $this->port);
                    $this->userData[] = $chunk;
                    $bytes -= 1024;
                }
                if (strlen($this->receivedData) >= 8) {
                    $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($this->receivedData, 0, 8));
                    $this->sessionId = hexdec($u['h6'] . $u['h5']);
                }
                $finalAck = '';
                @socket_recvfrom($this->socket, $finalAck, 1024, 0, $this->ip, $this->port);
            }

            $users = [];
            if (!empty($this->userData)) {
                for ($x = 0; $x < count($this->userData); $x++) {
                    if ($x > 0) {
                        $this->userData[$x] = substr($this->userData[$x], 8);
                    }
                }
                $userDataStr = substr(implode('', $this->userData), 11);

                while (strlen($userDataStr) >= 72) {
                    $u = unpack('H144', substr($userDataStr, 0, 72));
                    $u1 = hexdec(substr($u[1], 2, 2));
                    $u2 = hexdec(substr($u[1], 4, 2));
                    $uid = $u1 + ($u2 * 256);
                    $role = hexdec(substr($u[1], 6, 2));
                    $password = trim(explode(chr(0), (string)hex2bin(substr($u[1], 8, 16)), 2)[0] ?? '');
                    $name = trim(explode(chr(0), (string)hex2bin(substr($u[1], 24, 74)), 2)[0] ?? '');
                    $userid = trim(explode(chr(0), (string)hex2bin(substr($u[1], 98, 72)), 2)[0] ?? '');

                    if ($name === '') {
                        $name = (string)$uid;
                    }
                    if ($userid === '') {
                        $userid = (string)$uid;
                    }

                    $users[$uid] = [$userid, $name, (int)$role, $password];
                    $userDataStr = substr($userDataStr, 72);
                }
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
     * Add or update user profile on the device
     */
    public function setUser(int $uid, string $userid, string $name, string $password = '', int $role = Constants::LEVEL_USER): string|false
    {
        $name = substr($name, 0, 28);
        $command = Constants::CMD_USER_WRQ;
        $byte1 = chr($uid % 256);
        $byte2 = chr((int)($uid >> 8));
        $commandString = $byte1 . $byte2 . chr($role) . str_pad($password, 8, chr(0)) . str_pad($name, 28, chr(0)) . str_pad(chr(1), 9, chr(0)) . str_pad($userid, 8, chr(0)) . str_repeat(chr(0), 16);
        return $this->execCommand($command, $commandString);
    }

    /**
     * Delete user from the biometric machine
     */
    public function deleteUser(int $uid): string|false
    {
        $byte1 = chr($uid % 256);
        $byte2 = chr((int)($uid >> 8));
        return $this->execCommand(Constants::CMD_DELETE_USER, $byte1 . $byte2);
    }

    public function clearAdmin(): string|false
    {
        return $this->execCommand(Constants::CMD_CLEAR_ADMIN);
    }

    // =========================================================================
    // Attendance Log Streaming Methods
    // =========================================================================

    /**
     * Pull all attendance records from biometric storage
     *
     * @return array Array of [uid, userid, state, timestamp]
     */
    public function getAttendance(): array|false
    {
        $this->attendanceData = [];
        $command = Constants::CMD_ATTLOG_RRQ;
        $commandString = '';
        $chksum = 0;
        $sessionId = $this->sessionId;

        $replyId = 0;
        if (strlen($this->receivedData) >= 8) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->receivedData, 0, 8));
            $replyId = hexdec($u['h8'] . $u['h7']);
        }

        $buf = $this->createHeader($command, $chksum, $sessionId, $replyId, $commandString);
        @socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);

        try {
            $this->receivedData = '';
            @socket_recvfrom($this->socket, $this->receivedData, 1024, 0, $this->ip, $this->port);
            $bytes = $this->getSizePayload();

            if ($bytes) {
                while ($bytes > 0) {
                    $chunk = '';
                    @socket_recvfrom($this->socket, $chunk, 1032, 0, $this->ip, $this->port);
                    $this->attendanceData[] = $chunk;
                    $bytes -= 1024;
                }
                if (strlen($this->receivedData) >= 8) {
                    $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($this->receivedData, 0, 8));
                    $this->sessionId = hexdec($u['h6'] . $u['h5']);
                }
                $finalAck = '';
                @socket_recvfrom($this->socket, $finalAck, 1024, 0, $this->ip, $this->port);
            }

            $attendance = [];
            if (!empty($this->attendanceData)) {
                for ($x = 0; $x < count($this->attendanceData); $x++) {
                    if ($x > 0) {
                        $this->attendanceData[$x] = substr($this->attendanceData[$x], 8);
                    }
                }
                $attendanceDataStr = substr(implode('', $this->attendanceData), 10);

                while (strlen($attendanceDataStr) >= 40) {
                    $u = unpack('H78', substr($attendanceDataStr, 0, 39));
                    $u1 = hexdec(substr($u[1], 4, 2));
                    $u2 = hexdec(substr($u[1], 6, 2));
                    $uid = $u1 + ($u2 * 256);
                    $id = str_replace("\0", '', (string)hex2bin(substr($u[1], 8, 16)));
                    $state = hexdec(substr($u[1], 56, 2));
                    $timestamp = $this->decodeTime(hexdec($this->reverseHex(substr($u[1], 58, 8))));

                    $attendance[] = [$uid, $id, $state, $timestamp];
                    $attendanceDataStr = substr($attendanceDataStr, 40);
                }
            }
            return $attendance;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Clear all attendance logs on the device storage
     */
    public function clearAttendance(): string|false
    {
        return $this->execCommand(Constants::CMD_CLEAR_ATTLOG);
    }
}
