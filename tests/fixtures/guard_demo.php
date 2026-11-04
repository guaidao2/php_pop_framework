<?php
declare(strict_types=1);

// 属性比较作为前置条件（=== true / === 'admin_token'）的典型链
class GuardEntry
{
    public $is_admin = false;
    public $profile;

    public function __destruct()
    {
        if ($this->is_admin === true) {
            echo $this->profile;
        }
    }
}

class GuardProfile
{
    public $settings;

    public function __toString(): string
    {
        return (string) $this->settings->get_flag();
    }
}

class GuardSettings
{
    private $token = 'guest_token';
    public $cmd;

    public function __call($name, $args)
    {
        if ($this->token === 'admin_token') {
            return system($this->cmd);
        }
        return 'Access Denied';
    }
}

// 早退式守卫：ttl <= 0 时直接返回，所以要走到 sink 必须 ttl > 0
class GuardEarlyEntry
{
    public $ttl = 0;
    public $cmd;

    public function __destruct()
    {
        if ($this->ttl <= 0) {
            return;
        }
        system($this->cmd);
    }
}

// isset 守卫：没有 flag 就直接返回
class GuardIssetEntry
{
    public $flag;
    public $cmd;

    public function __destruct()
    {
        if (!isset($this->flag)) {
            return;
        }
        system($this->cmd);
    }
}

// 类型守卫：必须是数组
class GuardArrayEntry
{
    public $arr;
    public $cmd;

    public function __destruct()
    {
        if (!is_array($this->arr)) {
            return;
        }
        system($this->cmd);
    }
}

// 不等于守卫：mode === 'debug' 时返回
class GuardNotEqualEntry
{
    public $mode = 'debug';
    public $cmd;

    public function __destruct()
    {
        if ($this->mode === 'debug') {
            return;
        }
        system($this->cmd);
    }
}

// 属性直接作为条件
class GuardTruthyEntry
{
    public $enabled = false;
    public $cmd;

    public function __destruct()
    {
        if (!$this->enabled) {
            return;
        }
        system($this->cmd);
    }
}
