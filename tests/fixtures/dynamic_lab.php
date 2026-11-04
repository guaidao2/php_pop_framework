<?php
// 动态回调 + 常量 sink + 形参数组约束的链
class DynamicEngine
{
    public $mode;
    public $handler;
    public $param;

    public function __wakeup()
    {
        $action = "do" . ucfirst($this->mode);
        if (method_exists($this->handler, $action)) {
            call_user_func([$this->handler, $action], $this->param);
        }
    }
}

class DynamicCore
{
    public $target;
    private $secret = "guest";

    public function __get($name)
    {
        if ($name === 'key') {
            return "sys" . substr(md5($this->secret), 0, 3);
        }
        return null;
    }
}

class DynamicSys
{
    public function doExecute($args)
    {
        if (is_array($args) && count($args) === 2) {
            if ($args[0] == "admin" && is_numeric($args[1]) && intval($args[1]) > 1000) {
                system("cat /flag");
            }
        }
    }
}
