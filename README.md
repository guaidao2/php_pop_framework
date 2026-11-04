# php_pop_framework

中文文档: [README.zh-CN.md](README.zh-CN.md)

php_pop_framework is a PHP deserialization POP chain generator for CTF and authorized code auditing. The CLI entry point is `popchain`.

It also embeds **SymPHP**, a path-sensitive symbolic-execution / taint analysis engine, exposed as a second detection type for XSS, SQL injection, command injection, LFI, SSRF, XXE and more. Both detectors run by default (`--type=all`); use `--type=deserialization` or `--type=symbolic` to narrow. A heuristic `--type=dom-xss` detector scans JS/inline templates for dynamic `innerHTML` / `document.write` sinks (potential DOM/stored XSS) and is opt-in because it trades precision for coverage.

Give it a PHP source tree (a single file, a directory, stdin, or a URL) and the tool will:

1. Parse every class, property, method, and call site;
2. Start from magic method entry points and search along controllable properties;
3. Reach a dangerous sink (`system`, `eval`, `include`, `file_put_contents`, and the like);
4. Build a serialized payload you can submit directly, and print the full chain path.

Author: **guaidao2 & coolmoon** · License: [MIT](LICENSE)

## Requirements

- PHP >= 8.1 (the CLI is enough)
- [nikic/php-parser](https://github.com/nikic/PHP-Parser) 5.x

## Installing dependencies

Choose one:

```bash
# Composer, recommended: composer.json pins the parser and generates vendor/autoload.php
composer install

# Kali / Debian
sudo apt install php-parser

# Offline: download php-parser and unpack it into vendor/PhpParser/; bootstrap will pick it up automatically
```

`src/bootstrap.php` probes the dependency in this order:

1. `vendor/PhpParser/autoload.php` (a vendor directory inside the project, placed manually when offline)
2. `vendor/autoload.php` (Composer)
3. `/usr/share/php/PhpParser/autoload.php` (installed via apt)

If none is found, it prints installation instructions and exits.

## Unified entry point

`popchain` in the project root is the only entry point (the tool itself is php_pop_framework); `bin/popchain` is a compatibility alias, and the two behave identically:

```bash
./popchain version
./popchain help
# or
php popchain find ./src --pop-cmd='id'
```

CLI output is English by default; append `--lang=zh` (or `--lang zh`) to switch to Chinese.

## Quick start

### Local single file

After copying a challenge's source from the cloud to your machine, point the tool straight at the file:

```bash
./popchain scan ./chal.php
./popchain find ./chal.php --pop-cmd='id'
```

### Standard input (nothing written to disk)

```bash
cat chal.php | ./popchain find --stdin --pop-cmd='id'
# or
./popchain find --stdin --pop-cmd='id' < chal.php
```

### Fetching from a URL

```bash
./popchain find --url=https://example.com/chal.php --pop-cmd='id'
```

The URL may serve raw PHP source. Pages rendered by `highlight_file` / `highlight_string` (common in CTF challenges that show their own source) are recognised: the tool strips the HTML markup, unescapes it, and analyses the recovered source. Other rendered HTML — pages that already ran, login walls, API responses — cannot be converted and the tool reports that the URL is not raw source.

### Directory

```bash
./popchain find ./src --sink=system --pop-cmd='id'
./popchain find ./src --sink=file_get_contents --pop-read-file=/flag
./popchain find ./src --type=symbolic   # XSS / SQLi / RCE / LFI taint analysis
./popchain find ./src --entry=all --output=payloads.txt
```

Sample `find` output:

```
[1] [CRITICAL] Entry::__destruct -> Runner::__invoke -> Sink::__invoke  (deserialization/rce)
    examples/demo.php:3
    via call_property  $this->callback       Entry:8
    via callable       $this->fn             Runner:17
    sink: system() arg #0 <- $cmd
    engine: UNKNOWN (1 constraint, 0 skipped)
    payload: O:5:"Entry":1:{s:8:"callback";O:6:"Runner":2:{s:2:"fn";O:4:"Sink":0:{}s:3:"arg";s:2:"id";}}
```

Sample `find --type=symbolic` output (same format, different detector):

```
[1] [CRITICAL] OS Command Injection  (symbolic/RCE)
    examples/symbolic/vuln/rce.php:9
    taint: $_POST['cmd']
    path: (strlen($_POST['cmd']) > 0)
    sink: system()
    payload: id
    | system($cmd);
```

## Architecture

The project is organized as a framework rather than a single-purpose scanner:

- `src/Parser` turns PHP source into class/method metadata, guard conditions and a
  statement-level CFG (`src/Ir`);
- `src/Detector` holds pluggable detectors. Two are built in: `deserialization`
  (POP chains) and `symbolic` (path-sensitive taint analysis backed by the merged
  SymPHP engine). New vulnerability types implement `DetectorInterface` and
  register in `DetectorRegistry`, after which `--type` / `--list-types` pick them up;
- `src/Analysis` provides chain search, sink matching and guard collection shared
  by detectors;
- `src/Payload` turns a chain into a serialized payload;
- `src/Symbolic` is the **SymPHP** engine, merged natively (namespace
  `PopChain\Symbolic`, shared autoloader): parser, symbolic interpreter,
  constraint solver, taint tracking and reporters. It is selected with
  `--type=symbolic` and its budget options are exposed as `--sym-*`.
- The same solver is used by the deserialization detector as the default
  in-process engine (`PopChain\Symbolic\SymbolicEngine`): guard conditions collected from
  a chain are translated into symbolic expressions and checked for
  satisfiability; unsatisfiable chains are dropped, `unknown` results keep the
  heuristic output. Use `--engine=none` to disable it. `src/Engine` also supports
  external process engines.
  See [docs/symphp.md](docs/symphp.md) and
  [docs/symbolic-engine-protocol.md](docs/symbolic-engine-protocol.md).

```
CLI (--type)
  └─ Detector registry
       ├─ deserialization detector
       │    ├─ ChainFinder + guard constraints
       │    ├─ engine adapter (built-in solver by default)
       │    └─ PayloadBuilder
       └─ symbolic detector
            └─ SymPHP engine (symbolic execution + taint analysis)
```

## Commands and options

```
popchain scan <path|file> [options]
popchain find <path|file> [options]
popchain find --stdin [options]
popchain find --url=<address> [options]
popchain version
```

| Option | Description |
|---|---|
| `--lang=en\|zh` | Output language, `en` by default; `--lang=zh` switches to Chinese |
| `--entry=a,b` | Entry magic methods, `__destruct,__wakeup,__unserialize` by default; `--entry=all` enables every magic method |
| `--class=Entry` | Keep only chains that enter through the given class (short name or fully qualified namespace) |
| `--sink=system` | Keep only results that hit the given sink function (applies to both detectors) |
| `--pop-cmd=value` | Value for command-execution sinks (default `id`) |
| `--pop-read-file=path` | Value for file-read and `include` sinks (default `/etc/passwd`) |
| `--pop-write-file=path` | Value for file-write sinks (default `/tmp/popchain.txt`) |
| `--pop-delete-file=path` | Value for file-delete sinks (default `/tmp/popchain.txt`) |
| `--pop-move-file=path` | Value for file move / copy sinks (default `/tmp/popchain.txt`) |
| `--pop-chmod-file=path` | Value for `chmod` sinks (default `/tmp/popchain.txt`) |
| `--pop-ssrf-url=url` | Value for SSRF sinks such as `simplexml_load_file` / `get_headers` (default `http://127.0.0.1/`) |
| `--pop-env-value=text` | Value for `putenv` sinks (default `POPCHAIN=1`) |
| `--pop-var-value=text` | Value for `parse_str` sinks (default `popchain=1`) |
| `--pop-mail-to=address` | Value for `mail` sinks (default `a@b.test`) |
| `--pop-serialized=data` | Value for `unserialize` sinks (default `a:0:{}`) |
| `--output=file` | Write payloads to a file; in JSON mode, writes JSON |
| `--max-depth=8` | Maximum chain depth |
| `--max-chains=50` | Maximum number of chains to output |
| `--include-vendor` | Also scan the `vendor/` directory |
| `--json` | Emit JSON |
| `--color` | Force ANSI colors even when stdout is not a TTY |
| `--no-color` | Disable colors; the `NO_COLOR` environment variable also works |
| `--obfuscate` | Write strings as `S:` hex escapes so keyword filters do not see them |
| `--encode=url\|base64` | Encode the payload for direct copy/paste: `url` for a `?data=` query, `base64` for log-safe text; `--output` writes the encoded form too |
| `--type=all\|deserialization\|symbolic\|dom-xss` | Detection types; `all` (default) = deserialization + symbolic, `dom-xss` is opt-in (heuristic); see `--list-types` |
| `--no-gadget-gate` | Directory `all` scans suppress POP chains when no tainted `unserialize()` input is found; this flag keeps them |
| `--list-types` | List available detection types and exit |
| `--engine=none\|external` | Engine override: the built-in in-process solver is used by default, `none` disables it, `external` uses `--engine-cmd` |
| `--engine-cmd=cmd` | External engine command: JSON request on stdin, JSON response on stdout |
| `--engine-timeout=sec` | Engine timeout in seconds (default 10) |
| `--sym-timeout=sec` | Symbolic engine time limit in seconds (default 30) |
| `--sym-max-paths=N` | Symbolic engine max explored paths (default 2000) |
| `--sym-max-states=N` | Max states per execution point (default 128) |
| `--sym-max-depth=N` | Max inlined call depth (default 8) |
| `--sym-max-loop=N` | Max loop unroll count (default 3) |
| `--sym-entry=file` | Only analyze this entry file with the symbolic engine |
| `--sym-no-includes` | Do not inline `include` / `require` in the symbolic engine |

## How it works

```
PHP source (file / stdin / URL)
  └─ ProjectParser (nikic/php-parser + NameResolver)
       └─ IR: ClassInfo / PropertyInfo / MethodInfo / CallSite / ArgInfo
            └─ ChainFinder (DFS)
                 ├─ ($this->p)(...)               → any class __invoke
                 ├─ call_user_func($this->p, ..)  → any class __invoke
                 ├─ call_user_func([$this->p, 'm']) → method m / __call
                 ├─ call_user_func([$this->p, "do".ucfirst($this->mode)], ..) → dynamic method resolution
                 ├─ $this->p->m(...)              → any class method m / __call
                 ├─ self::m(...) / Foo::m(...)    → static method
                 ├─ $this->p->name                → any class __get / __isset
                 └─ $this->p in string context    → any class __toString
                      └─ SinkScanner detects dangerous functions
                           └─ PayloadBuilder → hand-written serializer → payload
```

Before every hop the parser follows local taint, so arguments that take a detour still resolve back to a property:

```php
$x = $this->cmd;                 // local variable copy
$this->target = $this->source;   // property to property
system('ping -c1 ' . $x);        // string concatenation keeps the controllable part
```

The payload is assembled straight into PHP's serialization format by `src/Payload/Serializer.php`; it **never loads or executes the target classes**. Payloads with private/protected properties contain NUL bytes, which most shells and terminals drop: take them from `--output` or use `--encode=url` / `--encode=base64` instead of copying the printed line. Property keys follow PHP conventions:

- public: `name`
- protected: `\0*\0name`
- private: `\0DeclaringClass\0name`

## Supported capabilities

- Input: single file, directory, stdin, URL
- Class inheritance (including entry methods inherited from an abstract parent), interfaces, constructor property promotion
- Traits flattened into the using class, nested traits included; trait properties are attributed to the using class so private keys serialize correctly
- Magic method entry points: `__destruct`, `__wakeup`, `__unserialize`, plus the configurable `__toString` / `__invoke` / `__call` / `__get` / `__isset` / `__clone`
- Chain edges: callable properties, callable arrays (`[$this->obj, 'method']`), method calls (with `__call` as fallback), static calls (`self::` / `static::` / `parent::` / `Foo::`), property reads that trigger `__get` / `__isset`, and string contexts that trigger `__toString`
- String contexts include `echo` / `print`, concatenation, string casts, `die()` / `exit()`, interpolation (`"{$this->obj}"`), the arguments of string built-ins (`strlen`, `str_replace`, `sprintf`, `strval`, ...), and the string parameters of sink functions such as `file_get_contents`
- Local taint propagation: variable copies, local aliases used as method receivers, property-to-property assignment, and string concatenation, so sink arguments stay traceable
- Callback arguments passed through arrays (`call_user_func_array`, `array_map`, `array_filter`, `array_walk`, `array_reduce`) are mapped as array elements, so the payload writes an array value instead of a scalar
- `usort` / `uasort` / `uksort` comparators: the first two callee parameters receive the first two array elements, so element values stay controllable
- Variable functions: `$fn = $this->cb; $fn(...)`, `$fn = [$this->obj, 'method']; $fn(...)`, and `$fn = 'system'; $fn(...)` all resolve to real call sites
- `__call` / `__callStatic`: `$args[0]` is mapped back to the corresponding argument at the call site
- `__unserialize(array $data)`: nested reads such as `$data['a']['b']` are handled, not just one level deep
- Trait conflict adaptation: `insteadof` chooses the winning method, `as` adds aliases or changes visibility
- `__wakeup` literal resets are detected at every nesting depth. When a chain depends on a property that `__wakeup` overwrites, the generated payload deliberately declares more properties than the class actually has so `unserialize()` fails at that object: `__wakeup` is skipped while the already-built objects are still destructed (CVE-2016-7124-style bypass), and the CLI marks the chain with a note. Classes that define `__unserialize` are handled separately (PHP does not call `__wakeup` for them)
- Argument backtracking: when a sink argument comes from a method parameter, trace it back along the call chain to the property that ultimately has to be assigned; constant arguments at the call site are reported as not controllable instead of producing a chain
- Guard conditions: while walking a chain, the tool collects what the properties must satisfy for the guarded code to run and writes those values into the payload. Covered: equality / inequality and ordering comparisons with literals (`$this->is_admin === true`, `$this->token === 'admin_token'`, `$this->ttl <= 0`), `isset` / `empty`, type checks (`is_array`, `is_string`, `is_int`, `is_float`, `is_bool`, `is_null`, `is_numeric`, `is_iterable`), plain truthiness (`if ($this->flag)` / `if (!$this->flag)`), early-return guards (`if (...) { return; }`) and else branches
- `__unserialize(array $data)` entries: `$data['cmd']` reads are treated as attacker-controlled data, both when assigned to a property and when passed straight to a sink
- Dynamic dispatch: `call_user_func([$this->handler, "do" . ucfirst($this->mode)], $this->param)` and the same pattern through a local variable are resolved by matching public methods that start with the literal prefix; the property that builds the method name (`mode`) is filled automatically (`execute`). `call_user_func` argument forwarding is used to map the target method parameters back to properties
- Method parameter constraints: `is_array($args)`, `count($args) === 2`, `$args[0] == 'admin'`, `is_numeric($args[1])`, `intval($args[1]) > 1000` are turned into an array value for the property that is forwarded into that parameter (`param = ['admin', 1001]`)
- Fixed sink arguments: when the sink argument is a literal (for example `system("cat /flag")`), the chain is still reported as a reachability chain with a note that the argument itself is not attacker-controlled; the payload only needs to reach the sink
- Sink categories: RCE, file read/write/delete/move, file inclusion, deserialization, callable, environment, variable injection, and more
- Unified output: deserialization chains and symbolic findings share one format, both in the text report (`[N] [SEVERITY] title  (type/category)` followed by location, sink, taint/path, notes and payload) and in JSON (`findings` array with `type`, `severity`, `category`, `title`, `file`, `line`, `sink`, `taint`, `path`, `payload`, `notes` and type-specific fields such as `chain`/`edges`)
- Payload output: textual reports and JSON (with tool name and version); payloads can be written to disk, NUL bytes are escaped on screen while `--output` keeps the raw bytes
- Entry semantics: chains that start from `__invoke` / `__toString` / `__get` and other non-deserialization magic methods are marked as needing an external trigger, so a payload that only works when called is not mistaken for a standalone gadget
- CLI guards: path and numeric option validation, with distinct exit codes (0 = chains found, 1 = no chains, 2 = bad input)

## Known limitations

Static analysis cannot cover every dynamic feature PHP offers. Current limits and heuristics:

- `--type=dom-xss` is a heuristic: it flags dynamic `innerHTML` / `document.write` without full JS data-flow, so treat hits as leads rather than confirmed stored XSS
- Directory `all` scans suppress POP chains when no tainted `unserialize()` input is found; use `--type=deserialization` or `--no-gadget-gate` to list gadget-only chains

- Reflection, code generated after `eval`, and `include` paths resolved at runtime
- Exception paths and loop counts are not modelled
- `preg_replace_callback`: the callback receives a matches array rather than plain elements, so parameters are not mapped
- Sink arguments that combine several different properties: only the first controllable property is written into the payload
- Guard conditions computed from function return values, arithmetic, regex results or nested array access are not solved; the solver covers literal/type comparisons and reports `unknown` for the rest
- Dynamic method names are resolved by literal-prefix matching over public methods; collisions (many methods sharing the prefix) can add candidate chains that need manual confirmation, and names with no static prefix are not resolved
- Fixed literal sink arguments produce reachability chains only; whether the hardcoded operation is actually harmful depends on the target
- Method parameter constraints only cover `is_array` / `count` / element comparisons / `intval`-style comparisons
- Symbolic analysis is bounded by path/state budgets; when a large project hits the limit the CLI prints a truncation warning (raise `--sym-max-paths` / `--sym-max-states` to dig deeper)
- `realpath()` containment guards are recognised: `$real = realpath($path); $base = realpath('/fixed'); if ($real === false || $base === false || strpos($real . '/', $base . '/') !== 0) exit;` suppresses path-confined file-read findings; weak prefix checks (no separator on the base side, base `/`, variable base, reversed direction, non-terminating guard) still report. `$_SERVER['DOCUMENT_ROOT']` is accepted as a server-controlled base
- Upload analysis: `move_uploaded_file` whose destination name comes from `$_FILES` is reported as `Unrestricted File Upload` when the code has no extension check (literal list, `.php` suffix comparison or extension regex); an extension blacklist / whitelist or a fixed safe suffix (`.jpg`) suppresses it. Validating only the client-supplied MIME (`$_FILES[...]['type']`) is not enough. `pathinfo(..., PATHINFO_EXTENSION)` and whitelist `preg_replace('/[^...]/', '_', ...)` clear path-traversal taint
- A declared `Content-Type: application/json` response suppresses XSS findings for output wrapped in `json_encode()`
- `header()` / `setcookie()` reject CRLF values on modern PHP, so response-header injection is only reported for `mail()`; `realpath()` results are treated as local paths (not SSRF sources), and `str_replace` that removes both path separators clears path-traversal taint
- Gadgets whose magic methods depend on runtime-created state (open handles, live database connections, loaded extensions) cannot be reproduced by property injection alone
- Anonymous classes cannot be instantiated by `unserialize`, so they are intentionally skipped rather than modelled

Whether a chain actually works ultimately depends on the class definitions and PHP version in the target environment. Reproduce and verify it locally before use.

## Project structure

```
php_pop_framework/
├── popchain                  # unified entry point
├── bin/popchain              # compatibility entry point
├── src/
│   ├── bootstrap.php         # dependency probing
│   ├── Cli/Application.php   # CLI implementation
│   ├── Parser/               # AST → model + CFG + guard constraints
│   ├── Ir/                   # statement-level control-flow graph
│   ├── Model/                # classes/methods/properties/call sites/chains/edges
│   ├── Detector/             # detector plugins (deserialization, symbolic)
│   ├── Engine/               # engine adapter (built-in solver enabled by default)
│   ├── Analysis/             # sink detection + chain search
│   ├── Symbolic/             # merged SymPHP engine (PopChain\Symbolic namespace)
│   └── Payload/              # object graph → hand-written serialization
├── examples/demo.php         # sample vulnerable code
├── examples/symbolic/vuln/   # SymPHP sample vulnerable code
├── tests/run.php             # test suite
├── tests/symbolic/           # SymPHP engine tests (merged)
├── tests/fixtures/           # parser/chain fixtures
├── docs/symphp.md            # merged SymPHP engine documentation
├── docs/symbolic-engine-protocol.md  # protocol for pluggable engines
├── composer.json             # dependency and autoload definition
├── README.zh-CN.md           # Chinese documentation
├── LICENSE                   # MIT
└── README.md
```

## Tests

```bash
php tests/run.php           # php_pop_framework tests
php tests/symbolic/run.php  # merged SymPHP engine tests
```

The suite covers the parser layer, chain search, payload construction, and the CLI. Several cases build a payload, deserialize it for real, and confirm the sink fires, which is the only way to tell a usable chain from a chain that merely looks right on paper. It also checks traits, inheritance, local taint, `__get` / `__isset` edges, namespaces, private/protected serialization keys, and the CLI input paths.

## Security Notice

This tool is meant solely for **authorized CTF competitions, teaching, and auditing systems you own**. The generated payloads genuinely invoke the dangerous functions in the target code; do not use them against systems you are not authorized to test.

## License

[MIT](LICENSE) © 2026 guaidao2 & coolmoon
