<?php
// 测试用的假符号执行引擎：读请求，回一个 sat + 一条赋值
declare(strict_types=1);

$raw = stream_get_contents(STDIN);
$request = json_decode((string) $raw, true);

$entry = is_array($request) ? ($request['entry'] ?? []) : [];
$class = is_array($entry) ? (string) ($entry['class'] ?? '') : '';

echo json_encode([
    'protocol_version' => '1.0',
    'status' => 'sat',
    'assignments' => [
        ['class' => $class, 'property' => 'engine_marker', 'value' => 'from_engine'],
    ],
    'notes' => ['fake engine: constraints satisfied'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
