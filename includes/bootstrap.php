<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Auth.php';

applyCorsHeaders();
startAppSession();
