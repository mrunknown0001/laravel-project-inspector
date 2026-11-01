<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Support\Evidence;

/**
 * Unescaped Blade output ({!! ... !!}), the usual source of stored XSS in Laravel apps.
 */
final class BladeOutputRule implements SourceRuleInterface
{
    /** Expressions that are HTML by construction. */
    private const SAFE = '/^(csrf_field\(\)|method_field\(.*\)|\$slot|\$attributes.*|\$errors->.*|__\(.*\)|trans\(.*\)|@?json\(.*\)|json_encode\(.*\)|Js::from\(.*\)|Vite::.*|vite\(.*\)|\$.*->links\(.*\)|nl2br\(e\(.*\)\)|e\(.*\)|Illuminate\\\\.*|\$html|\$svg|svg\(.*\))$/i';

    /** @var list<string> */
    private array $withInput = [];

    /** @var list<string> */
    private array $unescaped = [];

    public function reset(): void
    {
        $this->withInput = [];
        $this->unescaped = [];
    }

    public function visit(string $file, string $contents, ?array $stmts): void
    {
        $path = SourceFile::path($file);
        if (!str_ends_with($path, '.blade.php')) {
            return;
        }
        if (preg_match_all('/\{!!\s*(.+?)\s*!!\}/s', $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($matches as $match) {
            $expression = trim($match[1][0]);
            if (preg_match(self::SAFE, $expression) === 1) {
                continue;
            }
            $where = Evidence::location($file, substr_count($contents, "\n", 0, $match[0][1]) + 1, '{!! ' . $expression . ' !!}');
            if (preg_match('/\brequest\(|\$request\b|\$_(GET|POST|REQUEST|COOKIE)|\bold\(/', $expression) === 1) {
                $this->withInput[] = $where;
            } else {
                $this->unescaped[] = $where;
            }
        }
    }

    public function findings(InspectionContext $context): array
    {
        $findings = [];
        if ($this->withInput !== []) {
            $findings[] = new Finding(
                'sec.blade-unescaped-input',
                'Request input is echoed without escaping',
                Severity::High,
                Confidence::Potential,
                'resources/views',
                '{!! !!} prints raw HTML. Echoing request input this way allows reflected cross-site scripting.',
                Evidence::list($this->withInput),
                'Use {{ }} (escaped output) for anything derived from input.',
            );
        }
        if ($this->unescaped !== []) {
            $findings[] = new Finding(
                'sec.blade-unescaped-output',
                Evidence::plural(count($this->unescaped), 'Blade expression prints', 'Blade expressions print') . ' unescaped HTML',
                Severity::Low,
                Confidence::Potential,
                'resources/views',
                '{!! !!} bypasses escaping. That is fine for HTML the application generates itself, but stored user content (comments, profile fields, rich text) printed this way allows stored XSS.',
                Evidence::list($this->unescaped),
                'Use {{ }} unless the value is trusted HTML. For user-supplied rich text, sanitise it on save (e.g. with an HTML purifier) before printing it raw.',
            );
        }

        return $findings;
    }
}
