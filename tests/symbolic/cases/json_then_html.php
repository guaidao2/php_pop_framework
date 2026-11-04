<?php
// JSON 头之后又改成 HTML，echo 仍应按 XSS 报告
header('Content-Type: application/json');
header('Content-Type: text/html');
echo $_GET['x'] ?? '';
