<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\ProductProfileDao;
use DateTimeImmutable;

/**
 * Posting scheduler — preserved for future cron rework. Returns no due profiles while disabled.
 */
class PostingSchedulerService
{
    public function __construct(
        private readonly ProductProfileDao $profileDao
    ) {
    }

    /**
     * @return array<int, array{profile: array, schedule: array}>
     */
    public function getDueProfiles(?DateTimeImmutable $now = null): array
    {
        unset($now);

        return [];
    }
}
