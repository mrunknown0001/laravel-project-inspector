<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\RouteAnalyzer;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Severity;
use LaraInspect\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class RouteAnalyzerTest extends TestCase
{
    private function analyze(string $fixture): AnalyzerResult
    {
        return (new RouteAnalyzer())->analyze(Fixtures::context(Fixtures::project($fixture)));
    }

    private static function fact(AnalyzerResult $result, string $label): string
    {
        foreach ($result->facts as $fact) {
            if ($fact->label === $label) {
                return $fact->value;
            }
        }
        self::fail("Missing fact {$label}");
    }

    public function testInventoryCounts(): void
    {
        $result = $this->analyze('laravel-11');

        self::assertSame('16', self::fact($result, 'Total routes'));
        self::assertSame('7', self::fact($result, 'API routes'));
        self::assertSame('Static analysis (application not booted)', self::fact($result, 'Route source'));
        self::assertSame(16, $result->data['counts']['total']);
        self::assertSame(['ANY' => 1, 'DELETE' => 2, 'GET' => 10, 'PATCH' => 2, 'POST' => 1, 'PUT' => 1], $result->data['counts']['by_method']);
        self::assertStringContainsString('Livewire', implode(' ', $result->notes), 'Packages that add their own routes are listed.');
        self::assertSame([], $result->findings);
    }

    public function testReviewTableListsOnlyRoutesThatNeedIt(): void
    {
        $result = $this->analyze('insecure-app');
        $review = null;
        foreach ($result->tables as $table) {
            if ($table->title === 'Routes requiring review') {
                $review = $table;
            }
        }
        self::assertNotNull($review);
        $uris = array_column($review->rows, 1);

        self::assertContains('admin/users', $uris);
        self::assertContains('phpinfo', $uris);
        self::assertContains('api/orders/{order}/refund', $uris);
        self::assertNotContains('api/login', $uris);
        self::assertNotContains('reports', $uris, 'POST /reports has auth middleware.');
    }

    public function testDuplicateAndMissingControllerFindings(): void
    {
        $findings = [];
        foreach ($this->analyze('insecure-app')->findings as $finding) {
            $findings[$finding->id] = $finding;
        }

        self::assertSame(Severity::Low, $findings['routes.duplicate']->severity);
        self::assertStringContainsString('GET admin/users: routes/web.php:10 overrides routes/web.php:9', $findings['routes.duplicate']->evidence);
        self::assertStringContainsString('App\\Http\\Controllers\\SettingsController', $findings['routes.missing-controller']->evidence);
    }
}
