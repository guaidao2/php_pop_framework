<?php
// mail 的第 4 个参数（additional_headers）是头注入位
mail('a@b.test', 'subject', 'body', (string) ($_GET['h'] ?? ''));
