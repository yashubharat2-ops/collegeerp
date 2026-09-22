<?php

namespace App\Domain\Library\Support;

use App\Models\College;

/**
 * Serialize library writes for one college.
 *
 * Called inside an open transaction. Locking the college row makes the
 * uniqueness and "one open issue per copy" checks safe on engines that cannot
 * express partial unique indexes (MySQL/MariaDB), the same approach Academic
 * Year overlap uses. SQLite/PostgreSQL also have the partial indexes as a
 * backstop.
 */
final class CollegeRowLock
{
    public static function acquire(int $collegeId): void
    {
        College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    }
}
