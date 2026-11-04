<?php
// 与上传无关的 php 字面量不能抑制真正的上传问题
if (($_GET['mode'] ?? '') === 'php') {
    exit;
}
$name = basename($_FILES['f']['name'] ?? '');
move_uploaded_file($_FILES['f']['tmp_name'], '/uploads/' . $name);
