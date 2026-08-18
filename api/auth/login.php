<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = requestBody();
$email = trim((string) ($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($email === '' || $password === '') {
    jsonError('Email and password are required.', 422);
}

$stmt = db()->prepare(
    'SELECT uid, departmentId, firstName, lastName, email, role, phoneNumber, status, passwordHash
     FROM userProfile
     WHERE email = :email
     LIMIT 1'
);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, (string) $user['passwordHash'])) {
    jsonError('Invalid email or password.', 401);
}

if (($user['status'] ?? '') !== 'Active') {
    jsonError('Account is not active.', 403);
}

$authUser = normalizeAuthUser($user);
$_SESSION['user'] = $authUser;

$token = issueApiToken((string) $user['uid']);

logAudit(
    (string) $user['uid'],
    'LOGIN',
    'auth',
    'User logged in successfully.'
);

unset($user['passwordHash']);

jsonSuccess([
    'user' => publicUser($user),
    'token' => $token,
    'role' => $authUser['role'],
]);
