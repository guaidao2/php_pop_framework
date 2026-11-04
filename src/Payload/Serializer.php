<?php
declare(strict_types=1);

namespace PopChain\Payload;

/**
 * 手写 PHP 序列化格式，避免加载目标类（目标代码可能有副作用）。
 *
 * 属性键遵循 PHP 约定：
 *   public      name
 *   protected   \0*\0name
 *   private     \0DeclaringClass\0name
 */
final class Serializer
{
    /**
     * @param bool $obfuscate 用 S: 十六进制转义字符串（S:5:"f6..."）代替 s:，
     *                        用于绕过关键字过滤（O:+N: 这种带符号长度 PHP 解析器不接受，不生成）
     */
    public function __construct(private bool $obfuscate = false)
    {
    }

    public function serializeNode(PayloadNode $node): string
    {
        $body = '';
        foreach ($node->properties as $name => $property) {
            $body .= $this->propertyKey($name, $property['visibility'], $property['declaringClass']);
            $body .= $this->value($property['value']);
        }

        return sprintf(
            'O:%d:"%s":%d:{%s}',
            strlen($node->class),
            $node->class,
            count($node->properties) + $node->declaredBump,
            $body,
        );
    }

    public function value(mixed $value): string
    {
        if ($value instanceof PayloadNode) {
            return $this->serializeNode($value);
        }
        if (is_string($value)) {
            return $this->stringToken($value);
        }
        if (is_int($value)) {
            return 'i:' . $value . ';';
        }
        if (is_bool($value)) {
            return 'b:' . ($value ? '1' : '0') . ';';
        }
        if ($value === null) {
            return 'N;';
        }
        if (is_float($value)) {
            return 'd:' . $value . ';';
        }
        if (is_array($value)) {
            $body = '';
            foreach ($value as $key => $item) {
                $body .= $this->value($key) . $this->value($item);
            }
            return 'a:' . count($value) . ':{' . $body . '}';
        }

        throw new \InvalidArgumentException('无法序列化的值类型: ' . get_debug_type($value));
    }

    private function propertyKey(string $name, string $visibility, string $declaringClass): string
    {
        $key = match ($visibility) {
            'private' => "\0" . $declaringClass . "\0" . $name,
            'protected' => "\0*\0" . $name,
            default => $name,
        };

        return $this->stringToken($key);
    }

    /** 普通模式 s:；混淆模式 S: + 全字节十六进制转义。 */
    private function stringToken(string $value): string
    {
        if (!$this->obfuscate) {
            return sprintf('s:%d:"%s";', strlen($value), $value);
        }

        $escaped = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $escaped .= sprintf('\\%02x', ord($value[$index]));
        }

        return sprintf('S:%d:"%s";', strlen($value), $escaped);
    }
}
