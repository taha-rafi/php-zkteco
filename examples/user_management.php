<?php
/**
 * User Profile Management Example
 * php-zkteco Library
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/ZKTeco.php';

use TahaRafi\ZKTeco\ZKTeco;
use TahaRafi\ZKTeco\Constants;

$zk = new ZKTeco('192.168.1.201', 4370, 0);

if ($zk->connect()) {
    echo "✅ Connected to device.\n\n";

    $zk->disableDevice();

    // 1. List Users
    $users = $zk->getUser();
    echo "Current Users in Machine: " . count($users) . "\n";

    // 2. Add or Update a User
    // Roles: LEVEL_USER (0), LEVEL_ENROLLER (2), LEVEL_MANAGER (12), LEVEL_SUPERADMIN (14)
    $newUid = 99;
    $newUserId = '99';
    $newName = 'Test Employee';
    $newPassword = '';
    $role = Constants::LEVEL_USER;

    echo "Adding/Updating User UID {$newUid} ({$newName})...\n";
    $result = $zk->setUser($newUid, $newUserId, $newName, $newPassword, $role);
    echo "Set User Result: " . ($result !== false ? "Success" : "Failed") . "\n";

    // 3. Delete a User (commented out)
    // echo "Deleting User UID {$newUid}...\n";
    // $zk->deleteUser($newUid);

    $zk->enableDevice();
    $zk->disconnect();
    echo "Done.\n";
} else {
    echo "❌ Connection failed.\n";
}
