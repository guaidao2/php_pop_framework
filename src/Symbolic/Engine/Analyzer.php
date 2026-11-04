<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PhpParser\ParserFactory;
use PopChain\Symbolic\Security\FindingCollector;
use PopChain\Symbolic\Security\SourceCatalog;
use PopChain\Symbolic\Solver\Solver;

/**
 * 分析入口：解析文件、建立索引、对每个入口执行符号执行。
 */
final class Analyzer
{
    public function __construct(
        private readonly AnalyzerOptions $options = new AnalyzerOptions(),
    ) {
    }

    /**
     * @param list<string> $paths 文件或目录
     */
    public function analyze(array $paths, ?string $entry = null): AnalysisResult
    {
        $started = microtime(true);
        $files = $this->collectFiles($paths);
        $parseErrors = [];

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $program = new Program();

        foreach ($files as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                $parseErrors[] = $file . ': 无法读取';
                continue;
            }
            try {
                $stmts = $parser->parse($source);
            } catch (\Throwable $exception) {
                $parseErrors[] = $file . ': ' . $exception->getMessage();
                continue;
            }
            if ($stmts === null) {
                continue;
            }
            $program->addFile($file, $stmts);
        }

        $findings = new FindingCollector();
        $solver = new Solver();
        $builtins = new Builtins();
        $interpreter = new Interpreter($program, $this->options, $builtins, $findings, $solver);

        $targets = $program->files();
        if ($entry !== null) {
            $entry = realpath($entry) ?: $entry;
            $targets = in_array($entry, $targets, true) ? [$entry] : [];
            if ($targets === []) {
                $parseErrors[] = $entry . ': 入口文件不在已索引文件中';
            }
        }

        foreach ($targets as $target) {
            $globals = new GlobalScope();
            $globals->vars = SourceCatalog::superglobals($this->options->argvAsSource);
            $state = new State($globals->vars, $globals, [], true);
            $interpreter->executeFile($target, $state);
        }

        return new AnalysisResult(
            $findings->all(),
            count($files),
            $interpreter->pathCount(),
            $solver->stats(),
            $interpreter->wasTruncated(),
            microtime(true) - $started,
            $interpreter->statementCount(),
            $parseErrors,
        );
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function collectFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            if (is_file($real)) {
                $files[] = $real;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
                    function (\SplFileInfo $file) use ($real): bool {
                        if (!$file->isDir()) {
                            return true;
                        }
                        if (in_array($file->getFilename(), $this->options->excludeDirs, true)) {
                            return false;
                        }

                        return true;
                    },
                ),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getRealPath() ?: $file->getPathname();
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }
}
