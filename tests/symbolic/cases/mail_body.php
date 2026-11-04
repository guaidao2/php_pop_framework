<?php
// 正文不是响应头，不应报 header injection
mail('a@b.test', 'subject', (string) ($_GET['body'] ?? ''));
