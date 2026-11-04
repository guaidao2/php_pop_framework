<?php
// 已声明 JSON 响应，echo 不是 HTML 上下文
header('Content-Type: application/json');
echo json_encode(['name' => $_GET['n'] ?? '']);
