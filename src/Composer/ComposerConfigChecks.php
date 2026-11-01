<?php

declare(strict_types=1);

namespace LaraInspect\Composer;

use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Support\Arr;
use LaraInspect\Support\Evidence;

/**
 * Insecure Composer settings in composer.json (TLS, HTTP repositories, stability, plugins, ignored advisories).
 * Shared by the dependency and security analyzers.
 */
final class ComposerConfigChecks
{
    /**
     * @return list<Finding>
     */
    public static function findings(ComposerProject $composer): array
    {
        $config = $composer->config();
        $manifest = $composer->manifest();
        $findings = [];

        if (($config['disable-tls'] ?? false) === true) {
            $findings[] = new Finding(
                'deps.tls-disabled',
                'Composer TLS verification is disabled',
                Severity::High,
                Confidence::Confirmed,
                'composer.json',
                'With "disable-tls" enabled, package downloads can be intercepted and replaced in transit.',
                '"config": {"disable-tls": true}',
                'Remove "disable-tls" from composer.json and fix the underlying certificate problem instead.',
            );
        }
        if (($config['secure-http'] ?? true) === false) {
            $findings[] = new Finding(
                'deps.insecure-http',
                'Composer allows plain HTTP downloads',
                Severity::High,
                Confidence::Confirmed,
                'composer.json',
                'With "secure-http" disabled, packages may be fetched over unencrypted HTTP and tampered with.',
                '"config": {"secure-http": false}',
                'Remove "secure-http": false and serve private repositories over HTTPS.',
            );
        }

        $insecureRepositories = [];
        foreach (Arr::array($manifest, 'repositories') as $key => $repository) {
            if (is_array($repository) && str_starts_with(strtolower(Arr::string($repository, 'url')), 'http://')) {
                $insecureRepositories[] = sprintf('%s: %s', is_string($key) ? $key : Arr::string($repository, 'type', 'repository'), Arr::string($repository, 'url'));
            }
        }
        if ($insecureRepositories !== []) {
            $findings[] = new Finding(
                'deps.http-repository',
                'A Composer repository uses plain HTTP',
                Severity::Medium,
                Confidence::Confirmed,
                'composer.json',
                'Packages from HTTP repositories can be modified in transit.',
                Evidence::list($insecureRepositories),
                'Switch the repository URL to HTTPS.',
            );
        }

        $stability = strtolower(Arr::string($manifest, 'minimum-stability', 'stable'));
        if (in_array($stability, ['dev', 'alpha', 'beta', 'rc'], true) && Arr::bool($manifest, 'prefer-stable') !== true) {
            $findings[] = new Finding(
                'deps.unstable-minimum-stability',
                sprintf('minimum-stability is "%s" without prefer-stable', $stability),
                Severity::Low,
                Confidence::Potential,
                'composer.json',
                'Composer may resolve pre-release or development versions of any dependency, which receive less testing and security review.',
                sprintf('"minimum-stability": "%s", "prefer-stable" not set to true.', $stability),
                'Set "prefer-stable": true, or use per-package stability flags (e.g. "vendor/pkg": "^1.0@beta") instead.',
            );
        }

        if (($config['allow-plugins'] ?? null) === true) {
            $findings[] = new Finding(
                'deps.all-plugins-allowed',
                'All Composer plugins are allowed to run',
                Severity::Low,
                Confidence::Potential,
                'composer.json',
                'Composer plugins execute code during install and update. Allowing every plugin means a compromised or newly added dependency can run code without review.',
                '"config": {"allow-plugins": true}',
                'Replace true with an explicit map of trusted plugins, e.g. {"pestphp/pest-plugin": true}.',
            );
        }

        $ignored = Arr::array(Arr::array($config, 'audit'), 'ignore');
        if ($ignored !== []) {
            $findings[] = new Finding(
                'deps.audit-ignores',
                sprintf('%d security advisor%s ignored in composer.json', count($ignored), count($ignored) === 1 ? 'y is' : 'ies are'),
                Severity::Info,
                Confidence::Confirmed,
                'composer.json',
                'Ignored advisories are hidden from "composer audit". Make sure each ignore is still justified.',
                Evidence::list(array_map(
                    static fn (int|string $k, mixed $v): string => is_string($k) ? sprintf('%s: %s', $k, is_scalar($v) ? (string) $v : 'ignored') : (is_scalar($v) ? (string) $v : ''),
                    array_keys($ignored),
                    array_values($ignored),
                )),
                'Review the ignore list in config.audit.ignore and remove entries that no longer apply.',
            );
        }

        return $findings;
    }
}
