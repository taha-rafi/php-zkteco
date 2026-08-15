# 🚀 php-zkteco (Pure Native PHP 8.x ZKTeco Socket Library)

[![PHP Version](https://img.shields.io/badge/PHP-8.0%20%7C%208.1%20%7C%208.2%20%7C%208.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](LICENSE)
[![Comm Key Auth](https://img.shields.io/badge/Comm%20Key-Supported%20(Native)-brightgreen?style=for-the-badge&logo=shield)](https://github.com/taha-rafi/php-zkteco)
[![Zero Dependencies](https://img.shields.io/badge/Dependencies-Zero%20(Pure%20PHP)-blue?style=for-the-badge)](https://github.com/taha-rafi/php-zkteco)
[![Protocol](https://img.shields.io/badge/Protocol-UDP%20%2F%20TCP%20Sockets-orange?style=for-the-badge)](https://github.com/taha-rafi/php-zkteco)

A modern, high-performance, standalone **Pure Native PHP 8.x** socket communication library for **ZKTeco Biometric Time & Attendance Devices** (TX628, iClock, K-Series, uFace, MB-Series, ZMM-Series).

Designed by **Taha Rafi (Software Engineer)** with **Zero Python Dependencies** and featuring a **100% Native PHP Reverse-Engineered Comm Key Authentication Engine**, allowing seamless biometric communication with password-protected ZKTeco machines across Local LANs, Public IPs, and Cloud VPS hosts.

---

## ✨ Key Features

- ⚡ **100% Pure Native PHP**: No external SDKs, C-extensions, or Python bridges (`pyzk`) required.
- 🔑 **Native Comm Key Authentication**: Full reverse-engineered `MakeKey` bitwise obfuscation handshake (`CMD_AUTH 1102`), eliminating `0 users / 0 logs` locks on password-secured machines.
- 🐘 **PHP 8.0 - 8.3 Ready**: Modern PSR-4 namespaced architecture with typed properties, strict types, null-safe operators, and memory-safe socket buffer streaming.
- 👥 **User Profile Management**: Extract, create, update, and delete user profiles with custom roles and passwords.
- 🕒 **Attendance Streaming**: High-throughput packet streaming capable of pulling 30,000+ attendance records in seconds.
- ⚙️ **Device Hardware Controls**: Hardware clock synchronization, voice tests, device sleep/resume/reboot/shutdown, and LCD message broadcasting.
- 🌐 **Public IP & Remote WAN Ready**: Tested and optimized for remote connections over WAN, Port Forwarding (UDP `4370`), and DDNS.

---

## 📦 Installation

### Option 1: Via Composer (Recommended)

```bash
composer require taha-rafi/php-zkteco
```

### Option 2: Manual Require

Download or clone the repository and require `ZKTeco.php` directly:

```php
require_once __DIR__ . '/src/Constants.php';
require_once __DIR__ . '/src/ZKTeco.php';

use TahaRafi\ZKTeco\ZKTeco;
```

---

## 🚀 Quick Start

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use TahaRafi\ZKTeco\ZKTeco;

// Initialize device (IP, UDP Port, Security Comm Key)
$zk = new ZKTeco('192.168.1.201', 4370, 0);

if ($zk->connect()) {
    echo "✅ Connected and Authenticated!\n";

    // Disable device while reading data to prevent race conditions
    $zk->disableDevice();

    // 1. Fetch Users
    $users = $zk->getUser();
    echo "Total Users: " . count($users) . "\n";

    // 2. Fetch Attendance Records
    $attendance = $zk->getAttendance();
    echo "Total Attendance Logs: " . count($attendance) . "\n";

    // Re-enable device and disconnect
    $zk->enableDevice();
    $zk->disconnect();
} else {
    echo "❌ Connection failed!\n";
}
```

---

## 🔐 Comm Key Authentication Architecture

Standard ZKTeco libraries fail when a physical device has a **Communication Key (`Comm Key`)** set (e.g. `1234` or custom password). The device responds with `CMD_ACK_UNAUTH` (`2005`), causing unauthenticated queries to return empty datasets.

This library embeds a **pure PHP bitwise encryption algorithm** matching `commpro.c`:

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Machine returns CMD_ACK_UNAUTH (2005) + Session ID       │
│ 2. Scramble 32-bit Comm Key: k = (key << 1) | 1             │
│ 3. Add Session ID: k = (k + session_id) & 0xFFFFFFFF        │
│ 4. XOR with 'ZKSO' magic bytes                              │
│ 5. Word Swap (pack('HH', k[1], k[0])) + Tick XOR Modifier   │
│ 6. Send CMD_AUTH (1102) -> Machine unlocks CMD_ACK_OK (2000)│
└─────────────────────────────────────────────────────────────┘
```

---

## 📖 API Reference

### 🔌 Connection & Session
| Method | Return | Description |
|---|---|---|
| `connect(?string $ip, ?int $port, ?int $commKey)` | `bool` | Connects to device UDP socket and executes handshake/auth. |
| `disconnect()` | `bool` | Sends `CMD_EXIT` and cleanly terminates UDP socket session. |
| `ping(int $timeout = 1)` | `int\|string` | Pings device port and returns latency in milliseconds or `'down'`. |
| `setTimeout(int $sec, int $usec)` | `void` | Configures socket receive timeout. |

### 👥 User Management
| Method | Return | Description |
|---|---|---|
| `getUser()` / `getUsers()` | `array\|false` | Returns all users: `[uid => [userid, name, role, password]]`. |
| `setUser(int $uid, string $userid, string $name, string $password = '', int $role = 0)` | `string\|false` | Adds or updates user profile on the machine. |
| `deleteUser(int $uid)` | `string\|false` | Permanently deletes user from the device. |
| `clearAdmin()` | `string\|false` | Clears administrator privileges from all users. |

### 🕒 Attendance Records
| Method | Return | Description |
|---|---|---|
| `getAttendance()` | `array\|false` | Pulls all attendance records: `[[uid, userid, state, timestamp], ...]`. |
| `clearAttendance()` | `string\|false` | Wipes attendance log buffer on the machine. |

### ⚙️ Hardware & Diagnostics
| Method | Return | Description |
|---|---|---|
| `getDeviceName()` | `string\|false` | Returns device model (e.g., `TX628`). |
| `getSerialNumber()` | `string\|false` | Returns device serial number. |
| `getFirmwareVersion()` | `string\|false` | Returns device firmware version. |
| `getPlatform()` | `string\|false` | Returns platform chipset architecture (e.g., `ZMM200_TFT`). |
| `getTime()` | `string\|false` | Returns current hardware clock timestamp (`YYYY-MM-DD HH:MM:SS`). |
| `setTime(string $timestamp)` | `string\|false` | Sets hardware clock to specific timestamp. |
| `enableDevice()` / `disableDevice()` | `string\|false` | Enables/disables biometric scanner keypad input. |
| `restartDevice()` | `string\|false` | Reboots the physical biometric hardware. |
| `shutdownDevice()` | `string\|false` | Powers off the biometric machine. |
| `testVoice()` | `string\|false` | Plays audio prompt on device speaker for diagnostic testing. |
| `writeLCD(int $rank, string $text)` | `string\|false` | Broadcasts custom message text to machine LCD display. |
| `clearLCD()` | `string\|false` | Clears custom LCD display message. |

---

## 🛠️ Remote Public IP & Port Forwarding Guide

To access your office ZKTeco device over the Public Internet:

1. **Physical Machine Network Setup**:
   - Menu -> **`Comm.`** -> **`Ethernet`**: Set **Gateway** to your router's IP (e.g. `192.168.1.1`).
2. **Router Port Forwarding**:
   - Forward **UDP Port `4370`** to the internal IP of the machine (`192.168.1.201`).
3. **Public Access in PHP**:
   ```php
   $zk = new ZKTeco('YOUR_PUBLIC_IP', 4370, 0); // 0 or your Comm Key password
   $zk->connect();
   ```

---

## 🤝 Contributing & Feedback

Contributions, bug reports, and pull requests are welcome! If you find this library useful for your projects, please **give it a ⭐ on GitHub** to help other developers find it.

---

## 📄 License

This library is open-sourced software licensed under the [MIT License](LICENSE).
