<?php

declare(strict_types=1);

/**
 * Align legacy enrollment rows with assigned/distributed statuses.
 * Removes the old seed enrollment so Sam appears on the pending list when cleared.
 *
 *   php database/migrate_enrollment_status.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$updated = db()->exec(
    "UPDATE enrollment
     SET status = 'distributed'
     WHERE LOWER(status) = 'active'"
);

$deleted = db()->exec(
    "DELETE FROM enrollment
     WHERE uid = '66666666-6666-4666-8666-666666666601'"
);

echo "Updated {$updated} Active → distributed; removed seed enrollment rows: {$deleted}.\n";
