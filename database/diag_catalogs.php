<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
echo 'faculty userProfile: ' . $pdo->query("SELECT COUNT(*) FROM userProfile WHERE role='Faculty' AND status='Active'")->fetchColumn() . PHP_EOL;
echo 'rooms: ' . $pdo->query('SELECT COUNT(*) FROM room')->fetchColumn() . PHP_EOL;

$dept = $pdo->query("SELECT uid, name FROM department WHERE name LIKE '%GENED%' OR uid='dept-gened' LIMIT 1")->fetch();
if ($dept) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM userProfile WHERE role='Faculty' AND status='Active' AND departmentId = ?");
    $st->execute([(string) $dept['uid']]);
    echo 'GENED (' . $dept['name'] . ') faculty in userProfile: ' . $st->fetchColumn() . PHP_EOL;

    $st2 = $pdo->prepare(
        "SELECT COUNT(*) FROM departmentUser du
         INNER JOIN user u ON u.uid = du.userId
         WHERE u.role = 'Faculty' AND u.status = 'Active' AND du.departmentId = ?"
    );
    $st2->execute([(string) $dept['uid']]);
    echo 'GENED faculty in departmentUser: ' . $st2->fetchColumn() . PHP_EOL;
}

$missing = $pdo->query(
    "SELECT u.uid, u.email FROM user u
     INNER JOIN departmentUser du ON du.userId = u.uid
     LEFT JOIN userProfile up ON up.uid = u.uid
     WHERE u.role = 'Faculty' AND u.status = 'Active' AND up.uid IS NULL
     LIMIT 5"
)->fetchAll();
echo 'Faculty in departmentUser but NOT in userProfile: ' . count($missing) . PHP_EOL;
foreach ($missing as $m) {
    echo '  ' . $m['email'] . PHP_EOL;
}

$noFaculty = $pdo->query(
    "SELECT u.uid, u.email FROM user u
     INNER JOIN userProfile up ON up.uid = u.uid
     LEFT JOIN faculty f ON f.userId = u.uid
     WHERE u.role = 'Faculty' AND f.userId IS NULL
     LIMIT 5"
)->fetchAll();
echo 'Faculty users missing faculty row: ' . count($noFaculty) . PHP_EOL;
