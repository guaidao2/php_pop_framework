<?php
// 测试用：输出非法 JSON，验证"引擎不可用"时回退启发式
declare(strict_types=1);
stream_get_contents(STDIN);
echo 'this is not json';
