<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Room.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

foreach (['cict.dean@scheduleguard.test', 'gened.dean@scheduleguard.test'] as $email) {
    $stmt = db()->prepare('SELECT uid, email FROM userProfile WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        continue;
    }
    $dept = userDepartmentId((string) $user['uid']);
    $rooms = fetchRoomsForDepartment($dept, null, '');
    $schedules = fetchDeanFacultySchedules(null, null, $dept);
    echo $email . PHP_EOL;
    echo '  dept=' . $dept . PHP_EOL;
    echo '  rooms=' . count($rooms) . ' schedules=' . count($schedules) . PHP_EOL;
}
