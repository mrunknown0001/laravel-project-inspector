<?php

declare(strict_types=1);

namespace LaraInspect\Support;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Thin wrappers over composer/semver that never throw on malformed input.
 */
final class Versions
{
    private static ?VersionParser $parser = null;

    /**
     * "v10.48.4" => "10.48.4"; leaves dev versions such as "dev-main" untouched.
     */
    public static function clean(string $version): string
    {
        return preg_replace('/^v(?=\d)/i', '', trim($version)) ?? $version;
    }

    public static function major(string $version): ?int
    {
        return preg_match('/^v?(\d+)/i', trim($version), $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Lowest major version a constraint allows, e.g. "^10.10" => 10, ">=8.1 <9" => 8.
     */
    public static function constraintLowestMajor(string $constraint): ?int
    {
        $parsed = self::parse($constraint);
        if ($parsed === null) {
            return null;
        }
        $lower = $parsed->getLowerBound()->getVersion();

        return $lower === '0.0.0.0-dev' ? null : self::major($lower);
    }

    /**
     * Whether a concrete version satisfies a constraint; null when either cannot be parsed.
     */
    public static function satisfies(string $version, string $constraint): ?bool
    {
        if (str_starts_with($version, 'dev-') || self::parse($constraint) === null) {
            return null;
        }
        try {
            return Semver::satisfies(self::clean($version), $constraint);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Whether every version allowed by $inner is also allowed by $outer.
     */
    public static function isSubsetOf(string $inner, string $outer): ?bool
    {
        $a = self::parse($inner);
        $b = self::parse($outer);
        if ($a === null || $b === null) {
            return null;
        }

        return Intervals::isSubsetOf($a, $b);
    }

    /**
     * Whether at least one version satisfies both constraints.
     */
    public static function intersects(string $first, string $second): ?bool
    {
        $a = self::parse($first);
        $b = self::parse($second);
        if ($a === null || $b === null) {
            return null;
        }

        return Intervals::haveIntersections($a, $b);
    }

    public static function parse(string $constraint): ?ConstraintInterface
    {
        try {
            return (self::$parser ??= new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * "Composer version 2.10.3 2026-08-27 13:34:23" => "2.10.3"; "v22.1.0" => "22.1.0".
     */
    public static function extract(string $output): ?string
    {
        return preg_match('/v?(\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.\-]+)?)/', $output, $m) === 1 ? $m[1] : null;
    }
}
