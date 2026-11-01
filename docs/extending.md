# Extending the inspector

The code is organised so that new checks and providers are additions, not edits.

```
src/
  Analyzers/        one class per report section (AnalyzerInterface)
  Security/Rules/   security checks used by the security analyzer
  AI/Providers/     one adapter per AI provider
  AI/Tasks/         AI task definitions
  Reports/          one class per output format (ReporterInterface)
  Core/             detection, context, inspector, registry
  Parsing/          static readers for .env, config files and PHP
  Routing/          static route reconstruction
  Process/          allowlisted, audited command runner
  Security/         path guard, safe file reader, secret redactor
```

Run `composer check` (PHPStan level 8 plus the test suite) before submitting changes.

## Rules every extension must follow

- Read project files only through `$context->files` (`SafeFileReader`); never use `file_get_contents`
  on project paths or write anywhere in the project.
- Never `include`/`require` project files. Parse them with `LaraInspect\Parsing\PhpSource`.
- Run external commands only through `$context->processes` with a `CommandSpec`. Set
  `sideEffects: true` (with a `warning`) for anything that executes project code or could write,
  and `network: true` for anything that contacts a remote service.
- Read `.env` values only through `$context->env()`; non-allowlisted values are deliberately not
  available.
- Use `Confidence::Confirmed` only when the evidence proves the condition; otherwise
  `Confidence::Potential`. Put secrets in evidence only masked (`SecretPatterns::mask()`). The
  report is redacted again at the end, but do not rely on that.

## A new analyzer

```php
namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\{AnalyzerResult, Confidence, Fact, Finding, Severity, Source};

final class QueueAnalyzer implements AnalyzerInterface
{
    public function name(): string { return 'queues'; }      // used by --only / --exclude
    public function title(): string { return 'Queues'; }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $jobs = $context->files->findFiles('app/Jobs', ['php']);

        return new AnalyzerResult($this->name(), $this->title(),
            facts: [new Fact('Job classes', (string) count($jobs), Source::Detected, 'app/Jobs')],
            findings: [],   // new Finding($id, $title, Severity::Low, Confidence::Potential, $component, $why, $evidence, $fix)
        );
    }
}
```

Register it in `Services::analyzers()`. Reporters render facts, tables, findings and notes
generically, so no reporter changes are needed. An exception thrown by an analyzer is caught and
shown as a failed section; other analyzers still run.

Useful context helpers: `$context->composer()` (composer.json/lock), `$context->config()` (static
config reader), `new SettingResolver($context)` (drivers with detected/inferred sources),
`$context->routes()`, `$context->sourceFiles([...])`, `$context->trackedFiles()`.

## A new security rule

Project-wide checks implement `Security\Rules\ProjectRuleInterface::check(InspectionContext): list<Finding>`.
Per-file checks implement `SourceRuleInterface` (`reset()`, `visit($file, $contents, $stmts)`,
`findings()`): the security analyzer reads and parses each source file once and offers it to every
source rule, so rules must not read files themselves. Add the rule to the default lists in
`SecurityAnalyzer::__construct()`.

If two analyzers can produce the same finding (same id, component and title), the inspector keeps
it only under the analyzer that ran first.

## A new AI provider

For an OpenAI-compatible API, extend `OpenAICompatibleProvider` and override only what differs:

```php
namespace LaraInspect\AI\Providers;

final class ExampleProvider extends OpenAICompatibleProvider
{
    protected function extraHeaders(): array { return ['X-Example-Client' => 'larainspect']; }
    protected function maxTokensField(): string { return 'max_tokens'; }
}
```

For another wire format, extend `AbstractHttpProvider` and implement `headers()`, `chat()` and
`listModels()`, using `$this->send()` for HTTP so retries, timeouts, typed errors, redaction and the
audit trail apply automatically. Then add an entry to `config/providers.php`:

```php
'example' => [
    'label' => 'Example AI',
    'class' => LaraInspect\AI\Providers\ExampleProvider::class,
    'env_prefix' => 'EXAMPLE',            // EXAMPLE_API_KEY, EXAMPLE_BASE_URL, EXAMPLE_MODEL
    'base_url' => 'https://api.example.test/v1',
    'default_model' => null,
],
```

Test it with `Symfony\Component\HttpClient\MockHttpClient`; see `tests/Unit/AI/ProvidersTest.php`,
whose shared retry, timeout, invalid-response and error tests can be pointed at a new provider by
adding it to the `providers()` data set.

## A new AI task

Add an `AITask` to `TaskCatalog::all()`: a key (for `--ai-task`), a title, an instruction, and the
analyzers whose results it needs (empty for all). Tasks share the system prompt and the context
builder, so they cannot be given source code or unredacted data.

## A new report format

Implement `ReporterInterface` (`format()`, `fileExtension()`, `render(InspectionReport): string`)
and add it to `ReporterRegistry::default()`. Escape everything that comes from the project or from
an AI model; see `HtmlReporter::e()` and `MarkdownReporter::inline()`.
