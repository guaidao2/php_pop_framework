<?php
declare(strict_types=1);

/**
 * 依赖引导：优先加载项目内置的 php-parser，其次 composer / 系统 apt 安装。
 * 找不到时给出明确的安装指引，而不是致命错误堆栈。
 */

$candidates = [
    __DIR__ . '/../vendor/PhpParser/autoload.php', // 项目内置
    __DIR__ . '/../vendor/autoload.php',           // composer
    '/usr/share/php/PhpParser/autoload.php',       // Debian / Kali: apt install php-parser
];

$loaded = false;
foreach ($candidates as $file) {
    if (is_file($file)) {
        require_once $file;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, <<<TXT
[!] 缺少解析依赖 nikic/php-parser。

请任选一种方式安装：
  1) Kali / Debian：  sudo apt install php-parser
  2) Composer：       composer require nikic/php-parser
  3) 离线：           把 php-parser 解压到 vendor/PhpParser/

安装后重新运行即可。

TXT);
    exit(2);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PopChain\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
