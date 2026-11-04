<?php
// 测试用：直接回答 unsat
declare(strict_types=1);
stream_get_contents(STDIN);
echo json_encode(['protocol_version' => '1.0', 'status' => 'unsat', 'assignments' => [], 'notes' => ['fake engine: unsat']]);
