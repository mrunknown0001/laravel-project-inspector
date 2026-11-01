<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\DatabaseAnalyzer;
use LaraInspect\Database\MigrationParser;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class DatabaseAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * @return array<string, Finding>
     */
    private static function findings(AnalyzerResult $result): array
    {
        $map = [];
        foreach ($result->findings as $finding) {
            $map[$finding->id] = $finding;
        }

        return $map;
    }

    public function testMigrationAnalysisOnInsecureFixture(): void
    {
        $result = (new DatabaseAnalyzer())->analyze(Fixtures::context(Fixtures::project('insecure-app')));
        $findings = self::findings($result);

        self::assertSame('mysql', $result->data['default_connection']);
        self::assertSame('MySQL', $result->data['technology']);
        self::assertSame(1, $result->data['migrations']);
        self::assertSame(['orders'], $result->data['tables']);
        self::assertSame(1, $result->data['foreign_keys']);

        $missing = $findings['db.missing-index'];
        self::assertSame(Severity::Low, $missing->severity);
        self::assertStringContainsString('orders.user_id', $missing->evidence);
        self::assertStringContainsString('orders.coupon_id', $missing->evidence);
        self::assertStringNotContainsString('warehouse_id', $missing->evidence, 'Leading column of a composite index counts as indexed.');
        self::assertStringNotContainsString('product_id', $missing->evidence, 'Constrained foreign keys are not flagged.');

        self::assertStringContainsString('orders.coupon_id', $findings['db.unconstrained-foreign-id']->evidence);
        self::assertArrayHasKey('db.raw-sql-migrations', $findings);
        self::assertStringContainsString('not connected', $result->notes[0]);
    }

    public function testParserHandlesBlueprintVariants(): void
    {
        $root = $this->makeProject([
            'database/migrations/2024_01_01_000001_comments.php' => <<<'PHP'
                <?php
                use App\Models\BlogPost;
                return new class {
                    public function up(): void
                    {
                        Schema::connection('tenant')->create('comments', function (Blueprint $t) {
                            $t->bigIncrements('id');
                            $t->foreignIdFor(BlogPost::class)->constrained();
                            $t->morphs('commentable');
                            $t->uuid('author_id')->index();
                            $t->unsignedBigInteger('editor_id');
                            $t->foreign('editor_id')->references('id')->on('users');
                        });
                        Schema::table('comments', fn (Blueprint $t) => $t->unsignedInteger('legacy_id'));
                    }
                };
                PHP,
            'database/migrations/broken.php' => '<?php this is not valid',
        ]);
        $summary = (new MigrationParser(Fixtures::reader($root)))->parse(['database/migrations/2024_01_01_000001_comments.php', 'database/migrations/broken.php']);

        $table = $summary->tables['comments'];
        self::assertArrayHasKey('blog_post_id', $table->columns);
        self::assertTrue($table->hasForeignKey('blog_post_id'));
        self::assertTrue($table->isIndexed('commentable_id'));
        self::assertTrue($table->isIndexed('author_id'));
        self::assertTrue($table->hasForeignKey('editor_id'));
        self::assertSame(['legacy_id'], array_column($summary->unindexedKeyColumns(), 'column'));
        self::assertSame(['database/migrations/broken.php'], $summary->unparsed);
        self::assertSame(2, $summary->foreignKeyCount);
    }

    public function testUndefinedDefaultConnectionAndPostgresIndexes(): void
    {
        $root = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^10.0"}}',
            '.env' => "DB_CONNECTION=pgsql\n",
            'config/database.php' => "<?php return ['default' => env('DB_CONNECTION', 'mysql'), 'connections' => ['mysql' => ['driver' => 'mysql']]];",
        ]);
        self::assertSame(Severity::Medium, self::findings((new DatabaseAnalyzer())->analyze(Fixtures::context($root)))['db.default-connection-undefined']->severity);

        $pg = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^10.0"}}',
            '.env' => "DB_CONNECTION=pgsql\n",
            'config/database.php' => "<?php return ['default' => env('DB_CONNECTION'), 'connections' => ['pgsql' => ['driver' => 'pgsql']]];",
            'database/migrations/x.php' => "<?php Schema::create('posts', function (\$table) { \$table->foreignId('user_id')->constrained(); });",
        ]);
        self::assertStringContainsString('posts.user_id', self::findings((new DatabaseAnalyzer())->analyze(Fixtures::context($pg)))['db.pgsql-foreign-key-index']->evidence);
    }
}
