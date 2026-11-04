<?php
declare(strict_types=1);

// 解析层 fixture：字符串上下文 / include / eval / 属性提升
class Tpl
{
    public $file;

    public function __toString(): string
    {
        return file_get_contents($this->file);
    }
}

class Renderer
{
    public $tpl;

    public function render(): void
    {
        echo 'prefix: ' . $this->tpl;
    }
}

class Loader
{
    public $path;

    public function __destruct()
    {
        include $this->path;
    }
}

class Evaler
{
    public $code;

    public function __destruct()
    {
        eval($this->code);
    }
}

class Promoted
{
    public function __construct(
        public string $cmd,
        protected $extra = 'x',
    ) {
    }
}
