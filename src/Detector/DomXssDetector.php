<?php
declare(strict_types=1);

namespace PopChain\Detector;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 客户端 HTML sink 检测：innerHTML / outerHTML / insertAdjacentHTML /
 * document.write 直接拼接动态值。若同一文件存在 fetch/XHR，则数据很可能
 * 来自 API/数据库，属于存储型/反射型 DOM XSS。
 */
final class DomXssDetector implements DetectorInterface
{
    /** @var list<string> */
    private array $warnings = [];

    /** DetectorRegistry 会传入链求解器；DOM 检测不需要，忽略即可。 */
    public function __construct(private mixed $solver = null)
    {
    }

    public function name(): string
    {
        return 'dom-xss';
    }

    public function description(): string
    {
        return 'Client-side HTML sinks (innerHTML/document.write), including stored XSS via API/DB data';
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<Finding> */
    public function detect(array $classes, array $options = []): array
    {
        $this->warnings = [];
        $path = (string) ($options['path'] ?? '.');
        $includeVendor = (bool) ($options['include_vendor'] ?? false);
        $findings = [];
        $seen = [];

        foreach ($this->files($path, $includeVendor) as $file) {
            if (preg_match('/\.(min|bundle)\.js$/i', $file) === 1) {
                continue;
            }
            $content = @file_get_contents($file);
            if (!is_string($content) || $content === '') {
                continue;
            }
            // 压缩/打包后的单行 JS 不适合做静态模板分析
            $lines = max(1, substr_count($content, "\n") + 1);
            if (strlen($content) / $lines > 800) {
                continue;
            }
            $hasApiSource = preg_match('/\bfetch\s*\(|\bXMLHttpRequest\b|\.json\s*\(/', $content) === 1;
            $fileSinkSeen = [];
            $rawLines = preg_split('/\R/', $content);
            foreach ($rawLines as $lineNumber => $line) {
                foreach (['innerHTML', 'outerHTML'] as $sink) {
                    $position = strpos($line, $sink);
                    if ($position === false) {
                        continue;
                    }
                    if (isset($fileSinkSeen[$sink])) {
                        continue;
                    }
                    $equals = strpos($line, '=', $position);
                    if ($equals === false) {
                        continue;
                    }
                    // 跳过 == / === 比较
                    if (($line[$equals + 1] ?? '') === '=') {
                        continue;
                    }
                    $rhs = trim(substr($line, $equals + 1));
                    $rhs = rtrim($rhs, " \t;");
                    if ($rhs === '') {
                        // 多行赋值：看下一行是否有动态模板
                        $next = trim($rawLines[$lineNumber + 1] ?? '');
                        if ($next === '' || $this->isConstantLiteral($next)) {
                            continue;
                        }
                        $rhs = $next;
                    }
                    if ($this->isConstantLiteral($rhs)) {
                        continue;
                    }
                    if (preg_match('/\b(?:escapeHtml|escape_html|htmlspecialchars|sanitize|DOMPurify)\s*\(/i', $rhs) === 1) {
                        continue;
                    }
                    $key = $file . ':' . ($lineNumber + 1) . ':' . $sink;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $fileSinkSeen[$sink] = true;
                    $findings[] = $this->makeFinding($file, $lineNumber + 1, $sink, $line, $hasApiSource, 'client-side HTML sink; verify escaping of the interpolated value');
                    if (count($findings) >= 100) {
                        $this->warnings[] = 'dom-xss: stopped after 100 findings';

                        return $findings;
                    }
                }
                $this->detectInsertAdjacent($line, $lineNumber + 1, $file, $hasApiSource, $fileSinkSeen, $findings);
                $this->detectDocumentWrite($line, $lineNumber + 1, $file, $hasApiSource, $fileSinkSeen, $findings);
            }
        }

        return $findings;
    }

    private function isConstantLiteral(string $rhs): bool
    {
        if (str_contains($rhs, '<?=') || str_contains($rhs, '<?php')) {
            return false;
        }
        if (preg_match('/^`.*`$/s', $rhs) === 1 && !str_contains($rhs, '${')) {
            return true;
        }
        if (preg_match('/^([\'"]).*\1$/s', $rhs) === 1 && !str_contains($rhs, '${')) {
            return true;
        }

        return in_array(strtolower($rhs), ['null', 'undefined', '0', 'false', 'true'], true);
    }

    /** @param list<Finding> $findings */
    private function makeFinding(string $file, int $line, string $sink, string $snippet, bool $hasApiSource, string $note): Finding
    {
        $notes = [$note];
        if ($hasApiSource) {
            $notes[] = 'file uses fetch/XMLHttpRequest: the value may come from the API or database (stored XSS)';
        }

        return new Finding(
            type: $this->name(),
            title: 'Client-side HTML sink (potential stored XSS)',
            notes: $notes,
            severity: 'high',
            category: 'DOM_XSS',
            file: $file,
            line: $line,
            sink: $sink,
            taint: ['client-side template value'],
            payload: '<img src=x onerror=alert(1)>',
            snippet: trim($snippet),
        );
    }

    /**
     * @param array<string, true> $fileSinkSeen
     * @param list<Finding> $findings
     */
    private function detectInsertAdjacent(
        string $line,
        int $lineNumber,
        string $file,
        bool $hasApiSource,
        array &$fileSinkSeen,
        array &$findings,
    ): void {
        if (!str_contains($line, 'insertAdjacentHTML(') || isset($fileSinkSeen['insertAdjacentHTML'])) {
            return;
        }
        $start = strpos($line, 'insertAdjacentHTML(');
        $rhs = trim(substr($line, $start + strlen('insertAdjacentHTML(')));
        $rhs = rtrim($rhs, " \t;");
        if ($rhs === '' || $this->isConstantLiteral($rhs)) {
            return;
        }
        $fileSinkSeen['insertAdjacentHTML'] = true;
        $findings[] = $this->makeFinding($file, $lineNumber, 'insertAdjacentHTML', $line, $hasApiSource, 'insertAdjacentHTML with a dynamic value');
    }

    /**
     * @param array<string, true> $fileSinkSeen
     * @param list<Finding> $findings
     */
    private function detectDocumentWrite(
        string $line,
        int $lineNumber,
        string $file,
        bool $hasApiSource,
        array &$fileSinkSeen,
        array &$findings,
    ): void {
        if (!str_contains($line, 'document.write(') || isset($fileSinkSeen['document.write'])) {
            return;
        }
        $start = strpos($line, 'document.write(');
        $rhs = trim(substr($line, $start + strlen('document.write(')));
        $rhs = rtrim($rhs, " \t;");
        if ($rhs === '' || $this->isConstantLiteral($rhs)) {
            return;
        }
        $fileSinkSeen['document.write'] = true;
        $findings[] = $this->makeFinding($file, $lineNumber, 'document.write', $line, $hasApiSource, 'document.write with a dynamic value');
    }

    /** @return list<string> */
    private function files(string $path, bool $includeVendor): array
    {
        if (is_file($path)) {
            return preg_match('/\.(js|mjs|php|html?)$/i', $path) === 1 ? [$path] : [];
        }
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', $file->getPathname());
                if (!$includeVendor && (str_contains($relative, '/vendor/') || str_contains($relative, '/node_modules/'))) {
                    continue;
                }
                if (preg_match('/\.(js|mjs|php|html?)$/i', $relative) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException $exception) {
            $this->warnings[] = 'dom-xss: cannot read directory: ' . $exception->getMessage();
        }

        return $files;
    }
}
