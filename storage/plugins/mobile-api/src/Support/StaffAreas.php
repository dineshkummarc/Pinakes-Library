<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Single source of truth for the Mobile API 1.7.0 staff areas.
 *
 * MobileApiPlugin registers an area's routes only when it is listed in
 * IMPLEMENTED, and HealthController advertises `staff_<area>` = true only for
 * an implemented area whose prerequisite (plugin active, table present) holds.
 * A flag can therefore never promise routes that do not exist.
 */
final class StaffAreas
{
    /** Every staff area the 1.7.0 contract names (health flag = `staff_<area>`). */
    public const ALL = [
        'circulation', 'copies', 'series', 'merges', 'labels', 'imports', 'covers',
        'desiderata', 'archives', 'periodicals', 'bookclub', 'frbr', 'digital_library', 'librarything',
    ];

    /** Areas whose routes this build registers. */
    public const IMPLEMENTED = ['circulation', 'copies', 'series', 'merges', 'labels', 'imports', 'covers', 'librarything'];

    private function __construct()
    {
    }

    public static function implemented(string $area): bool
    {
        return in_array($area, self::IMPLEMENTED, true);
    }

    /**
     * The `features.staff_*` flags: true only for an implemented area whose
     * prerequisite holds.
     *
     * @param array<string, bool> $prerequisites area => runtime condition (missing = true)
     * @return array<string, bool>
     */
    public static function flags(array $prerequisites): array
    {
        $flags = [];
        foreach (self::ALL as $area) {
            $flags['staff_' . $area] = self::implemented($area) && ($prerequisites[$area] ?? true);
        }

        return $flags;
    }
}
