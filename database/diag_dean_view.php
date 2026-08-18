<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$email = $argv[1] ?? 'cict.dean@scheduleguard.test';
$stmt = db()->prepare('SELECT uid, email, role FROM userProfile WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User not found: {$email}\n");
    exit(1);
}

$dept = userDepartmentId((string) $user['uid']);
echo "User: {$user['email']} ({$user['role']}) dept={$dept}\n";

// faculty API logic
$sql = "SELECT uid, firstName, lastName FROM userProfile WHERE role = 'Faculty' AND status = 'Active'";
$params = [];
if (in_array($user['role'], ['Dean', 'ProgramHead'], true) && $dept !== null) {
    $sql .= ' AND departmentId = :departmentId';
    $params[':departmentId'] = $dept;
}
$sql .= ' ORDER BY lastName ASC';
$st = db()->prepare($sql);
$st->execute($params);
$faculty = $st->fetchAll(PDO::FETCH_ASSOC);
echo 'Faculty count: ' . count($faculty) . PHP_EOL;

require_once dirname(__DIR__) . '/includes/Room.php';
$rooms = fetchRooms(null, '');
echo 'Rooms count: ' . count($rooms) . PHP_EOL;

require_once dirname(__DIR__) . '/includes/Schedule.php';
require_once dirname(__DIR__) . '/includes/FacultyLoad.php';
try {
    $schedules = fetchDeanFacultySchedules(null, null, $dept);
    echo 'Schedules count: ' . count($schedules) . PHP_EOL;
    if (isset($schedules[0])) {
        echo 'Sample schedule keys: ' . implode(', ', array_keys($schedules[0])) . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'fetchDeanFacultySchedules ERROR: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

try {
    $tbf = computeDepartmentTbfLoadSummary($dept);
    echo 'TBF summary OK: ' . json_encode($tbf) . PHP_EOL;
} catch (Throwable $e) {
    echo 'TBF ERROR: ' . $e->getMessage() . PHP_EOL;
}
