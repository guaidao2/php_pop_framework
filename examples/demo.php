<?php
// 模拟一道 CTF 题：反序列化入口 + 三条可组合的 gadget
class Entry {
    public $callback;
    public $payload;
    public function __destruct() {
        if (is_callable($this->callback)) {
            ($this->callback)($this->payload);
        }
    }
}

class Runner {
    public $fn;
    public $arg;
    public function __invoke($ignored) {
        call_user_func($this->fn, $this->arg);
    }
}

class Sink {
    public function __invoke($cmd) {
        system($cmd);
    }
}

class Holder {
    private $secret = 'p';
    protected $mid = 'q';
    public $open = 'r';
}
