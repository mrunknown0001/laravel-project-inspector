<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\PhpSource;
use LaraInspect\Security\Taint;
use LaraInspect\Support\Evidence;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Dangerous functions and risky patterns in application PHP code.
 *
 * Every finding is "potential": the analysis is local to one expression and
 * cannot prove that input actually reaches the sink. Calls that directly
 * contain request input are reported at a higher severity than the rest.
 */
final class DangerousCodeRule implements SourceRuleInterface
{
    private const COMMAND_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];

    private const FILE_FUNCTIONS = ['file_get_contents', 'fopen', 'readfile', 'file', 'unlink', 'file_put_contents', 'copy', 'rename', 'curl_init', 'simplexml_load_file'];

    private const STORAGE_METHODS = ['get', 'download', 'delete', 'put', 'readStream', 'response', 'path', 'move', 'copy'];

    private const RAW_SQL_STATIC = ['DB::raw', 'DB::select', 'DB::selectOne', 'DB::statement', 'DB::unprepared', 'DB::insert', 'DB::update', 'DB::delete', 'DB::affectingStatement', 'DB::scalar'];

    private const RAW_SQL_METHODS = ['whereRaw', 'orWhereRaw', 'havingRaw', 'orHavingRaw', 'orderByRaw', 'selectRaw', 'groupByRaw', 'fromRaw'];

    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'send'];

    private const MASS_ASSIGNMENT_METHODS = ['create', 'update', 'fill', 'forceFill', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'insert'];

    private const DEBUG_FUNCTIONS = ['dd', 'dump', 'var_dump', 'ray', 'ddd'];

    /** category => [id, title, severity, explanation, remediation] */
    private const CATEGORIES = [
        'command-input' => ['sec.command-injection', 'Shell command built with request input', Severity::High,
            'A shell command contains request input. If the input is not strictly validated, attackers can run arbitrary commands on the server.',
            'Avoid shell commands for user-facing features. Otherwise pass arguments with Symfony Process arrays or escapeshellarg(), and validate input against an allow-list.'],
        'command' => ['sec.command-execution', 'Shell command execution', Severity::Medium,
            'The application runs shell commands. That is risky whenever any part of the command can be influenced from outside, and it complicates hosting.',
            'Prefer PHP APIs or Symfony Process with argument arrays; never build command strings from input.'],
        'eval' => ['sec.eval', 'Use of eval() or create_function()', Severity::High,
            'Evaluated code runs with the application\'s full privileges; any input reaching it means remote code execution.',
            'Remove eval()/create_function() and implement the logic in regular code.'],
        'unserialize' => ['sec.unsafe-unserialize', 'Unsafe unserialize() of non-literal data', Severity::Medium,
            'unserialize() on attacker-controlled data can instantiate arbitrary classes and trigger gadget chains, leading to remote code execution.',
            "Use json_decode() for data from outside, or pass ['allowed_classes' => false] (or a list of safe classes)."],
        'sql-input' => ['sec.sql-injection', 'Raw SQL built with request input', Severity::High,
            'Request input is concatenated or passed directly into a raw SQL expression, which allows SQL injection.',
            'Use query builder methods or bindings: whereRaw("email = ?", [$email]); never concatenate input into SQL.'],
        'sql-dynamic' => ['sec.raw-sql-concatenation', 'Raw SQL assembled by concatenation', Severity::Medium,
            'Raw SQL strings are built by concatenating or interpolating variables. If any of them can come from a user, this is SQL injection.',
            'Use ? placeholders with bindings, or query builder methods, instead of string building.'],
        'include-input' => ['sec.file-inclusion', 'File inclusion with request input', Severity::High,
            'include/require with a path from the request allows local (or remote) file inclusion, which can lead to code execution.',
            'Never include files based on input; map allowed values to fixed paths instead.'],
        'file-input' => ['sec.path-or-url-from-input', 'File or URL access using request input', Severity::Medium,
            'A file path or URL comes directly from the request. This can allow path traversal (reading or deleting arbitrary files) or server-side request forgery.',
            'Validate input against an allow-list, use basename() / Storage paths you construct yourself, and restrict outbound hosts.'],
        'redirect-input' => ['sec.open-redirect', 'Redirect to a URL from request input', Severity::Low,
            'Redirecting to a user-supplied URL lets attackers craft links on your domain that forward victims to phishing sites.',
            'Redirect only to relative paths or an allow-list of hosts (e.g. url()->previous() or named routes).'],
        'mass-assignment-force' => ['sec.force-fill-request', 'forceFill()/forceCreate() with all request input', Severity::Medium,
            'forceFill/forceCreate bypass $fillable/$guarded, so passing all request input lets users set any column (is_admin, role, user_id).',
            'Pass only validated fields: $model->forceFill($request->validated()) or explicit $request->only([...]).'],
        'mass-assignment' => ['sec.mass-assignment-request-all', 'Model writes with all request input', Severity::Low,
            'Passing $request->all() to create/update/fill relies entirely on the model\'s $fillable/$guarded configuration to block privileged columns.',
            'Use $request->validated() from a Form Request, or $request->only([...]) with the expected fields.'],
        'weak-hash' => ['sec.weak-password-hash', 'Fast hash (md5/sha1) applied to a password', Severity::Medium,
            'MD5 and SHA-1 are fast hashes and are unsuitable for passwords; hashes can be cracked quickly after a breach.',
            'Use Hash::make() / bcrypt() / Argon2id via Laravel\'s Hash facade, and rehash on login.'],
        'debug' => ['sec.debug-statement', 'Debug statement left in application code', Severity::Info,
            'dd(), dump() and var_dump() print internal data to the response and halt or disturb normal output when reached in production.',
            'Remove debugging calls before deploying; use logging for diagnostics.'],
        'superglobal' => ['sec.superglobal-access', 'Direct superglobal access', Severity::Info,
            'Reading $_GET/$_POST/$_COOKIE directly bypasses Laravel\'s request handling (trusted proxies, input normalisation, testing helpers).',
            'Use the Request object ($request->input(), $request->cookie()).'],
    ];

    /** @var array<string, list<string>> */
    private array $hits = [];

    public function reset(): void
    {
        $this->hits = [];
    }

    public function visit(string $file, string $contents, ?array $stmts): void
    {
        $path = SourceFile::path($file);
        if ($stmts === null || !str_ends_with($path, '.php')) {
            return;
        }
        $lines = preg_split('/\R/', $contents) ?: [];
        $isAppCode = str_starts_with($path, 'app/') || str_starts_with($path, 'routes/') || str_starts_with($path, 'src/');

        $nodes = (new NodeFinder())->find($stmts, static fn (Node $n): bool => $n instanceof Expr\CallLike
            || $n instanceof Expr\Eval_ || $n instanceof Expr\ShellExec || $n instanceof Expr\Include_
            || ($n instanceof Expr\Variable && in_array($n->name, ['_GET', '_POST', '_REQUEST', '_COOKIE'], true)));

        foreach ($nodes as $node) {
            $category = $this->classify($node, $isAppCode);
            if ($category !== null) {
                $line = $node->getStartLine();
                $this->hits[$category][] = Evidence::location($file, $line, $lines[$line - 1] ?? '');
            }
        }
    }

    public function findings(InspectionContext $context): array
    {
        $findings = [];
        foreach (self::CATEGORIES as $category => [$id, $title, $severity, $explanation, $remediation]) {
            $hits = array_values(array_unique($this->hits[$category] ?? []));
            if ($hits === []) {
                continue;
            }
            $findings[] = new Finding(
                $id,
                sprintf('%s (%s)', $title, Evidence::plural(count($hits), 'occurrence')),
                $severity,
                Confidence::Potential,
                'source code',
                $explanation,
                Evidence::list($hits),
                $remediation,
            );
        }

        return $findings;
    }

    private function classify(Node $node, bool $isAppCode): ?string
    {
        if ($node instanceof Expr\Eval_) {
            return 'eval';
        }
        if ($node instanceof Expr\ShellExec) {
            return Taint::hasUserInput($node) ? 'command-input' : 'command';
        }
        if ($node instanceof Expr\Include_) {
            return !$node->expr instanceof Node\Scalar && Taint::hasUserInput($node->expr) ? 'include-input' : null;
        }
        if ($node instanceof Expr\Variable) {
            return $isAppCode ? 'superglobal' : null;
        }
        if (!$node instanceof Expr\CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        $first = PhpSource::arg($node, 0);
        $tainted = Taint::hasUserInput($first);

        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = strtolower($node->name->toString());

            return match (true) {
                in_array($name, self::COMMAND_FUNCTIONS, true) => Taint::hasUserInput($node) ? 'command-input' : 'command',
                $name === 'create_function' => 'eval',
                $name === 'unserialize' => $this->unsafeUnserialize($node) ? 'unserialize' : null,
                $name === 'extract' && $tainted => 'mass-assignment',
                in_array($name, self::FILE_FUNCTIONS, true) && $tainted => 'file-input',
                $name === 'redirect' && $tainted => 'redirect-input',
                in_array($name, ['md5', 'sha1'], true) && $this->mentionsPassword($first) => 'weak-hash',
                in_array($name, self::DEBUG_FUNCTIONS, true) && $isAppCode => 'debug',
                default => null,
            };
        }

        if ($node instanceof Expr\StaticCall) {
            $name = PhpSource::callName($node);
            if ($name === null) {
                return null;
            }
            [$class, $method] = explode('::', $name, 2);

            return match (true) {
                in_array($name, self::RAW_SQL_STATIC, true) => $tainted ? 'sql-input' : (Taint::isDynamicString($first) ? 'sql-dynamic' : null),
                $class === 'Storage' && in_array($method, self::STORAGE_METHODS, true) && $tainted => 'file-input',
                $class === 'Http' && in_array(strtolower($method), self::HTTP_METHODS, true) && $tainted => 'file-input',
                $class === 'Redirect' && in_array($method, ['to', 'away'], true) && $tainted => 'redirect-input',
                in_array($method, self::MASS_ASSIGNMENT_METHODS, true) && $this->passesAllInput($node) => in_array($method, ['forceFill', 'forceCreate'], true) ? 'mass-assignment-force' : 'mass-assignment',
                default => null,
            };
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $method = $node->name->toString();
            $chain = PhpSource::chain($node);
            $root = $chain['root'] ?? null;
            $rootClass = $root instanceof Node\Name ? PhpSource::shortName($root) : null;

            return match (true) {
                in_array($method, self::RAW_SQL_METHODS, true) => $tainted ? 'sql-input' : (Taint::isDynamicString($first) ? 'sql-dynamic' : null),
                $rootClass === 'Storage' && in_array($method, self::STORAGE_METHODS, true) && $tainted => 'file-input',
                $rootClass === 'Http' && in_array(strtolower($method), self::HTTP_METHODS, true) && $tainted => 'file-input',
                in_array($method, ['download', 'file'], true) && $this->isResponseHelper($node) && $tainted => 'file-input',
                in_array($method, ['to', 'away'], true) && $this->isRedirectHelper($node) && $tainted => 'redirect-input',
                in_array($method, self::MASS_ASSIGNMENT_METHODS, true) && $this->passesAllInput($node) => in_array($method, ['forceFill', 'forceCreate'], true) ? 'mass-assignment-force' : 'mass-assignment',
                default => null,
            };
        }

        return null;
    }

    private function unsafeUnserialize(Expr\FuncCall $call): bool
    {
        if (PhpSource::arg($call, 0) instanceof Node\Scalar) {
            return false;
        }
        $options = PhpSource::arg($call, 1, 'options');
        if ($options instanceof Expr\Array_) {
            foreach ($options->items as $item) {
                if (PhpSource::string($item->key) === 'allowed_classes') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * $request->all(), request()->all(), Request::all(), or $request->input() with no arguments.
     */
    private function passesAllInput(Expr\CallLike $call): bool
    {
        if ($call->isFirstClassCallable()) {
            return false;
        }
        foreach ($call->getArgs() as $arg) {
            $found = (new NodeFinder())->findFirst([$arg->value], static function (Node $n): bool {
                if ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier) {
                    $name = $n->name->toString();

                    return ($name === 'all' || ($name === 'input' && $n->getArgs() === [])) && Taint::hasUserInput($n->var);
                }

                return $n instanceof Expr\StaticCall && $n->class instanceof Node\Name && PhpSource::shortName($n->class) === 'Request'
                    && $n->name instanceof Node\Identifier && $n->name->toString() === 'all';
            });
            if ($found !== null) {
                return true;
            }
        }

        return false;
    }

    private function mentionsPassword(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }

        return (new NodeFinder())->findFirst([$node], static fn (Node $n): bool => ($n instanceof Expr\Variable && is_string($n->name) && stripos($n->name, 'pass') !== false)
            || (($n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch) && $n->name instanceof Node\Identifier && stripos($n->name->toString(), 'pass') !== false)
            || ($n instanceof Node\Scalar\String_ && stripos($n->value, 'password') !== false)) !== null;
    }

    private function isResponseHelper(Expr\MethodCall $call): bool
    {
        return $call->var instanceof Expr\FuncCall && $call->var->name instanceof Node\Name && $call->var->name->toString() === 'response';
    }

    private function isRedirectHelper(Expr\MethodCall $call): bool
    {
        return $call->var instanceof Expr\FuncCall && $call->var->name instanceof Node\Name && $call->var->name->toString() === 'redirect';
    }
}
