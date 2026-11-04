<?php
declare(strict_types=1);

namespace PopChain\Parser;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use PopChain\Model\ArgInfo;
use PopChain\Model\CallSite;
use PopChain\Model\SinkCatalog;
use PopChain\Model\StringUse;

/**
 * 从一个方法体里收集"可能被 POP 链利用"的调用点与字符串上下文。
 *
 * 只关心：函数调用、方法调用、属性可调用、静态调用、new、eval/include、
 * 属性读取（__get / __isset 触发点），以及方法内的局部变量污点传播。
 * 不进入嵌套的类/接口/枚举，避免把内层类的方法算到外层。
 */
final class CallCollector extends NodeVisitorAbstract
{
    /** @var list<CallSite> */
    public array $calls = [];

    /** @var list<StringUse> */
    public array $stringUses = [];

    /** @var array<string, ArgInfo> $var = $this->prop 之类的局部变量污点 */
    private array $varTaint = [];

    /** @var array<string, ArgInfo> $this->a = $this->b 之类的属性污点 */
    private array $propTaint = [];

    /** @var array<string, true> 被显式赋了常量的属性（之后不再可控） */
    private array $constProps = [];

    /** @return list<string> 本方法内被显式赋成字面量的属性名 */
    public function constantProperties(): array
    {
        return array_keys($this->constProps);
    }

    /** @var array<string, Node> 局部变量最近一次赋值的表达式，用于解析动态方法名 */
    private array $localExprs = [];

    /** @var array<string, true> 当前方法的参数名（小写） */
    private array $params = [];

    private Standard $printer;

    /** @param list<string> $paramNames */
    public function __construct(array $paramNames = [], private ?string $methodName = null)
    {
        $this->printer = new Standard();
        foreach ($paramNames as $name) {
            $this->params[strtolower($name)] = true;
        }
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Expr\Assign) {
            $this->collectAssign($node);

        } elseif ($node instanceof Expr\FuncCall) {
            $this->collectFuncCall($node);
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->collectMethodCall($node);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->collectStaticCall($node);
        } elseif ($node instanceof Expr\New_) {
            $this->collectNew($node);
        } elseif ($node instanceof Expr\Eval_) {
            $this->collectEval($node);
        } elseif ($node instanceof Expr\Include_) {
            $this->collectInclude($node);
        } elseif ($node instanceof Expr\Isset_ || $node instanceof Expr\Empty_) {
            foreach ($node instanceof Expr\Isset_ ? $node->vars : [$node->expr] as $expr) {
                $this->collectPropertyProbe($expr, CallSite::PROPERTY_ISSET);
            }
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        } elseif ($node instanceof Expr\PropertyFetch) {
            $this->collectPropertyProbe($node, CallSite::PROPERTY_GET);
        } elseif ($node instanceof Stmt\Echo_) {
            foreach ($node->exprs as $expr) {
                $this->stringOperand($expr, StringUse::ECHO);
            }
        } elseif ($node instanceof Expr\Print_) {
            $this->stringOperand($node->expr, StringUse::ECHO);
        } elseif ($node instanceof Expr\BinaryOp\Concat) {
            $this->stringOperand($node->left, StringUse::CONCAT);
            $this->stringOperand($node->right, StringUse::CONCAT);
        } elseif ($node instanceof Expr\Cast\String_) {
            $this->stringOperand($node->expr, StringUse::CAST);
        } elseif ($node instanceof Expr\Exit_) {
            // die()/exit() 的参数会按字符串输出，同样会触发 __toString
            if ($node->expr !== null) {
                $this->stringOperand($node->expr, StringUse::ECHO);
            }
        } elseif ($this->isInterpolatedString($node)) {
            // "value: {$this->obj}" 的插值部分也是字符串上下文
            foreach ($node->parts as $part) {
                if ($this->isLiteralStringPart($part)) {
                    continue;
                }
                if ($part instanceof Node) {
                    $this->stringOperand($part, StringUse::STRING_FUNC);
                }
            }
        }

        return null;
    }

    private function collectFuncCall(Expr\FuncCall $node): void
    {
        if ($this->isFirstClassCallable($node->args)) {
            return;
        }
        $name = $node->name;

        // $fn(...) / $fn = $this->cb 这类变量函数
        if ($name instanceof Expr\Variable && is_string($name->name)) {
            $this->collectVariableCall($node, $name->name);
            return;
        }

        if ($name instanceof Name) {
            $target = ltrim($name->toString(), '\\');
            $args = $this->args($node->args);
            $this->calls[] = new CallSite(
                kind: CallSite::FUNCTION_CALL,
                target: $target,
                args: $args,
                line: $node->getStartLine(),
                raw: $this->printer->prettyPrintExpr($node),
            );

            $this->collectStringArgs(strtolower($this->shortName($target)), $node->args);
            return;
        }

        // ($this->callback)(...) —— 括号包裹的动态调用
        if ($name instanceof Expr\PropertyFetch
            && $this->isThisProperty($name)) {
            $this->calls[] = new CallSite(
                kind: CallSite::CALL_PROPERTY,
                target: $this->propertyName($name),
                args: $this->args($node->args),
                line: $node->getStartLine(),
                receiverKind: ArgInfo::THIS_PROPERTY,
                receiverName: $this->propertyName($name),
                raw: $this->printer->prettyPrintExpr($node),
            );
        }
    }

    /**
     * 变量函数调用：$fn(...)，$fn 可能来自属性、局部变量或字符串常量。
     */
    private function collectVariableCall(Expr\FuncCall $node, string $variable): void
    {
        $info = $this->resolveVariable($variable, '');
        $args = $this->args($node->args);
        $line = $node->getStartLine();
        $raw = $this->printer->prettyPrintExpr($node);

        if ($info->kind === ArgInfo::THIS_PROPERTY && $info->name !== null) {
            $this->calls[] = new CallSite(
                kind: CallSite::CALL_PROPERTY,
                target: $info->name,
                args: $args,
                line: $line,
                receiverKind: ArgInfo::THIS_PROPERTY,
                receiverName: $info->name,
                raw: $raw,
            );
            return;
        }

        // $fn = [$this->obj, 'method']; $fn(...)
        if ($info->kind === ArgInfo::CALLABLE_ARRAY && $info->name !== null) {
            $this->calls[] = new CallSite(
                kind: CallSite::CALLABLE_ARRAY_INVOKE,
                target: is_string($info->literal) ? $info->literal : null,
                args: $args,
                line: $line,
                receiverKind: ArgInfo::THIS_PROPERTY,
                receiverName: $info->name,
                raw: $raw,
            );
            return;
        }

        // $fn = 'system'; $fn($x)
        if ($info->kind === ArgInfo::STRING && is_string($info->literal)) {
            $this->calls[] = new CallSite(
                kind: CallSite::FUNCTION_CALL,
                target: ltrim($info->literal, '\\'),
                args: $args,
                line: $line,
                raw: $raw,
            );
        }
    }

    private function collectMethodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node): void
    {
        // $obj->method(...) 是 first-class callable，只生成闭包、不会真的调用
        if ($this->isFirstClassCallable($node->args)) {
            return;
        }
        [$receiverKind, $receiverName] = $this->receiver($node->var);
        $target = $node->name instanceof Identifier ? $node->name->toString() : null;

        $this->calls[] = new CallSite(
            kind: CallSite::METHOD_CALL,
            target: $target,
            args: $this->args($node->args),
            line: $node->getStartLine(),
            receiverKind: $receiverKind,
            receiverName: $receiverName,
            raw: $this->printer->prettyPrintExpr($node),
        );
    }

    private function collectStaticCall(Expr\StaticCall $node): void
    {
        if ($this->isFirstClassCallable($node->args)) {
            return;
        }
        $this->calls[] = new CallSite(
            kind: CallSite::STATIC_CALL,
            target: $node->name instanceof Identifier ? $node->name->toString() : null,
            args: $this->args($node->args),
            line: $node->getStartLine(),
            receiverKind: $node->class instanceof Name ? 'static' : ArgInfo::EXPR,
            receiverName: $node->class instanceof Name ? ltrim($node->class->toString(), '\\') : null,
            raw: $this->printer->prettyPrintExpr($node),
        );
    }

    private function collectNew(Expr\New_ $node): void
    {
        $this->calls[] = new CallSite(
            kind: CallSite::NEW_OBJECT,
            target: $node->class instanceof Name ? ltrim($node->class->toString(), '\\') : null,
            args: $this->args($node->args),
            line: $node->getStartLine(),
            receiverKind: ArgInfo::EXPR,
            raw: $this->printer->prettyPrintExpr($node),
        );
    }

    private function collectEval(Expr\Eval_ $node): void
    {
        $this->calls[] = new CallSite(
            kind: CallSite::FUNCTION_CALL,
            target: 'eval',
            args: [$this->arg(new Node\Arg($node->expr))],
            line: $node->getStartLine(),
            raw: $this->printer->prettyPrintExpr($node),
        );
        $this->stringOperand($node->expr, StringUse::STRING_FUNC);
    }

    private function collectInclude(Expr\Include_ $node): void
    {
        $kind = match ($node->type) {
            Expr\Include_::TYPE_REQUIRE => 'require',
            Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            default => 'include',
        };
        $this->calls[] = new CallSite(
            kind: CallSite::FUNCTION_CALL,
            target: $kind,
            args: [$this->arg(new Node\Arg($node->expr))],
            line: $node->getStartLine(),
            raw: $this->printer->prettyPrintExpr($node),
        );
        $this->stringOperand($node->expr, StringUse::INCLUDE);
    }

    /**
     * $this->prop->name 这样的二级属性读取。
     * 目标对象来自可控属性时，读取未知属性会触发它的 __get / __isset。
     */
    private function collectPropertyProbe(Node $expr, string $kind): void
    {
        if (!$expr instanceof Expr\PropertyFetch) {
            return;
        }
        if (!$expr->name instanceof Identifier) {
            return;
        }
        if (!$this->isThisProperty($expr->var)) {
            return;
        }
        $this->calls[] = new CallSite(
            kind: $kind,
            target: $expr->name->toString(),
            args: [],
            line: $expr->getStartLine(),
            receiverKind: ArgInfo::THIS_PROPERTY,
            receiverName: $this->propertyName($expr->var),
            raw: $this->printer->prettyPrintExpr($expr),
        );
    }

    /** 方法内的赋值：$x = $this->prop / $this->a = $this->b / $x = $param。 */
    private function collectAssign(Expr\Assign $node): void
    {
        $info = $this->argValue($node->expr);

        if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $this->storeTaint($this->varTaint, $node->var->name, $info);
            $this->localExprs[$node->var->name] = $node->expr;
            return;
        }

        if ($this->isThisProperty($node->var)) {
            $name = $this->propertyName($node->var);

            // 被显式赋成字面量之后，这个属性确定不可控
            if ($info->isLiteral()) {
                unset($this->propTaint[$name]);
                $this->constProps[$name] = true;
                return;
            }

            $this->storeTaint($this->propTaint, $name, $info);
            if (isset($this->propTaint[$name])) {
                unset($this->constProps[$name]);
            } else {
                // 来源未知（如 $data['x']）时保持默认的"可控"
                unset($this->constProps[$name]);
            }
        }
    }

    /** @param array<string, ArgInfo> $map */
    private function storeTaint(array &$map, string $name, ArgInfo $info): void
    {
        $keep = $info->isControllable()
            || $info->kind === ArgInfo::VARIABLE
            || $info->kind === ArgInfo::CONCAT
            || $info->isLiteral();

        if ($keep && $name !== 'this') {
            $map[$name] = $info;
            return;
        }
        unset($map[$name]);
    }

    /** 记录 $this->prop 被放入字符串上下文的位置。 */
    private function stringOperand(Node $node, string $context): void
    {
        if ($node instanceof Expr\PropertyFetch && $this->isThisProperty($node)) {
            $this->stringUses[] = new StringUse(
                property: $this->propertyName($node),
                context: $context,
                line: $node->getStartLine(),
                raw: $this->printer->prettyPrintExpr($node),
            );
            return;
        }

        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $info = $this->resolveVariable($node->name, '');
            foreach ($this->controllableProperties($info) as $property) {
                $this->stringUses[] = new StringUse(
                    property: $property,
                    context: $context,
                    line: $node->getStartLine(),
                    raw: $this->printer->prettyPrintExpr($node),
                );
            }
            return;
        }
    }

    /** @return list<string> */
    private function controllableProperties(ArgInfo $info): array
    {
        if ($info->kind === ArgInfo::THIS_PROPERTY) {
            return $info->name === null ? [] : [$info->name];
        }
        if ($info->kind === ArgInfo::CONCAT) {
            return $info->propertyNames();
        }
        return [];
    }

    /** @return array{0:string,1:?string} */
    private function receiver(Node $node): array
    {
        if ($node instanceof Expr\Variable && $node->name === 'this') {
            return ['this', null];
        }
        if ($node instanceof Expr\PropertyFetch && $this->isThisProperty($node)) {
            return [ArgInfo::THIS_PROPERTY, $this->propertyName($node)];
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $info = $this->resolveVariable($node->name, '');
            if ($info->kind === ArgInfo::THIS_PROPERTY && $info->name !== null) {
                return [ArgInfo::THIS_PROPERTY, $info->name];
            }
            return [ArgInfo::VARIABLE, $node->name];
        }
        return [ArgInfo::EXPR, null];
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args */
    private function args(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $info = $this->arg($arg);
            if ($arg->name instanceof Identifier) {
                $info->name = $arg->name->toString();
            }
            $out[] = $info;
        }
        return $out;
    }

    private function arg(Node\Arg $arg): ArgInfo
    {
        return $this->argValue($arg->value);
    }

    /** 把一个表达式解析成污点描述。 */
    private function argValue(Node\Expr $value): ArgInfo
    {
        $raw = $this->printer->prettyPrintExpr($value);

        if ($value instanceof Expr\PropertyFetch && $this->isThisProperty($value)) {
            return $this->propertyArg($this->propertyName($value), $raw);
        }

        if ($value instanceof Expr\Variable && is_string($value->name)) {
            return $this->resolveVariable($value->name, $raw);
        }

        // 入口魔术方法的参数数组：$data['cmd'] / $data['a']['b'] 也来自反序列化数据
        $parameterElement = $this->parameterElement($value);
        if ($parameterElement !== null) {
            return $parameterElement;
        }

        if ($value instanceof Expr\BinaryOp\Concat) {
            $parts = [];
            if ($this->flattenConcat($value, $parts)) {
                return new ArgInfo(ArgInfo::CONCAT, null, $raw, null, $parts);
            }
            return new ArgInfo(ArgInfo::EXPR, null, $raw);
        }

        if ($value instanceof Scalar\String_) {
            return new ArgInfo(ArgInfo::STRING, null, $raw, $value->value);
        }
        if ($value instanceof Scalar\Int_) {
            return new ArgInfo(ArgInfo::INT, null, $raw, $value->value);
        }
        if ($value instanceof Scalar\Float_) {
            return new ArgInfo(ArgInfo::FLOAT, null, $raw, $value->value);
        }
        if ($value instanceof Expr\ConstFetch) {
            $name = strtolower((string) $value->name->toString());
            if ($name === 'true' || $name === 'false') {
                return new ArgInfo(ArgInfo::BOOL, null, $raw, $name === 'true');
            }
            if ($name === 'null') {
                return new ArgInfo(ArgInfo::NULL, null, $raw, null);
            }
        }

        if ($value instanceof Expr\Array_) {
            $callable = $this->callableArray($value, $raw);
            if ($callable !== null) {
                return $callable;
            }

            $items = [];
            $simple = true;
            foreach ($value->items as $item) {
                if ($item === null || $item->key !== null) {
                    $simple = false;
                    break;
                }
                $info = $this->argValue($item->value);
                if (!$info->isLiteral()) {
                    $simple = false;
                    break;
                }
                $items[] = $info;
            }
            if ($simple) {
                return new ArgInfo(ArgInfo::ARRAY_LITERAL, null, $raw, $items);
            }
        }

        return new ArgInfo(ArgInfo::EXPR, null, $raw);
    }

    /**
     * [$this->obj, 'method'] 形式的方法回调数组。
     */
    private function callableArray(Expr\Array_ $array, string $raw): ?ArgInfo
    {
        $items = $array->items;
        if (count($items) !== 2 || $items[0] === null || $items[1] === null) {
            return null;
        }
        if ($items[0]->key !== null || $items[1]->key !== null) {
            return null;
        }
        $object = $items[0]->value;
        $property = null;
        if ($object instanceof Expr\PropertyFetch && $this->isThisProperty($object)) {
            $property = $this->propertyName($object);
        } elseif ($object instanceof Expr\Variable && is_string($object->name)) {
            $info = $this->resolveVariable($object->name, '');
            if ($info->kind === ArgInfo::THIS_PROPERTY) {
                $property = $info->name;
            }
        }
        if ($property === null) {
            return null;
        }

        $method = $items[1]->value;
        if ($method instanceof Expr\Variable && is_string($method->name) && isset($this->localExprs[$method->name])) {
            $method = $this->localExprs[$method->name];
        }
        if ($method instanceof Scalar\String_) {
            return new ArgInfo(ArgInfo::CALLABLE_ARRAY, $property, $raw, $method->value);
        }

        $dynamic = $this->dynamicCallableMethod($method);
        if ($dynamic !== null) {
            [$prefix, $methodProperty, $transform] = $dynamic;
            return new ArgInfo(
                ArgInfo::CALLABLE_ARRAY_DYNAMIC,
                $property,
                $raw,
                null,
                [
                    ['lit', $prefix],
                    ['prop', $methodProperty],
                    ['transform', $transform],
                ],
            );
        }

        return null;
    }

    /**
     * 解析动态方法名表达式，返回 [前缀, 方法名属性, 大小写变换]。
     *
     * 覆盖 "do" . ucfirst($this->mode) / "do" . $this->mode 这类写法。
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private function dynamicCallableMethod(Expr $expr): ?array
    {
        if (!$expr instanceof Expr\BinaryOp\Concat || !$expr->left instanceof Scalar\String_) {
            return null;
        }

        $prefix = $expr->left->value;
        $right = $expr->right;

        if ($this->isThisProperty($right)) {
            return [$prefix, $this->propertyName($right), 'identity'];
        }

        if ($right instanceof Expr\FuncCall && $right->name instanceof Name && count($right->args) >= 1) {
            $transform = strtolower($right->name->toString());
            if (!in_array($transform, ['ucfirst', 'lcfirst', 'strtolower', 'ucwords'], true)) {
                return null;
            }
            $arg = $right->args[0];
            if (!$arg instanceof Node\Arg || !$this->isThisProperty($arg->value)) {
                return null;
            }
            return [$prefix, $this->propertyName($arg->value), $transform];
        }

        return null;
    }

    /** 属性参数：跟随 $this->a = $this->b 这类赋值。 */
    private function propertyArg(string $name, string $raw, int $depth = 0): ArgInfo
    {
        if ($depth > 8 || !isset($this->propTaint[$name])) {
            if (isset($this->constProps[$name])) {
                return new ArgInfo(ArgInfo::EXPR, null, '$this->' . $name);
            }
            return new ArgInfo(ArgInfo::THIS_PROPERTY, $name, $raw);
        }

        $source = $this->propTaint[$name];
        if ($source->isLiteral()) {
            return new ArgInfo(ArgInfo::EXPR, null, '$this->' . $name);
        }
        if ($source->kind === ArgInfo::THIS_PROPERTY) {
            return $this->propertyArg((string) $source->name, $raw, $depth + 1);
        }
        if ($source->kind === ArgInfo::CONCAT) {
            return new ArgInfo(ArgInfo::CONCAT, null, $raw, null, $source->parts);
        }
        return new ArgInfo(ArgInfo::THIS_PROPERTY, $name, $raw);
    }

    /** 局部变量参数：跟随 $x = $this->prop / $x = $param。 */
    private function resolveVariable(string $name, string $raw): ArgInfo
    {
        $seen = [];
        $current = $name;
        while (isset($this->varTaint[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $taint = $this->varTaint[$current];

            if ($taint->kind === ArgInfo::THIS_PROPERTY) {
                return $this->propertyArg((string) $taint->name, $raw);
            }
            if ($taint->kind === ArgInfo::CONCAT) {
                return new ArgInfo(ArgInfo::CONCAT, null, $raw, null, $taint->parts);
            }
            if ($taint->kind === ArgInfo::VARIABLE && $taint->name !== null) {
                $current = $taint->name;
                continue;
            }
            if ($taint->isLiteral()) {
                return new ArgInfo($taint->kind, null, $raw, $taint->literal);
            }
            if ($taint->kind === ArgInfo::CALLABLE_ARRAY) {
                return new ArgInfo(ArgInfo::CALLABLE_ARRAY, $taint->name, $raw, $taint->literal);
            }
            break;
        }

        return new ArgInfo(ArgInfo::VARIABLE, $current, $raw);
    }

    /**
     * 把字符串拼接表达式拆成字面量与属性占位。
     *
     * @param list<array{0:string,1:string}> $parts
     */
    private function flattenConcat(Node $node, array &$parts): bool
    {
        if ($node instanceof Expr\BinaryOp\Concat) {
            return $this->flattenConcat($node->left, $parts)
                && $this->flattenConcat($node->right, $parts);
        }
        if ($node instanceof Scalar\String_) {
            $parts[] = ['lit', $node->value];
            return true;
        }
        if ($node instanceof Scalar\Int_ || $node instanceof Scalar\Float_) {
            $parts[] = ['lit', (string) $node->value];
            return true;
        }
        if ($node instanceof Expr\PropertyFetch && $this->isThisProperty($node)) {
            $name = $this->propertyName($node);
            $info = $this->propertyArg($name, '');
            if ($info->kind === ArgInfo::CONCAT) {
                foreach ($info->parts as $part) {
                    $parts[] = $part;
                }
                return true;
            }
            $parts[] = ['prop', $name];
            return true;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $info = $this->resolveVariable($node->name, '');
            if ($info->kind === ArgInfo::THIS_PROPERTY && $info->name !== null) {
                $parts[] = ['prop', $info->name];
                return true;
            }
            if ($info->kind === ArgInfo::CONCAT) {
                foreach ($info->parts as $part) {
                    $parts[] = $part;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 把 $data['a']['b'] 解析成 PARAM_ELEMENT（路径 ['a', 'b']）。
     */
    private function parameterElement(Node $value): ?ArgInfo
    {
        $path = [];
        $current = $value;
        while ($current instanceof Expr\ArrayDimFetch) {
            $key = null;
            if ($current->dim instanceof Scalar\String_) {
                $key = $current->dim->value;
            } elseif ($current->dim instanceof Scalar\Int_) {
                $key = $current->dim->value;
            }
            if ($key === null) {
                return null;
            }
            $path[] = $key;
            $current = $current->var;
        }

        if ($path === [] || !$current instanceof Expr\Variable || !is_string($current->name)) {
            return null;
        }
        if (!isset($this->params[strtolower($current->name)])) {
            return null;
        }

        $path = array_reverse($path);
        $literal = count($path) === 1 ? $path[0] : $path;
        $raw = $this->printer->prettyPrintExpr($value);
        $method = strtolower((string) $this->methodName);

        if ($method === '__unserialize') {
            return new ArgInfo(ArgInfo::PARAM_ELEMENT, $current->name, $raw, $literal);
        }
        if ($method === '__call' || $method === '__callstatic') {
            return new ArgInfo(ArgInfo::ARG_ELEMENT, $current->name, $raw, $literal);
        }
        return null;
    }

    private function isThisProperty(Node $node): bool
    {
        return $node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier;
    }

    private function propertyName(Node $node): string
    {
        return $node instanceof Expr\PropertyFetch && $node->name instanceof Identifier
            ? $node->name->toString()
            : '?';
    }

    /**
     * 把函数实参按字符串上下文登记（位置表见 Model\SinkCatalog）。
     *
     * @param list<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function collectStringArgs(string $short, array $args): void
    {
        if ($short === 'sprintf' || $short === 'printf') {
            foreach ($this->sprintfStringPositions($args) as $position) {
                $arg = $args[$position] ?? null;
                if ($arg instanceof Node\Arg) {
                    $this->stringOperand($arg->value, StringUse::STRING_FUNC);
                }
            }
            return;
        }

        $positions = SinkCatalog::stringPositions($short);

        if ($positions === null) {
            foreach ($args as $arg) {
                if ($arg instanceof Node\Arg) {
                    $this->stringOperand($arg->value, StringUse::STRING_FUNC);
                }
            }
            return;
        }

        foreach ($positions as $position) {
            $arg = $args[$position] ?? null;
            if ($arg instanceof Node\Arg) {
                $this->stringOperand($arg->value, StringUse::STRING_FUNC);
            }
        }
    }

    /**
     * 从 sprintf/printf 的格式串里找出哪些实参是 %s（其它转换符不会触发 __toString）。
     *
     * @param list<Node\Arg|Node\VariadicPlaceholder> $args
     * @return list<int>
     */
    private function sprintfStringPositions(array $args): array
    {
        $formatArg = $args[0] ?? null;
        if (!$formatArg instanceof Node\Arg || !$formatArg->value instanceof Scalar\String_) {
            // 格式串本身不可确定时，只把格式串当字符串上下文
            return [0];
        }

        $positions = [0];
        preg_match_all('/%(?:\d+\$)?[-+ 0]*(?:\d+)?(?:\.\d+)?([bcdeEfFgGosuxX%])/', $formatArg->value->value, $matches);

        $argument = 1;
        foreach ($matches[1] as $conversion) {
            if ($conversion === '%') {
                continue;
            }
            if ($conversion === 's') {
                $positions[] = $argument;
            }
            $argument++;
        }

        return $positions;
    }

    /** $fn(...) / $obj->m(...) / Foo::m(...) 的 first-class callable 语法。 */
    private function isFirstClassCallable(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg instanceof Node\VariadicPlaceholder) {
                return true;
            }
        }
        return false;
    }

    /** 兼容 php-parser 5 的 InterpolatedString 与 4 的 Encapsed。 */
    private function isInterpolatedString(Node $node): bool
    {
        $class = get_class($node);
        return str_ends_with($class, 'InterpolatedString') || str_ends_with($class, 'Encapsed');
    }

    /** 插值字符串里的纯文本片段不是表达式，跳过。 */
    private function isLiteralStringPart(mixed $part): bool
    {
        if (!is_object($part)) {
            return true;
        }
        $class = get_class($part);
        return str_ends_with($class, 'InterpolatedStringPart') || str_ends_with($class, 'EncapsedStringPart');
    }

    private function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
