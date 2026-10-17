<?php
/**
 * Laravel Facade for taha-rafi/php-zkteco
 *
 * @method static bool connect(?string $ip = null, ?int $port = null, ?int $commKey = null, ?string $protocol = null)
 * @method static bool connectWithRetry(int $maxAttempts = 3, int $retryDelayMs = 500)
 * @method static bool disconnect()
 * @method static string|null getActiveProtocol()
 * @method static array markAttendance(string|int $userId, int $state = 0, ?string $timestamp = null, int $verifyType = 1, array $options = [])
 * @method static bool writeAttendanceToDevice(string|int $userId, int $state = 0, ?string $timestamp = null, int $verifyType = 1, int $workCode = 0)
 * @method static array pushAttendance(array $records)
 * @method static bool deleteAttendanceByTimeRange(string $startTime, string $endTime)
 * @method static bool deleteAttendanceBefore(string $beforeTime)
 * @method static array|false getAttendance(bool $includeManual = true)
 * @method static array|false getAttendanceStructured(?string $since = null, bool $includeManual = true)
 * @method static array postAttendance(string $url, array $options = [])
 * @method static array|false getUser()
 * @method static array|false getUsers()
 * @method static array|false getUsersStructured()
 * @method static string|false setUser(int $uid, string $userid, string $name, string $password = '', int $role = 0, int $cardNo = 0)
 * @method static array pushUsers(array $users)
 * @method static array postUsers(string $url, array $options = [])
 * @method static string|false unlockDoor(int $delaySeconds = 3)
 * @method static bool listenRealTime(?callable $onEvent = null, int $maxDurationSec = 0, int $eventMask = 65535)
 * @method static \TahaRafi\ZKTeco\ZKTeco addWebhook(string $url, ?string $secret = null, array $events = ['*'], array $headers = [])
 *
 * @see \TahaRafi\ZKTeco\ZKTeco
 *
 * @package   TahaRafi\ZKTeco\Laravel\Facades
 * @author    Taha Rafi <connect@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use TahaRafi\ZKTeco\ZKTeco as ZKTecoClient;

class ZKTeco extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ZKTecoClient::class;
    }
}
