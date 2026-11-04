<?php
declare(strict_types=1);

namespace PopChain\Detector;

use PopChain\Model\ClassInfo;

/**
 * 漏洞检测器插件接口。
 *
 * 每个漏洞类型一个实现：反序列化链是第一个，后续的 SQL 注入、SSRF、命令注入等
 * 都按同一个接口接进来。输入是解析好的类信息与 CLI 选项，输出是检测结果列表。
 */
interface DetectorInterface
{
    /** 机器可读的类型名，例如 deserialization。 */
    public function name(): string;

    /** 一句话说明，用于 --list-types。 */
    public function description(): string;

    /**
     * @param array<string, ClassInfo> $classes
     * @param array<string, mixed> $options
     * @return list<Finding>
     */
    public function detect(array $classes, array $options = []): array;
}
