<?php
declare(strict_types=1);

namespace PopChain\Engine;

use PopChain\Model\Chain;
use PopChain\Model\ClassInfo;
use PopChain\Model\MethodInfo;

/**
 * 把一条链翻译成引擎请求，再把引擎给出的属性赋值回填到链上。
 *
 * 请求内容包含：入口方法、链上的类/方法、各方法的 CFG、以及启发式已经
 * 收集到的前置条件；引擎可以只回答"能不能满足"，也可以给出具体赋值。
 */
final class ChainConstraintSolver
{
    public function __construct(private EngineInterface $engine)
    {
    }

    public function engine(): EngineInterface
    {
        return $this->engine;
    }

    /**
     * @param array<string, ClassInfo> $classes
     * @return array{status:string, assignments:list<array{class:string,property:string,value:mixed}>, notes:list<string>}
     */
    public function solve(Chain $chain, array $classes): array
    {
        $request = [
            'protocol_version' => '1.0',
            'kind' => 'solve_chain',
            'entry' => [
                'class' => $chain->entryClass->name,
                'method' => $chain->entryMethod->name,
            ],
            'nodes' => $chain->nodes(),
            'methods' => $this->methodPayloads($chain),
            'constraints' => $this->constraints($chain),
            'guard_conditions' => $this->guardConditions($chain),
            'sink' => $chain->sink === null ? null : [
                'function' => $chain->sink->function,
                'category' => $chain->sink->category,
                'position' => $chain->sink->position,
                'argument' => $chain->sink->arg->describe(),
            ],
        ];

        $response = $this->engine->analyze($request);
        if ($response === null) {
            return ['status' => 'unavailable', 'assignments' => [], 'notes' => ['engine unavailable or returned invalid JSON']];
        }

        return $this->parseResponse($response, $classes);
    }

    /** @return list<array{class:string,method:string,cfg:array<string,mixed>}> */
    private function methodPayloads(Chain $chain): array
    {
        $methods = [];
        $this->appendMethod($methods, $chain->entryClass, $chain->entryMethod);
        foreach ($chain->edges as $edge) {
            $this->appendMethod($methods, $edge->toClass, $edge->toMethod);
        }
        return $methods;
    }

    /** @param list<array{class:string,method:string,cfg:array<string,mixed>}> $methods */
    private function appendMethod(array &$methods, ClassInfo $class, MethodInfo $method): void
    {
        $methods[] = [
            'class' => $class->name,
            'method' => $method->name,
            'cfg' => $method->cfg?->toArray() ?? [
                'class' => $class->name,
                'method' => $method->name,
                'entry' => 0,
                'exits' => [],
                'nodes' => [],
            ],
        ];
    }

    /**
     * 链上各方法的守卫条件（表达式 + 期望真假），交给符号执行引擎判断可满足性。
     *
     * @return list<array{class:string,method:string,expr:string,expected:bool}>
     */
    private function guardConditions(Chain $chain): array
    {
        $conditions = [];
        $append = static function (array &$out, ClassInfo $class, MethodInfo $method): void {
            foreach ($method->guardConditions as $condition) {
                $out[] = [
                    'class' => $class->name,
                    'method' => $method->name,
                    'expr' => (string) ($condition['expr'] ?? ''),
                    'expected' => (bool) ($condition['expected'] ?? true),
                ];
            }
        };

        $append($conditions, $chain->entryClass, $chain->entryMethod);
        foreach ($chain->edges as $edge) {
            $append($conditions, $edge->toClass, $edge->toMethod);
        }
        return $conditions;
    }

    /** @return list<array{class:string,method:string,property:string,value:mixed,line:int}> */
    private function constraints(Chain $chain): array
    {
        $constraints = [];
        $this->appendConstraints($constraints, $chain->entryClass, $chain->entryMethod);
        foreach ($chain->edges as $edge) {
            $this->appendConstraints($constraints, $edge->toClass, $edge->toMethod);
        }
        return $constraints;
    }

    /** @param list<array{class:string,method:string,property:string,value:mixed,line:int}> $constraints */
    private function appendConstraints(array &$constraints, ClassInfo $class, MethodInfo $method): void
    {
        foreach ($method->preconditions as $property => $value) {
            $constraints[] = [
                'class' => $class->name,
                'method' => $method->name,
                'property' => (string) $property,
                'value' => $value,
                'line' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, ClassInfo> $classes
     * @return array{status:string, assignments:list<array{class:string,property:string,value:mixed}>, notes:list<string>}
     */
    private function parseResponse(array $response, array $classes): array
    {
        $status = strtolower((string) ($response['status'] ?? 'unknown'));
        if (!in_array($status, ['sat', 'unsat', 'unknown'], true)) {
            $status = 'unknown';
        }

        $assignments = [];
        foreach ((array) ($response['assignments'] ?? []) as $assignment) {
            if (!is_array($assignment) || !isset($assignment['class'], $assignment['property'])) {
                continue;
            }
            $class = $this->resolveClass((string) $assignment['class'], $classes);
            if ($class === null) {
                continue;
            }
            $assignments[] = [
                'class' => $class->name,
                'property' => (string) $assignment['property'],
                'value' => $assignment['value'] ?? null,
            ];
        }

        $notes = [];
        foreach ((array) ($response['notes'] ?? []) as $note) {
            if (is_string($note)) {
                $notes[] = $note;
            }
        }

        return ['status' => $status, 'assignments' => $assignments, 'notes' => $notes];
    }

    /** @param array<string, ClassInfo> $classes */
    private function resolveClass(string $name, array $classes): ?ClassInfo
    {
        if (isset($classes[$name])) {
            return $classes[$name];
        }
        foreach ($classes as $class) {
            if (strcasecmp($class->shortName(), $name) === 0) {
                return $class;
            }
        }
        return null;
    }
}
