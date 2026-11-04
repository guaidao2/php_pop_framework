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
            count($node->properties),
            $body,
        );
    }

    public function value(mixed $value): string
    {
        if ($value instanceof PayloadNode) {
            return $this->serializeNode($value);
        }
        if (is_string($value)) {
            return sprintf('s:%d:"%s";', strlen($value), $value);
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

        return sprintf('s:%d:"%s";', strlen($key), $key);
    }
}
