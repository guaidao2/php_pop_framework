<?php
// urlencode 净化了 HEADER，urldecode 必须能撤销，否则头注入漏报
mail('a@b.test', 'subject', 'body', urldecode(urlencode((string) ($_GET['h'] ?? ''))));
