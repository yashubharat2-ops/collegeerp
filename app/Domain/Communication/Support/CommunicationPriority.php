<?php

namespace App\Domain\Communication\Support;

/**
 * Priority levels shared by Notices and internal Notifications.
 *
 * A closed, ordered set (normal < important < urgent) so that listings can be
 * filtered and badged consistently across the Communication module.
 */
final class CommunicationPriority
{
    public const NORMAL = 'normal';

    public const IMPORTANT = 'important';

    public const URGENT = 'urgent';

    public const ALL = [self::NORMAL, self::IMPORTANT, self::URGENT];

    public static function label(string $priority): string
    {
        return ucfirst($priority);
    }
}
