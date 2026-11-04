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
