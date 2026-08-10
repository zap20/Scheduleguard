<?php

declare(strict_types=1);

/**
 * One-time: map legacy schedule.status "Active" → "confirmed".
 *   php database/migrate_schedule_status.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$updated = db()->exec(
    "UPDATE schedule SET status = 'confirmed' WHERE LOWER(status) = 'active'"
);

echo "Updated {$updated} schedule row(s) from Active to confirmed.\n";
