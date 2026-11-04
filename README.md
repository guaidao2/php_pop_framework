# php_pop_framework

中文文档: [README.zh-CN.md](README.zh-CN.md)

php_pop_framework is a PHP deserialization POP chain generator for CTF and authorized code auditing. The CLI entry point is `popchain`.

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
php popchain find ./src --cmd='id'
```

CLI output is English by default; append `--lang=zh` (or `--lang zh`) to switch to Chinese.

## Quick start

### Local single file

After copying a challenge's source from the cloud to your machine, point the tool straight at the file:

```bash
./popchain scan ./chal.php
./popchain find ./chal.php --cmd='id'
```

### Standard input (nothing written to disk)

```bash
cat chal.php | ./popchain find --stdin --cmd='id'
# or
./popchain find --stdin --cmd='id' < chal.php
```

### Fetching from a URL

```bash
./popchain find --url=https://example.com/chal.php --cmd='id'
```

Note: the URL must serve **raw PHP source**. If the page is rendered HTML, requires a login, or has already been executed by a PHP server, what you fetch is not source and the tool will find no classes.

### Directory

```bash
./popchain find ./src --sink=system --cmd='id'
./popchain find ./src --sink=file_get_contents --read-file=/flag
./popchain find ./src --entry=all --output=payloads.txt
```

Sample `find` output:

```
[1] Entry::__destruct → Runner::__invoke → Sink::__invoke
    via call_property  $this->callback       Entry:8
    via callable       $this->fn             Runner:17
    sink rce      system() arg #0 ← $cmd
    payload: O:5:"Entry":1:{s:8:"callback";O:6:"Runner":2:{...}}
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
| `--sink=system` | Keep only chains that reach the given sink function |
| `--cmd=value` | Value for command-execution sinks (default `id`) |
| `--read-file=path` | Value for file-read and `include` sinks (default `/etc/passwd`) |
| `--write-file=path` | Value for file-write sinks (default `/tmp/popchain.txt`) |
| `--delete-file=path` | Value for file-delete sinks (default `/tmp/popchain.txt`) |
| `--move-file=path` | Value for file move / copy / permission sinks (default `/tmp/popchain.txt`) |
| `--output=file` | Write payloads to a file; in JSON mode, writes JSON |
| `--max-depth=8` | Maximum chain depth |
| `--max-chains=50` | Maximum number of chains to output |
| `--include-vendor` | Also scan the `vendor/` directory |
| `--json` | Emit JSON |
| `--obfuscate` | Write strings as `S:` hex escapes so keyword filters do not see them |

## How it works

```
PHP source (file / stdin / URL)
  └─ ProjectParser (nikic/php-parser + NameResolver)
       └─ IR: ClassInfo / PropertyInfo / MethodInfo / CallSite / ArgInfo
            └─ ChainFinder (DFS)
                 ├─ ($this->p)(...)               → any class __invoke
                 ├─ call_user_func($this->p, ..)  → any class __invoke
                 ├─ call_user_func([$this->p, 'm']) → method m / __call
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

The payload is assembled straight into PHP's serialization format by `src/Payload/Serializer.php`; it **never loads or executes the target classes**. Property keys follow PHP conventions:

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
- `__unserialize(array $data)` entries: `$data['cmd']` reads are treated as attacker-controlled data, both when assigned to a property and when passed straight to a sink
- Sink categories: RCE, file read/write/delete/move, file inclusion, deserialization, callable, environment, variable injection, and more
- Payload output: textual chain paths, JSON (with tool name and version), payloads written to disk; NUL bytes are escaped on screen while `--output` keeps the raw bytes
- Entry semantics: chains that start from `__invoke` / `__toString` / `__get` and other non-deserialization magic methods are marked as needing an external trigger, so a payload that only works when called is not mistaken for a standalone gadget
- CLI guards: path and numeric option validation, with distinct exit codes (0 = chains found, 1 = no chains, 2 = bad input)

## Known limitations

Static analysis cannot cover every dynamic feature PHP offers. The current version does not handle:

- Reflection, code generated after `eval`, and `include` paths resolved at runtime
- Control-flow reachability (`if` conditions, exception paths, loop counts)
- `preg_replace_callback`: the callback receives a matches array rather than plain elements, so parameters are not mapped
- Sink arguments that combine several different properties: only the first controllable property is written into the payload
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
│   ├── Parser/               # AST → IR
│   ├── Model/                # classes/methods/properties/call sites/chains/edges
│   ├── Analysis/             # sink detection + chain search
│   └── Payload/              # object graph → hand-written serialization
├── examples/demo.php         # sample vulnerable code
├── tests/run.php             # test suite
├── tests/fixtures/           # parser/chain fixtures
├── composer.json             # dependency and autoload definition
├── README.zh-CN.md           # Chinese documentation
├── LICENSE                   # MIT
└── README.md
```

## Tests

```bash
php tests/run.php
```

The suite covers the parser layer, chain search, payload construction, and the CLI. Several cases build a payload, deserialize it for real, and confirm the sink fires, which is the only way to tell a usable chain from a chain that merely looks right on paper. It also checks traits, inheritance, local taint, `__get` / `__isset` edges, namespaces, private/protected serialization keys, and the CLI input paths.

## Security Notice

This tool is meant solely for **authorized CTF competitions, teaching, and auditing systems you own**. The generated payloads genuinely invoke the dangerous functions in the target code; do not use them against systems you are not authorized to test.

## License

[MIT](LICENSE) © 2026 guaidao2 & coolmoon
