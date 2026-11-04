<?php
// urlencode 后再 urldecode，等于没净化
echo urldecode(urlencode($_GET['x'] ?? ''));
