<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\PhpSource;
use LaraInspect\Support\Evidence;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * CSRF exclusions and trusted proxies, in both the classic middleware classes
 * and Laravel 11+ bootstrap/app.php configuration.
 */
final class MiddlewareRule implements ProjectRuleInterface
{
    private const BROAD_PATTERNS = ['*', '/*', '/', '*/*'];

    public function check(InspectionContext $context): array
    {
        $php = new PhpSource();
        [$csrfExcept, $csrfOrigin] = $this->csrfExceptions($context, $php);
        [$proxies, $proxiesOrigin] = $this->trustedProxies($context, $php);
        $findings = [];

        $broad = array_values(array_intersect($csrfExcept, self::BROAD_PATTERNS));
        if ($broad !== []) {
            $findings[] = new Finding(
                'sec.csrf-disabled',
                'CSRF protection is disabled for all routes',
                Severity::High,
                Confidence::Confirmed,
                $csrfOrigin,
                'The CSRF exclusion list contains a wildcard that matches every route. Any website a logged-in user visits can submit forms to the application on their behalf.',
                sprintf('Excluded URIs: %s', implode(', ', $csrfExcept)),
                'Remove the wildcard and exclude only specific endpoints that verify requests another way (e.g. signed webhooks).',
            );
        } elseif ($csrfExcept !== []) {
            $findings[] = new Finding(
                'sec.csrf-exclusions',
                sprintf('%s excluded from CSRF protection', Evidence::plural(count($csrfExcept), 'URI pattern is', 'URI patterns are')),
                Severity::Info,
                Confidence::Confirmed,
                $csrfOrigin,
                'Excluded web routes accept cross-site form submissions. This is normal for webhooks that verify a signature, but not for routes used by logged-in users.',
                Evidence::list($csrfExcept),
                'Check that every excluded endpoint authenticates requests by other means, such as a webhook signature.',
            );
        }

        if (in_array('*', $proxies, true) || in_array('**', $proxies, true)) {
            $findings[] = new Finding(
                'sec.trust-all-proxies',
                'All proxies are trusted',
                Severity::Low,
                Confidence::Potential,
                $proxiesOrigin,
                'Trusting every proxy makes Laravel believe the X-Forwarded-For/-Proto/-Host headers of any request. That is correct only behind a load balancer that strips client-supplied headers. Otherwise clients can spoof their IP address (bypassing IP-based rate limits and logs) or the scheme and host.',
                sprintf('proxies = %s', implode(', ', $proxies)),
                'List the load balancer\'s addresses explicitly, or confirm that the application is reachable only through a proxy that overwrites these headers.',
            );
        }

        return $findings;
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function csrfExceptions(InspectionContext $context, PhpSource $php): array
    {
        foreach (['app/Http/Middleware/VerifyCsrfToken.php', 'app/Http/Middleware/PreventRequestForgery.php'] as $file) {
            $stmts = $context->files->isFile($file) ? $php->parseFile($context->files, $file) : null;
            if ($stmts !== null) {
                $except = $this->propertyStrings($stmts, 'except');
                if ($except !== null) {
                    return [$except, $file];
                }
            }
        }

        $except = $this->bootstrapArgument($context, $php, ['validateCsrfTokens', 'preventRequestForgery'], 'except');

        return [$except, 'bootstrap/app.php'];
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function trustedProxies(InspectionContext $context, PhpSource $php): array
    {
        $file = 'app/Http/Middleware/TrustProxies.php';
        $stmts = $context->files->isFile($file) ? $php->parseFile($context->files, $file) : null;
        if ($stmts !== null) {
            $proxies = $this->propertyStrings($stmts, 'proxies');
            if ($proxies !== null) {
                return [$proxies, $file];
            }
        }

        return [$this->bootstrapArgument($context, $php, ['trustProxies'], 'at'), 'bootstrap/app.php'];
    }

    /**
     * Literal default of a class property, e.g. protected $except = ['webhook/*'];
     *
     * @param list<Node\Stmt> $stmts
     * @return list<string>|null
     */
    private function propertyStrings(array $stmts, string $name): ?array
    {
        foreach ((new NodeFinder())->findInstanceOf($stmts, Stmt\Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name && $prop->default !== null) {
                    return PhpSource::strings($prop->default);
                }
            }
        }

        return null;
    }

    /**
     * Strings passed to a middleware configuration call in bootstrap/app.php,
     * e.g. $middleware->validateCsrfTokens(except: ['stripe/*']).
     *
     * @param list<string> $methods
     * @return list<string>
     */
    private function bootstrapArgument(InspectionContext $context, PhpSource $php, array $methods, string $argument): array
    {
        $stmts = $php->parseFile($context->files, 'bootstrap/app.php');
        if ($stmts === null) {
            return [];
        }
        $values = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), $methods, true)) {
                array_push($values, ...PhpSource::strings(PhpSource::arg($call, 0, $argument)));
            }
        }

        return $values;
    }
}
