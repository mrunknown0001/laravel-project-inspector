<?php

declare(strict_types=1);

namespace LaraInspect\Security;

use LaraInspect\Parsing\PhpSource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

/**
 * Very local "does this expression contain request input?" checks.
 *
 * Only direct use within the same expression is detected ($request->input(),
 * request('x'), $_GET[...]). Data flowing through variables is not tracked,
 * so findings based on this are always reported as potential.
 */
final class Taint
{
    private const SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES'];

    private const REQUEST_VARIABLES = ['request', 'req'];

    public static function hasUserInput(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }

        return (new NodeFinder())->findFirst([$node], static function (Node $n): bool {
            if ($n instanceof Expr\Variable && is_string($n->name)) {
                return in_array($n->name, self::SUPERGLOBALS, true) || in_array($n->name, self::REQUEST_VARIABLES, true);
            }
            if ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name) {
                return in_array(strtolower($n->name->toString()), ['request', 'input', 'old'], true);
            }
            if ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name) {
                return in_array(PhpSource::shortName($n->class), ['Request', 'Input'], true);
            }

            return false;
        }) !== null;
    }

    /**
     * Whether a string is assembled at runtime by concatenation or interpolation.
     *
     * A bare variable is not counted: it is usually a prepared query passed
     * together with bindings, and flagging it would bury real issues in noise.
     */
    public static function isDynamicString(?Node $node): bool
    {
        if ($node instanceof Scalar\InterpolatedString) {
            foreach ($node->parts as $part) {
                if (!$part instanceof Node\InterpolatedStringPart) {
                    return true;
                }
            }

            return false;
        }

        return $node instanceof Expr\BinaryOp\Concat && PhpSource::string($node) === null;
    }
}
