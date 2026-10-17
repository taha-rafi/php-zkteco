# 🚀 php-zkteco v2.0 (Pure Native PHP 8.x ZKTeco TCP/UDP, Cloud POST Sync, ADMS Push & Webhook Engine)

[![Latest Stable Version](https://poser.pugx.org/taha-rafi/php-zkteco/v)](https://packagist.org/packages/taha-rafi/php-zkteco)
[![Total Downloads](https://poser.pugx.org/taha-rafi/php-zkteco/downloads)](https://packagist.org/packages/taha-rafi/php-zkteco)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%20%7C%208.1%20%7C%208.2%20%7C%208.3%20%7C%208.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](LICENSE)
[![Comm Key Auth](https://img.shields.io/badge/Comm%20Key-Supported%20(Native)-brightgreen?style=for-the-badge&logo=shield)](https://github.com/taha-rafi/php-zkteco)
[![Protocol](https://img.shields.io/badge/Protocol-Auto%20(TCP%20%2B%20UDP%20%2B%20ADMS)-orange?style=for-the-badge)](https://github.com/taha-rafi/php-zkteco)
[![Webhooks](https://img.shields.io/badge/Webhooks-HMAC--SHA256%20%2B%20RealTime-purple?style=for-the-badge)](https://github.com/taha-rafi/php-zkteco)

A next-generation, standalone **Pure Native PHP 8.x** communication, Cloud POST Sync, ADMS Push Server, and Real-Time Webhook library for **ZKTeco Biometric Time & Attendance Devices** (TX628, iClock, K-Series, uFace, SpeedFace, MB-Series, ZMM-Series).

Unlike legacy PHP ZKTeco packages that only support unreliable **UDP** polling and only `GET` raw arrays, **php-zkteco v2.0** introduces:
1. **Direct Hardware Attendance Writing (`CMD_SET_DATA = 3001`)**: The **first and only** ZKTeco library capable of writing attendance punches (`Check-In` / `Check-Out`) **directly into the physical ZKTeco machine's internal `ATT_LOG` memory** (`ZKDB.db`), plus selective attendance deletion by time range (`CMD_TIME_ATTLOG_DELE = 10005`).
2. **Dual-Protocol Socket Engine (`auto`, `tcp`, `udp`)**: Full native ZKTeco **TCP framing (`\x50\x50\x82\x7d`)** with automatic fallback to **UDP**, eliminating dropped packets over WAN/VPN/Cloud links.
3. **Bi-Directional `POST` & Cloud Sync Engine**: Directly `POST` attendance logs and user profiles to any remote REST API / ERP / HRMS with automatic batching, incremental cursor deduplication (`state_file`), and bulk-push users/cards/attendance to the machine.
4. **Real-Time Live Punch Listener & Signed Webhooks**: Subscribe to live hardware scans (`CMD_REG_EVENT 500`) and automatically dispatch **HMAC-SHA256 signed Webhooks** (`attendance.punch`, `user.created`, `door.unlocked`, `device.alarm`).
5. **Built-in ADMS / iClock HTTP Push Server (`AdmsServer`)**: Receive real-time HTTP `POST /iclock/cdata` pushes directly from ZKTeco Cloud-enabled machines behind 4G/NAT routers without port forwarding.

---

## ✨ What Makes `php-zkteco` v2.0 Different?

| Feature | Other PHP/Python/Node ZKTeco Packages | `taha-rafi/php-zkteco` v2.0 |
|---|---|---|
| **Write Attendance into Physical Machine** | ❌ **Impossible** (Read-Only `ATT_LOG`) | ✅ **Direct Hardware `ATT_LOG` Write (`markAttendance()` / `writeAttendanceToDevice()`)** |
| **Selective Attendance Deletion** | ❌ Only full wipe (`clearAttendance()`) | ✅ **`deleteAttendanceByTimeRange()` & `deleteAttendanceBefore()`** |
| **Socket Transport** | UDP Only (drops packets on WAN) | ✅ **Dual TCP + UDP + Auto-Fallback (`auto`)** |
| **Comm Key Password Auth** | ❌ Broken (`CMD_ACK_UNAUTH` lock) | ✅ **100% Native `MakeKey` Auth (`CMD_AUTH 1102`)** |
| **Attendance & Users Sync** | Only `getAttendance()` | ✅ **`getAttendance()` + `postAttendance()` + `pushAttendance()` + `pushUsers()`** |
| **Incremental Deduplication** | ❌ Re-fetches all 30k logs every time | ✅ **Built-in `since` & `state_file` cursor tracking** |
| **Real-Time Webhooks** | ❌ None | ✅ **Live `CMD_REG_EVENT` Listener + HMAC-SHA256 Webhooks** |
| **ADMS / iClock Push Server** | ❌ None | ✅ **Built-in `AdmsServer` (`POST /iclock/cdata` + Command Queue)** |
| **Access Control & Cards** | ❌ None | ✅ **Remote `unlockDoor()` + RFID `card_no` read/write** |

---

## 📦 Installation

### Option 1: Via Composer (Recommended)

```bash
composer require taha-rafi/php-zkteco
```

### Option 2: Manual Require

```php
require_once __DIR__ . '/src/Constants.php';
require_once __DIR__ . '/src/WebhookDispatcher.php';
require_once __DIR__ . '/src/AdmsServer.php';
require_once __DIR__ . '/src/ZKTeco.php';

use TahaRafi\ZKTeco\ZKTeco;
use TahaRafi\ZKTeco\Constants;
```

---

## 🚀 1. Dual-Protocol Connection (`auto`, `tcp`, `udp`)

By default, `ZKTeco` uses `Constants::PROTOCOL_AUTO`: it attempts a reliable **TCP** stream connection first (`\x50\x50\x82\x7d` framing) and automatically falls back to **UDP** if the device only listens on UDP.

```php
<?php
declare(strict_types=1);

use TahaRafi\ZKTeco\ZKTeco;
use TahaRafi\ZKTeco\Constants;

// Protocol options: Constants::PROTOCOL_AUTO (default), Constants::PROTOCOL_TCP, Constants::PROTOCOL_UDP
$zk = new ZKTeco('192.168.1.201', 4370, commKey: 0, protocol: Constants::PROTOCOL_AUTO);

if ($zk->connectWithRetry(maxAttempts: 3, retryDelayMs: 500)) {
    echo "✅ Connected via: " . strtoupper((string)$zk->getActiveProtocol()) . "\n"; // TCP or UDP

    $users = $zk->getUsersStructured();
    $logs  = $zk->getAttendanceStructured(since: '2026-10-01 00:00:00');

    $zk->disconnect();
}
```

---

## ⚡ 2. Direct Hardware Attendance Writing & Selective Deletion (`CMD_SET_DATA = 3001`)

Unlike every other ZKTeco library where `ATT_LOG` is strictly read-only, `php-zkteco` v2.0 can **write attendance punches directly into the physical ZKTeco machine's internal `ATT_LOG` database** (for current, past, or future timestamps) and selectively delete logs by time range without wiping the entire device:

```php
<?php
use TahaRafi\ZKTeco\ZKTeco;
use TahaRafi\ZKTeco\Constants;

$zk = new ZKTeco('192.168.1.201', 4370, commKey: 0, protocol: Constants::PROTOCOL_AUTO);

if ($zk->connect()) {
    // A. Mark Check-In / Check-Out directly inside the physical ZKTeco machine's ATT_LOG memory!
    $res = $zk->markAttendance(
        userId: '1',                             // Enrolled User ID on the machine
        state: Constants::ATT_STATE_CHECK_OUT,   // Check-In (0), Check-Out (1), Break-Out (2), Break-In (3)
        timestamp: '2026-10-18 18:00:00',        // null = current time, or pass any 'YYYY-MM-DD HH:MM:SS'
        verifyType: Constants::VERIFY_FINGERPRINT,
        options: [
            'write_to_device' => true,           // Writes directly to physical machine ATT_LOG (CMD_SET_DATA 3001)
            'device_feedback' => true,           // Shows on device LCD & triggers "Thank You" voice
        ]
    );
    // $res['device_written'] === true

    // B. Bulk-write multiple attendance records into the physical machine
    $zk->pushAttendance([
        ['user_id' => '1', 'state' => Constants::ATT_STATE_CHECK_IN,  'timestamp' => '2026-10-18 09:00:00'],
        ['user_id' => '1', 'state' => Constants::ATT_STATE_CHECK_OUT, 'timestamp' => '2026-10-18 18:00:00'],
    ]);

    // C. Selectively delete attendance records by time range (without wiping all logs!)
    $zk->deleteAttendanceByTimeRange('2026-10-18 00:00:00', '2026-10-18 23:59:59');

    $zk->disconnect();
}
```

---

## 📤 3. Outbound `POST` Attendance & Bi-Directional Sync

Instead of only pulling raw arrays, `postAttendance()` and `postUsers()` push structured JSON batches directly to your ERP / HRMS / Laravel API with **incremental cursor state tracking** (so only new punches are posted on each cron run) and **HMAC-SHA256 signing**:

```php
<?php
use TahaRafi\ZKTeco\ZKTeco;
use TahaRafi\ZKTeco\Constants;

$zk = new ZKTeco('192.168.1.201', 4370, 0, Constants::PROTOCOL_AUTO);

if ($zk->connect()) {
    // A. POST new attendance punches to your API in batches
    $sync = $zk->postAttendance('https://erp.example.com/api/biometric/attendance', [
        'token'            => 'your-bearer-api-token',
        'secret'           => 'whsec_signing_secret',
        'state_file'       => __DIR__ . '/.zk_sync_cursor.json', // Tracks last synced timestamp automatically!
        'batch_size'       => 500,
        'retries'          => 3,
        'clear_after_post' => false, // Set true to wipe device logs ONLY if all POST batches succeed
    ]);

    // B. Bulk Push/POST employees (with RFID card numbers) FROM your app TO the ZKTeco device
    $zk->pushUsers([
        ['uid' => 1, 'user_id' => '1001', 'name' => 'Taha Rafi', 'role' => Constants::LEVEL_SUPERADMIN, 'card_no' => 12345678],
        ['uid' => 2, 'user_id' => '1002', 'name' => 'Ali Khan',  'role' => Constants::LEVEL_USER,       'card_no' => 87654321],
    ]);

    $zk->disconnect();
}
```

---

## 🔔 4. Real-Time Webhooks & Live Punch Listener (`CMD_REG_EVENT`)

Register one or multiple Webhook endpoints on `$zk`. When you run `$zk->listenRealTime()`, the library subscribes to hardware events (`CMD_REG_EVENT = 500`) and fires signed HTTP `POST` webhooks the exact millisecond a finger, face, or RFID card is scanned:

```php
<?php
use TahaRafi\ZKTeco\ZKTeco;
use TahaRafi\ZKTeco\WebhookDispatcher;

$zk = new ZKTeco('192.168.1.201', 4370, 0);

$zk->addWebhook(
    url: 'https://your-app.com/webhooks/zkteco',
    secret: 'whsec_your_secret_key',
    events: [
        WebhookDispatcher::EVENT_ATTENDANCE_PUNCH,
        WebhookDispatcher::EVENT_USER_CREATED,
        WebhookDispatcher::EVENT_DOOR_UNLOCKED,
        WebhookDispatcher::EVENT_DEVICE_ALARM,
    ]
);

if ($zk->connect()) {
    // Listen continuously and fire webhooks in real time on every biometric scan
    $zk->listenRealTime(function (array $punch, string $event): bool {
        echo "⚡ Live Punch: User {$punch['user_id']} ({$punch['state_label']} via {$punch['verify_label']}) at {$punch['timestamp']}\n";
        return true; // Return false to stop listening
    });
}
```

### Verifying Webhooks on Your Receiving Server

```php
<?php
use TahaRafi\ZKTeco\WebhookDispatcher;

// Verifies X-ZKTeco-Signature (HMAC-SHA256) & X-ZKTeco-Timestamp replay protection automatically
$event = WebhookDispatcher::captureIncoming('whsec_your_secret_key');

if ($event['event'] === WebhookDispatcher::EVENT_ATTENDANCE_PUNCH) {
    $punch = $event['data'];
    // $punch['user_id'], $punch['state_label'], $punch['verify_label'], $punch['timestamp']
}
http_response_code(200);
```

---

## ☁️ 5. ADMS / iClock Cloud Push Server (`AdmsServer`)

If your ZKTeco device is behind a 4G router or dynamic IP without port forwarding, point the machine's **Cloud Server (ADMS)** setting to your PHP endpoint using `AdmsServer`:

```php
<?php
use TahaRafi\ZKTeco\AdmsServer;
use TahaRafi\ZKTeco\WebhookDispatcher;

$webhooks = (new WebhookDispatcher())->addEndpoint('https://your-app.com/webhooks/zkteco', 'whsec_secret');
$adms = new AdmsServer($webhooks, __DIR__ . '/.adms_queue.json', timezoneOffset: 5);

$adms->onAttendance(function (string $serialNumber, array $records): void {
    // Device POSTed real-time ATTLOG records directly to your server!
});

// Queue commands to push back to the device on its next /iclock/getrequest poll:
// $adms->queueUpdateUser('SERIAL123', '1001', 'Taha Rafi');
// $adms->queueUnlockDoor('SERIAL123');

$response = $adms->handleRequest();
http_response_code($response['status']);
echo $response['body'];
```

---

## 📖 Complete API Reference

### 🔌 Connection & Transport (`ZKTeco`)
| Method | Return | Description |
|---|---|---|
| `__construct(?string $ip, ?int $port = 4370, ?int $commKey = 0, string $protocol = 'auto', int $timeoutSec = 5)` | `ZKTeco` | Initializes client with `'auto'` (TCP->UDP fallback), `'tcp'`, or `'udp'`. |
| `connect(?string $ip, ?int $port, ?int $commKey, ?string $protocol)` | `bool` | Connects & authenticates via TCP/UDP with native `MakeKey` handshake. |
| `connectWithRetry(int $maxAttempts = 3, int $retryDelayMs = 500)` | `bool` | Connects with automatic exponential backoff retries. |
| `getActiveProtocol()` | `?string` | Returns `'tcp'` or `'udp'` for the active session. |
| `disconnect()` | `bool` | Sends `CMD_EXIT` and closes the socket session. |
| `ping(int $timeout = 1)` | `int\|string` | Probes TCP & UDP port and returns latency in `ms` or `'down'`. |

### 🕒 Attendance (`GET`, Direct Hardware `WRITE` & `POST`)
| Method | Return | Description |
|---|---|---|
| `markAttendance(string\|int $userId, int $state = 0, ?string $timestamp = null, int $verifyType = 1, ...)` | `array` | **Writes an attendance punch directly into the physical ZKTeco machine's `ATT_LOG` memory** (`CMD_SET_DATA 3001`), triggers LCD/Voice feedback, and dispatches `attendance.punch` webhooks. |
| `writeAttendanceToDevice(string\|int $userId, int $state = 0, ?string $timestamp = null, int $verifyType = 1, int $workCode = 0)` | `bool` | Low-level direct hardware `ATT_LOG` insertion into the machine's internal storage + `refreshData()`. |
| `pushAttendance(array $records)` | `array` | Bulk-writes multiple attendance punches directly into the physical ZKTeco machine's internal `ATT_LOG` storage. |
| `deleteAttendanceByTimeRange(string $startTime, string $endTime)` | `bool` | Selectively deletes attendance logs from the physical machine within `[$startTime, $endTime]` (`CMD_TIME_ATTLOG_DELE 10005`). |
| `deleteAttendanceBefore(string $beforeTime)` | `bool` | Selectively deletes attendance logs from the physical machine older than or equal to `$beforeTime` (`CMD_TIMEPOINT_ATTLOG_DELE 10006`). |
| `getAttendance()` | `array\|false` | Returns indexed records: `[[uid, userid, state, timestamp, verifyType], ...]`. |
| `getAttendanceStructured(?string $since = null)` | `array\|false` | Returns associative records with `state_label`, `verify_label`, and `since` filter. |
| `postAttendance(string $url, array $options = [])` | `array` | Pulls & `POST`s attendance to a remote URL with batching, `state_file` cursor, and HMAC signing. |
| `clearAttendance()` | `string\|false` | Clears all attendance logs on the device and emits `attendance.cleared` webhook. |

### 👥 Users, Cards & Access Control (`GET` & `POST`)
| Method | Return | Description |
|---|---|---|
| `getUser()` / `getUsers()` | `array\|false` | Returns `[uid => [userid, name, role, password, cardNo]]`. |
| `getUsersStructured()` | `array\|false` | Returns associative array of enrolled users including `card_no`. |
| `setUser(int $uid, string $userid, string $name, string $password = '', int $role = 0, int $cardNo = 0)` | `string\|false` | Creates/updates user profile and RFID card on the device. |
| `pushUsers(array $users)` | `array` | Bulk-pushes an array of users to the device in one call. |
| `postUsers(string $url, array $options = [])` | `array` | Pulls users from device and `POST`s them to a remote API endpoint. |
| `deleteUser(int $uid)` | `string\|false` | Deletes user from the device and emits `user.deleted` webhook. |
| `unlockDoor(int $delaySeconds = 3)` | `string\|false` | Triggers door unlock relay (`CMD_UNLOCK`) and emits `door.unlocked` webhook. |

### 🔔 Webhooks & Real-Time Events
| Method | Return | Description |
|---|---|---|
| `addWebhook(string $url, ?string $secret = null, array $events = ['*'], array $headers = [])` | `self` | Registers a webhook URL for real-time punches & device events. |
| `onEvent(string $event, callable $callback)` | `self` | Registers a local PHP callback for events (`attendance.punch`, `*`, etc.). |
| `listenRealTime(?callable $onEvent = null, int $maxDurationSec = 0, int $eventMask = Constants::EF_ALL)` | `bool` | Subscribes to `CMD_REG_EVENT` (500) for instant live biometric scans. |
| `WebhookDispatcher::captureIncoming(?string $secret = null)` | `array` | Verifies `X-ZKTeco-Signature` and parses incoming webhook `POST` body. |

---

## 👨‍💻 Author & Support

* **Taha Rafi** — Software Engineer & Lead Developer
* **Email**: [connect@taharafi.com](mailto:connect@taharafi.com)
* **GitHub**: [@taha-rafi](https://github.com/taha-rafi)

---

## 📄 License

This library is open-sourced software licensed under the [MIT License](LICENSE).

