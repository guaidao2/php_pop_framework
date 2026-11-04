# Symbolic engine protocol

This document defines the JSON contract between `php_pop_framework` and an
external symbolic-execution / constraint-solving engine. The engine is optional:
when it is missing, slow, or returns invalid data, the CLI falls back to the
heuristic analysis and says so.

## Two integration modes

### A. In-process engine (primary path)

The engine code is merged into this repository, so no JSON is involved.
The default implementation is `PopChain\Symbolic\SymbolicEngine`: it translates the
guard expressions collected from a chain into SymPHP expressions and checks them
with the built-in solver. It is selected automatically when available; pass
`--engine=none` to turn it off.

Adding another in-process engine:

1. Put the code under `src/Symbolic/` with namespace `PopChain\Symbolic`
   (the autoloader maps `PopChain\` to `src/`, so no extra registration is needed).
2. Implement `PopChain\Engine\EngineInterface`.
3. Select it with `--engine=<name>`, or register any other name:

```php
\PopChain\Engine\EngineRegistry::register('my-engine', \PopChain\Symbolic\MyEngine::class);
```

```bash
./popchain find ./src --engine=builtin
./popchain find ./src --engine=my-engine
```

`analyze(array $request): ?array` receives the request array described below
directly (no serialization) and returns the same response array. Returning
`null`, `unknown`, or an unsat/sat result is handled exactly like the JSON mode.

### B. External process engine (optional)

When the engine lives outside PHP or in another repository, it can be attached as
a subprocess. The rest of this document describes the JSON contract used in that
mode.

## Invocation

```bash
./popchain find ./src \
  --type=deserialization \
  --engine-cmd='./bin/php-sym' \
  --engine-timeout=10
```

The tool starts the command, writes **one** JSON request to its stdin, closes
stdin, then reads **one** JSON response from stdout. stderr is captured and
ignored. A non-zero exit status, a timeout, or invalid JSON is treated as
"engine unavailable".

## Request

```json
{
  "protocol_version": "1.0",
  "kind": "solve_chain",
  "entry": { "class": "App", "method": "__destruct" },
  "nodes": ["App::__destruct", "View::__toString", "Cacher::__get"],
  "methods": [
    {
      "class": "App",
      "method": "__destruct",
      "cfg": {
        "class": "App",
        "method": "__destruct",
        "entry": 0,
        "exits": [3],
        "nodes": [
          {
            "id": 0,
            "kind": "entry",
            "line": 0,
            "code": "",
            "successors": [{ "to": 1, "label": null }],
            "meta": []
          },
          {
            "id": 1,
            "kind": "condition",
            "line": 10,
            "code": "$this->is_admin === true",
            "successors": [
              { "to": 2, "label": "true" },
              { "to": 3, "label": "false" }
            ],
            "meta": { "condition": "if" }
          }
        ]
      }
    }
  ],
  "guard_conditions": [
    { "class": "App", "method": "__destruct", "expr": "$this->is_admin === true", "expected": true }
  ],
  "constraints": [
    {
      "class": "App",
      "method": "__destruct",
      "property": "is_admin",
      "value": true,
      "line": 0
    }
  ],
  "sink": {
    "function": "system",
    "category": "rce",
    "position": 0,
    "argument": "$this->cmd"
  }
}
```

Field notes:

- `nodes` is the chain path as `Class::method` strings, in execution order.
- `methods[].cfg` is the statement-level control-flow graph of that method.
  `successors[].label` is `true`, `false`, `exception`, `case`, or `null`.
- `constraints` are the guard values collected by the heuristic pass. They are
  hints, not the whole path condition; the engine is expected to derive the
  real constraints from the CFG.
- `guard_conditions` is a list of `{class, method, expr, expected}` entries: the
  raw guard expressions with the truth value required to reach the chain. The
  built-in engine translates these expressions and checks whether their
  conjunction is satisfiable. An expression that appears with both
  `expected: true` and `expected: false` is treated as an alternative branch and
  ignored.
- `sink` describes what the chain is trying to reach.

## Response

```json
{
  "protocol_version": "1.0",
  "status": "sat",
  "assignments": [
    { "class": "App", "property": "is_admin", "value": true },
    { "class": "Settings", "property": "token", "value": "admin_token" }
  ],
  "notes": ["solved with Z3, 2 properties required"]
}
```

- `status` must be `sat`, `unsat`, or `unknown`.
  - `sat`: the engine found a model. `assignments` are written into the payload;
    properties already set by the heuristic are overridden.
  - `unsat`: the path is infeasible. The CLI drops the chain from the output and
    prints a note on stderr.
  - `unknown`: keep the heuristic result.
- `assignments[].class` is the class whose object should carry the property. A
  short class name is accepted if it is unambiguous.
- `assignments[].value` may be any JSON scalar, array, or object; arrays are
  serialized as PHP arrays, objects as nested payload nodes are **not** supported
  yet, keep values scalar/array.
- `notes` are printed as-is under the chain.

## Versioning and failures

- The current protocol version is `1.0`. Future incompatible changes bump it.
- Engines should answer within a few seconds; the CLI enforces `--engine-timeout`
  (default 10s) and kills the process on expiry.
- Any parse/validation failure is non-fatal: the chain is still reported with
  heuristic payload and a note saying the engine was unavailable.

## Minimal engine skeleton

```php
<?php
$request = json_decode(stream_get_contents(STDIN), true);

// ... solve $request['methods'][*]['cfg'] + $request['constraints'] ...

echo json_encode([
    'protocol_version' => '1.0',
    'status' => 'sat',
    'assignments' => [
        ['class' => 'App', 'property' => 'is_admin', 'value' => true],
    ],
    'notes' => ['solved'],
]);
```
