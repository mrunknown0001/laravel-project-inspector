<?php

declare(strict_types=1);

namespace LaraInspect\Support;

/**
 * Laravel's published support windows.
 *
 * Source: https://laravel.com/docs/13.x/releases#support-policy (checked 2026-11-01).
 * Versions 8 and 9 come from the policy tables in earlier releases of the docs.
 * Where the docs give only a quarter ("Q3 2027"), the last day of that quarter is used.
 */
final class LaravelSupportPolicy
{
    public const SOURCE_URL = 'https://laravel.com/docs/releases#support-policy';

    /** @var array<int, array{php: string, released: string, bug_fixes_until: string, security_fixes_until: string}> */
    private const VERSIONS = [
        8 => ['php' => '7.3 - 8.1', 'released' => '2020-09-08', 'bug_fixes_until' => '2022-07-26', 'security_fixes_until' => '2023-01-24'],
        9 => ['php' => '8.0 - 8.2', 'released' => '2022-02-08', 'bug_fixes_until' => '2023-08-08', 'security_fixes_until' => '2024-02-06'],
        10 => ['php' => '8.1 - 8.3', 'released' => '2023-02-14', 'bug_fixes_until' => '2024-08-06', 'security_fixes_until' => '2025-02-04'],
        11 => ['php' => '8.2 - 8.4', 'released' => '2024-03-12', 'bug_fixes_until' => '2025-09-03', 'security_fixes_until' => '2026-03-12'],
        12 => ['php' => '8.2 - 8.5', 'released' => '2025-02-24', 'bug_fixes_until' => '2026-08-13', 'security_fixes_until' => '2027-02-24'],
        13 => ['php' => '8.3 - 8.5', 'released' => '2026-03-17', 'bug_fixes_until' => '2027-09-30', 'security_fixes_until' => '2028-03-17'],
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SECURITY_ONLY = 'security-fixes-only';
    public const STATUS_END_OF_LIFE = 'end-of-life';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @return array{php: string, released: string, bug_fixes_until: string, security_fixes_until: string}|null
     */
    public static function for(int $major): ?array
    {
        return self::VERSIONS[$major] ?? null;
    }

    public static function status(int $major, \DateTimeImmutable $now): string
    {
        $policy = self::for($major);
        if ($policy === null) {
            // Anything older than the table is long out of support; newer is not yet known.
            return $major < array_key_first(self::VERSIONS) ? self::STATUS_END_OF_LIFE : self::STATUS_UNKNOWN;
        }

        $today = $now->format('Y-m-d');

        return match (true) {
            $today > $policy['security_fixes_until'] => self::STATUS_END_OF_LIFE,
            $today > $policy['bug_fixes_until'] => self::STATUS_SECURITY_ONLY,
            default => self::STATUS_ACTIVE,
        };
    }
}
