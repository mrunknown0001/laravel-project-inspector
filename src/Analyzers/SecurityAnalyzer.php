<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Exception\FileAccessException;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Parsing\PhpSource;
use LaraInspect\Security\Rules\BladeOutputRule;
use LaraInspect\Security\Rules\ConfigurationRule;
use LaraInspect\Security\Rules\DangerousCodeRule;
use LaraInspect\Security\Rules\DependencyRule;
use LaraInspect\Security\Rules\HardcodedSecretsRule;
use LaraInspect\Security\Rules\MassAssignmentRule;
use LaraInspect\Security\Rules\MiddlewareRule;
use LaraInspect\Security\Rules\PermissionsRule;
use LaraInspect\Security\Rules\ProjectRuleInterface;
use LaraInspect\Security\Rules\RouteSecurityRule;
use LaraInspect\Security\Rules\SensitiveFilesRule;
use LaraInspect\Security\Rules\SourceFile;
use LaraInspect\Security\Rules\SourceRuleInterface;

/**
 * Rule-based security checks. Project rules look at configuration, files and
 * routes; source rules see each source file once (parsed once).
 *
 * Findings are "confirmed" only when the evidence proves the condition (e.g.
 * a value read from .env); pattern-based code findings are "potential".
 */
final class SecurityAnalyzer implements AnalyzerInterface
{
    private const SOURCE_EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'json', 'yml', 'yaml', 'xml', 'neon', 'ini'];

    private const SKIP_FILES = '#(^|/)(composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$|\.min\.js$|\.map$#';

    /** Root-level files worth scanning for secrets. */
    private const ROOT_FILES = '#^(\.env[.\-_].+|auth\.json|docker-compose[^/]*\.ya?ml|\.gitlab-ci\.yml|bitbucket-pipelines\.yml|phpunit\.xml(\.dist)?|[^/]+\.(json|ya?ml|neon|ini|php))$#';

    private const MAX_FILE_BYTES = 524_288;

    /** @var list<ProjectRuleInterface> */
    private readonly array $projectRules;

    /** @var list<SourceRuleInterface> */
    private readonly array $sourceRules;

    /**
     * @param list<ProjectRuleInterface>|null $projectRules
     * @param list<SourceRuleInterface>|null  $sourceRules
     */
    public function __construct(
        ?array $projectRules = null,
        ?array $sourceRules = null,
        private readonly int $maxFiles = 5000,
    ) {
        $this->projectRules = $projectRules ?? [
            new ConfigurationRule(),
            new SensitiveFilesRule(),
            new PermissionsRule(),
            new RouteSecurityRule(),
            new MiddlewareRule(),
            new DependencyRule(),
        ];
        $this->sourceRules = $sourceRules ?? [
            new HardcodedSecretsRule(),
            new DangerousCodeRule(),
            new MassAssignmentRule(),
            new BladeOutputRule(),
        ];
    }

    public function name(): string
    {
        return 'security';
    }

    public function title(): string
    {
        return 'Security';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $findings = [];
        $notes = [];

        foreach ($this->projectRules as $rule) {
            try {
                array_push($findings, ...$rule->check($context));
            } catch (\Throwable $e) {
                $notes[] = sprintf('%s failed: %s', self::shortName($rule), $e->getMessage());
            }
            if ($rule instanceof PermissionsRule && $rule->skipReason() !== null) {
                $notes[] = $rule->skipReason();
            }
        }

        [$files, $truncated] = $this->filesToScan($context);
        $tracked = $context->trackedFiles();
        $trackedIndex = $tracked === null ? null : array_flip($tracked);
        $php = new PhpSource();
        $parsed = 0;
        $unparsable = [];
        foreach ($this->sourceRules as $rule) {
            $rule->reset();
        }
        foreach ($files as $file) {
            try {
                $contents = $context->files->read($file, self::MAX_FILE_BYTES);
            } catch (FileAccessException) {
                continue;
            }
            $stmts = null;
            if (str_ends_with($file, '.php') && !str_ends_with($file, '.blade.php')) {
                $stmts = $php->parse($contents);
                $stmts === null ? $unparsable[] = $file : $parsed++;
            }
            // Untracked files are scanned too (work in progress matters), but labelled.
            $label = $trackedIndex !== null && !isset($trackedIndex[$file]) ? $file . SourceFile::UNTRACKED_SUFFIX : $file;
            foreach ($this->sourceRules as $rule) {
                $rule->visit($label, $contents, $stmts);
            }
        }
        foreach ($this->sourceRules as $rule) {
            try {
                array_push($findings, ...$rule->findings($context));
            } catch (\Throwable $e) {
                $notes[] = sprintf('%s failed: %s', self::shortName($rule), $e->getMessage());
            }
        }

        if ($tracked === null) {
            $notes[] = 'Git tracking could not be determined (not a git checkout, git unavailable, or the repository is not trusted by git), so "committed file" checks were skipped.';
        }
        if ($truncated) {
            $notes[] = sprintf('Only the first %d source files were scanned.', $this->maxFiles);
        }
        if ($unparsable !== []) {
            $notes[] = sprintf('%d PHP files could not be parsed and were only scanned as text: %s', count($unparsable), implode(', ', array_slice($unparsable, 0, 5)) . (count($unparsable) > 5 ? ', ...' : ''));
        }
        $notes[] = 'tests/ is not scanned. Code findings are pattern-based and local to single expressions; they need manual review and do not prove exploitability.';

        $byRule = [];
        foreach ($findings as $finding) {
            $byRule[$finding->id] = [$finding->id, $finding->severity->value, $finding->confidence->value, $finding->title];
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: [
                new Fact('Project rules', (string) count($this->projectRules), Source::Detected, null),
                new Fact('Source rules', (string) count($this->sourceRules), Source::Detected, null),
                new Fact('Files scanned', (string) count($files), Source::Detected, implode(', ', $context->sourceDirectories())),
                new Fact('PHP files parsed', (string) $parsed, Source::Detected, null),
                new Fact('Git tracking', $tracked === null ? 'Unknown' : sprintf('Known (%d tracked files)', count($tracked)), Source::Detected, 'git ls-files'),
            ],
            tables: [new Table('Security checks with findings', ['Rule', 'Severity', 'Confidence', 'Finding'], array_values($byRule))],
            findings: $findings,
            notes: $notes,
            data: ['files_scanned' => count($files), 'php_parsed' => $parsed, 'rules' => array_map(static fn (Finding $f): string => $f->id, $findings)],
        );
    }

    /**
     * @return array{0: list<string>, 1: bool} files, truncated
     */
    private function filesToScan(InspectionContext $context): array
    {
        $files = $context->sourceFiles(self::SOURCE_EXTENSIONS);
        foreach ($context->files->findFiles('', [], false) as $root) {
            if ($root !== '.env' && preg_match(self::ROOT_FILES, $root) === 1) {
                $files[] = $root;
            }
        }
        $files = array_values(array_filter(array_unique($files), static fn (string $f): bool => preg_match(self::SKIP_FILES, $f) !== 1));

        sort($files);

        return [array_slice($files, 0, $this->maxFiles), count($files) > $this->maxFiles];
    }

    private static function shortName(object $rule): string
    {
        $parts = explode('\\', $rule::class);

        return (string) end($parts);
    }
}
