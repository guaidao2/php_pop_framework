<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use PopChain\Analysis\ChainFinder;
use PopChain\Analysis\SinkScanner;
use PopChain\Cli\Application;
use PopChain\Detector\DetectorRegistry;
use PopChain\Model\ArgInfo;
use PopChain\Model\CallSite;
use PopChain\Model\Chain;
use PopChain\Parser\ProjectParser;
use PopChain\Payload\PayloadBuilder;
use PopChain\Payload\PayloadNode;
use PopChain\Payload\Serializer;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** @return array<string, Chain> 入口短类名 => 链 */
function chainsByEntry(array $chains): array
{
    $map = [];
    foreach ($chains as $chain) {
        $name = $chain->entryClass->shortName();
        if (!isset($map[$name]) || count($chain->edges) < count($map[$name]->edges)) {
            $map[$name] = $chain;
        }
    }
    return $map;
}

echo "== 解析层 ==\n";
$classes = (new ProjectParser())->parsePath(__DIR__ . '/../examples/demo.php');
check('解析出 4 个类', count($classes) === 4, '实际 ' . count($classes));
check('Entry::__destruct 标记为魔术方法', ($classes['Entry']->method('__destruct')?->magic) === true);

$hasCallProperty = false;
foreach ($classes['Entry']->method('__destruct')->calls as $call) {
    if ($call->kind === CallSite::CALL_PROPERTY && $call->receiverName === 'callback') {
        $hasCallProperty = true;
    }
}
check('识别 ($this->callback)(...) 动态调用', $hasCallProperty);

$argControllable = false;
foreach ($classes['Runner']->method('__invoke')->calls as $call) {
    if ($call->target === 'call_user_func' && ($call->args[0] ?? null)?->isControllable()) {
        $argControllable = true;
    }
}
check('call_user_func 的 $this->fn 标记为可控', $argControllable);

echo "== 链搜索 ==\n";
$chains = (new ChainFinder($classes, new SinkScanner()))->find();
check('找到 2 条链', count($chains) === 2, '实际 ' . count($chains));

$systemChains = array_values(array_filter(
    $chains,
    static fn ($chain): bool => $chain->sink?->function === 'system',
));
check('两条链都命中 system()', count($systemChains) === 2);
check('存在一条三节点链', ($systemChains[0]->length() ?? 0) === 3 || ($systemChains[1]->length() ?? 0) === 3);

echo "== payload 构建 ==\n";
$builder = new PayloadBuilder($classes);
$longChain = $systemChains[0]->length() >= $systemChains[1]->length() ? $systemChains[0] : $systemChains[1];
$payload = $builder->build($longChain, 'echo TEST_MARKER');
check('payload 以 Entry 对象开头', str_starts_with($payload, 'O:5:"Entry":'), $payload);

require __DIR__ . '/../examples/demo.php';
ob_start();
$object = unserialize($payload);
unset($object);
gc_collect_cycles();
$output = ob_get_clean();
check('payload 真实反序列化后触发 system()', str_contains($output, 'TEST_MARKER'), trim($output));

echo "== 序列化格式 ==\n";
$node = new PayloadNode('Holder');
$node->set('secret', 'p', 'private', 'Holder');
$node->set('mid', 'q', 'protected', 'Holder');
$node->set('open', 'r', 'public', 'Holder');
$serialized = (new Serializer())->serializeNode($node);
check('private 属性键为 \0Holder\0secret', str_contains($serialized, "\0Holder\0secret"));
check('protected 属性键为 \0*\0mid', str_contains($serialized, "\0*\0mid"));
check('public 属性键为 open', str_contains($serialized, 's:4:"open";'));

echo "== 字符串上下文 fixture ==\n";
$fixture = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/string_demo.php');
$fixtureChains = (new ChainFinder($fixture, new SinkScanner()))->find();
$functions = array_map(static fn ($chain): ?string => $chain->sink?->function, $fixtureChains);
check('找到 include 链', in_array('include', $functions, true));
check('找到 eval 链', in_array('eval', $functions, true));
check('找到 __toString → file_get_contents 链', in_array('file_get_contents', $functions, true));

$toStringChains = (new ChainFinder($fixture, new SinkScanner()))->find(['__toString']);
check('__toString 可作为入口搜索', count($toStringChains) === 1, '实际 ' . count($toStringChains));

echo "== trait 合并 ==\n";
$traits = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/traits_demo.php');
check('解析出 trait 与使用类', isset($traits['CommandTrait'], $traits['TraitEntry']));
check('trait 方法合并进使用类', $traits['TraitEntry']->method('__destruct') !== null);
check('trait 属性合并进使用类', $traits['TraitEntry']->property('cmd') !== null);
check('trait private 属性归属使用类', ($traits['TraitEntry']->property('suffix')?->declaringClass) === 'TraitEntry');
check('trait 嵌套使用也会展开', $traits['NestedTraitEntry']->method('ping') !== null);

$traitChains = (new ChainFinder($traits, new SinkScanner()))->find();
$traitByEntry = chainsByEntry($traitChains);
check('trait 里的入口也能出链', isset($traitByEntry['TraitEntry'], $traitByEntry['NestedTraitEntry']), '实际 ' . implode(',', array_keys($traitByEntry)));
$traitPayload = (new PayloadBuilder($traits))->build($traitByEntry['TraitEntry'], 'id');
check('trait 链 payload 使用使用类名', str_starts_with($traitPayload, 'O:10:"TraitEntry":'), $traitPayload);
check('trait 链 payload 写入 cmd 属性', str_contains($traitPayload, 's:3:"cmd";s:2:"id";'), $traitPayload);

echo "== 继承 ==\n";
$inherit = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/inherit_demo.php');
$inheritChains = (new ChainFinder($inherit, new SinkScanner()))->find();
$inheritByEntry = chainsByEntry($inheritChains);
check('子类继承入口方法也能出链', count($inheritChains) === 3, '实际 ' . count($inheritChains));
$pseudoProp = false;
foreach ($inheritChains as $chain) {
    $built = (new PayloadBuilder($inherit))->build($chain, 'id');
    if (str_contains($built, '(this)') || str_contains($built, '(static)')) {
        $pseudoProp = true;
    }
}
check('方法/静态跳转不产生 payload 伪属性', !$pseudoProp);
check('抽象父类不单独作为入口', !isset($inheritByEntry['BaseRunner']));
$childPayload = (new PayloadBuilder($inherit))->build($inheritByEntry['ChildRunner'], 'id');
check('payload 用子类名并带 protected 键', str_starts_with($childPayload, 'O:11:"ChildRunner":') && str_contains($childPayload, "\0*\0cmd"), $childPayload);

echo "== 污点传播 ==\n";
$taint = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/taint_demo.php');
$taintByEntry = chainsByEntry((new ChainFinder($taint, new SinkScanner()))->find());
check('局部变量层层赋值仍可控', isset($taintByEntry['TaintEntry']));
check('sink 参数回溯到属性', str_contains($taintByEntry['TaintEntry']->sink?->arg->describe() ?? '', '$this->cmd'));
check('字符串拼接识别为可控', ($taintByEntry['ConcatEntry']->sink?->arg->kind ?? '') === ArgInfo::CONCAT);
check('方法回调数组能出链', isset($taintByEntry['CallableArrayEntry']));
check('属性赋值能传播', isset($taintByEntry['PropCopyEntry']));

$taintPayload = (new PayloadBuilder($taint))->build($taintByEntry['TaintEntry'], 'echo TAINT');
check('污点链 payload 写入属性值', str_contains($taintPayload, 's:3:"cmd";s:10:"echo TAINT";'), $taintPayload);

echo "== 魔术方法触发 ==\n";
$magic = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/magic_demo.php');
$magicChains = (new ChainFinder($magic, new SinkScanner()))->find();
$viaKinds = [];
foreach ($magicChains as $chain) {
    foreach ($chain->edges as $edge) {
        $viaKinds[] = $edge->viaKind;
    }
}
check('读取未知属性触发 __get', in_array('get', $viaKinds, true), implode(',', $viaKinds));
check('isset 触发 __isset', in_array('isset', $viaKinds, true), implode(',', $viaKinds));
$magicByEntry = chainsByEntry($magicChains);
check('__get 链命中 system', ($magicByEntry['GetEntry']->sink?->function ?? '') === 'system');
check('__isset 链命中 system', ($magicByEntry['IssetEntry']->sink?->function ?? '') === 'system');
$magicEntries = (new ChainFinder($magic, new SinkScanner()))->find(['__get', '__isset']);
check('__get/__isset 可作为入口', count($magicEntries) === 2, '实际 ' . count($magicEntries));

echo "== 端到端 exploitable ==\n";
$method = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/method_demo.php');
$methodChains = (new ChainFinder($method, new SinkScanner()))->find();
$methodByEntry = chainsByEntry($methodChains);
check('方法调用边能找到链', isset($methodByEntry['ChainEntry']), '实际 ' . implode(',', array_keys($methodByEntry)));
$methodPayload = (new PayloadBuilder($method))->build($methodByEntry['ChainEntry'], 'echo POPCHAIN_OK');
require __DIR__ . '/fixtures/method_demo.php';
ob_start();
$object = unserialize($methodPayload);
unset($object);
gc_collect_cycles();
$methodOutput = trim((string) ob_get_clean());
check('方法边链 payload 真实执行', str_contains($methodOutput, 'POPCHAIN_OK'), $methodPayload . ' / ' . $methodOutput);

echo "== 命名空间 ==\n";
$ns = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/namespace_demo.php');
$nsChains = (new ChainFinder($ns, new SinkScanner()))->find();
$nsByEntry = chainsByEntry($nsChains);
check('命名空间类名解析正确', isset($nsByEntry['NamespacedEntry']), '实际 ' . implode(',', array_keys($nsByEntry)));
$nsPayload = (new PayloadBuilder($ns))->build($nsByEntry['NamespacedEntry'], 'echo NS_OK');
check('private 键带命名空间', str_contains($nsPayload, "\0Demo\\Ns\\NamespacedEntry\0cmd"), $nsPayload);
require __DIR__ . '/fixtures/namespace_demo.php';
ob_start();
$object = unserialize($nsPayload);
unset($object);
gc_collect_cycles();
$nsOutput = trim((string) ob_get_clean());
check('命名空间 payload 真实执行', str_contains($nsOutput, 'NS_OK'), $nsOutput);

echo "== 回调与实参可控性 ==\n";
$callable = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/callable_demo.php');
$callableByEntry = chainsByEntry((new ChainFinder($callable, new SinkScanner()))->find());
check('方法回调数组（实参可控）出链', isset($callableByEntry['CallableArrayEntry']));
check('常量实参不误报', !isset($callableByEntry['ConstCallEntry']));
check('call_user_func_array 能出链', isset($callableByEntry['CufaEntry']));
check('array_map 能出链', isset($callableByEntry['MapEntry']));

$callableBuilder = new PayloadBuilder($callable);
$cufaPayload = $callableBuilder->build($callableByEntry['CufaEntry'], 'echo CUFA_OK');
check('call_user_func_array 的 payload 写数组而不是标量', str_contains($cufaPayload, 'a:1:{'), $cufaPayload);
$mapPayload = $callableBuilder->build($callableByEntry['MapEntry'], 'echo MAP_OK');
check('array_map 的 payload 写数组而不是标量', str_contains($mapPayload, 'a:1:{'), $mapPayload);

require __DIR__ . '/fixtures/callable_demo.php';
ob_start();
$object = unserialize($cufaPayload);
unset($object);
gc_collect_cycles();
$cufaOutput = trim((string) ob_get_clean());
check('call_user_func_array payload 真实执行', str_contains($cufaOutput, 'CUFA_OK'), $cufaOutput);

ob_start();
$object = unserialize($mapPayload);
unset($object);
gc_collect_cycles();
$mapOutput = trim((string) ob_get_clean());
check('array_map payload 真实执行', str_contains($mapOutput, 'MAP_OK'), $mapOutput);

echo "== 负向场景 ==\n";
$negative = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/negative_demo.php');
$negativeChains = (new ChainFinder($negative, new SinkScanner()))->find();
check('常量实参 / 已声明属性 / 常量覆盖都不报链', $negativeChains === [], '实际 ' . count($negativeChains));

echo "== 字符串触发点 ==\n";
$strings = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/strings_demo.php');
$stringChains = (new ChainFinder($strings, new SinkScanner()))->find();
$stringByEntry = chainsByEntry($stringChains);
foreach (['SprintfEntry', 'ReadEntry', 'StrvalEntry', 'InterpEntry', 'ExitEntry'] as $entry) {
    check($entry . ' 能触发 __toString 出链', isset($stringByEntry[$entry]));
}
check('局部别名作为接收者能出链', isset($stringByEntry['AliasEntry']));
$sprintfPayload = (new PayloadBuilder($strings))->build($stringByEntry['SprintfEntry'], 'echo STR_OK');
check('sprintf 链 payload 指向 __toString gadget', str_contains($sprintfPayload, 'O:6:"Render":'), $sprintfPayload);

echo "== 私有属性同名冲突 ==\n";
$collision = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/private_collision_demo.php');
$collisionChains = chainsByEntry((new ChainFinder($collision, new SinkScanner()))->find());
check('同名私有属性场景能出链', isset($collisionChains['EntryP']));
$collisionPayload = (new PayloadBuilder($collision))->build($collisionChains['EntryP'], 'echo COLLISION_OK');
check('私有属性 key 用 fromClass 的类名', str_contains($collisionPayload, "\0Verify\Private\Cases\EntryP\0obj"), $collisionPayload);
require __DIR__ . '/fixtures/private_collision_demo.php';
ob_start();
$object = unserialize($collisionPayload);
unset($object);
gc_collect_cycles();
$collisionOutput = trim((string) ob_get_clean());
check('同名私有属性 payload 真实执行', str_contains($collisionOutput, 'COLLISION_OK'), $collisionOutput);

echo "== first-class callable ==\n";
$modern = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/modern_demo.php');
$modernChains = chainsByEntry((new ChainFinder($modern, new SinkScanner()))->find());
check('first-class callable 不影响出链', isset($modernChains['ModernEntry']));
$modernPayload = (new PayloadBuilder($modern))->build($modernChains['ModernEntry'], 'echo MODERN_OK');
check('payload 带上了 sink 实参', str_contains($modernPayload, 'echo MODERN_OK'), $modernPayload);
require __DIR__ . '/fixtures/modern_demo.php';
ob_start();
$object = unserialize($modernPayload);
unset($object);
gc_collect_cycles();
$modernOutput = trim((string) ob_get_clean());
check('first-class callable 场景 payload 真实执行', str_contains($modernOutput, 'MODERN_OK'), $modernOutput);

echo "== __unserialize 数据数组 ==\n";
$uns = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/unserialize_demo.php');
$unsByEntry = chainsByEntry((new ChainFinder($uns, new SinkScanner()))->find());
check('$data 数组直接传给 sink 也能出链', isset($unsByEntry['UnsDirectEntry']));
check('$data 数组经属性中转也能出链', isset($unsByEntry['UnsPropEntry']));

$unsBuilder = new PayloadBuilder($uns);
$unsDirectPayload = $unsBuilder->build($unsByEntry['UnsDirectEntry'], 'echo UNS_DIRECT_OK');
$unsPropPayload = $unsBuilder->build($unsByEntry['UnsPropEntry'], 'echo UNS_PROP_OK');
check('$data 数组元素写进 payload', str_contains($unsDirectPayload, 's:3:"cmd";'), $unsDirectPayload);

require __DIR__ . '/fixtures/unserialize_demo.php';
ob_start();
$object = unserialize($unsDirectPayload);
unset($object);
gc_collect_cycles();
$unsDirectOutput = trim((string) ob_get_clean());
check('$data 直接传参 payload 真实执行', str_contains($unsDirectOutput, 'UNS_DIRECT_OK'), $unsDirectOutput);

ob_start();
$object = unserialize($unsPropPayload);
unset($object);
gc_collect_cycles();
$unsPropOutput = trim((string) ob_get_clean());
check('$data 经属性中转 payload 真实执行', str_contains($unsPropOutput, 'UNS_PROP_OK'), $unsPropOutput);

echo "== 原已知限制的覆盖 ==\n";
$limits = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/limits_demo.php');
$limitsByEntry = chainsByEntry((new ChainFinder($limits, new SinkScanner()))->find());
foreach ([
    'VarFuncEntry' => '变量函数（属性）',
    'LiteralFuncEntry' => '变量函数（函数名字符串）',
    'ArrFuncEntry' => '变量函数（可调用数组）',
    'SortEntry' => 'usort 比较器',
    'CallArgsEntry' => '__call 实参数组',
    'NestedUnsEntry' => '__unserialize 嵌套数组',
    'TraitAdaptEntry' => 'trait insteadof/as',
] as $entry => $label) {
    check($label . ' 能出链', isset($limitsByEntry[$entry]));
}

// trait 适配：run 必须来自 RunTraitA（带 sink），runB 是别名
$adaptRun = $limits['TraitAdaptEntry']->method('run');
$adaptRunHasSink = false;
foreach ($adaptRun?->calls ?? [] as $call) {
    if ($call->target === 'system') {
        $adaptRunHasSink = true;
    }
}
check('insteadof 选中带 sink 的 trait 方法', $adaptRunHasSink);
check('as 生成了别名方法 runB', $limits['TraitAdaptEntry']->method('runB') !== null);

$limitsBuilder = new PayloadBuilder($limits);
$execCases = [
    'VarFuncEntry' => 'VARFUNC_OK',
    'LiteralFuncEntry' => 'LITERAL_OK',
    'ArrFuncEntry' => 'ARRFUNC_OK',
    'SortEntry' => 'SORT_OK',
    'CallArgsEntry' => 'CALLARGS_OK',
    'NestedUnsEntry' => 'NESTED_OK',
];
require __DIR__ . '/fixtures/limits_demo.php';
foreach ($execCases as $entry => $marker) {
    $built = $limitsBuilder->build($limitsByEntry[$entry], 'echo ' . $marker);
    ob_start();
    $object = unserialize($built);
    unset($object);
    gc_collect_cycles();
    $output = trim((string) ob_get_clean());
    check($entry . ' payload 真实执行', str_contains($output, $marker), $built . ' / ' . $output);
}

echo "== __wakeup 重置属性 ==\n";
$wakeBlocked = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/wakeup_blocked.php');
$blockedByEntry = chainsByEntry((new ChainFinder($wakeBlocked, new SinkScanner()))->find());
check('深层 __wakeup 重置的链仍然出链', isset($blockedByEntry['WakeBlockedApp']));
$markerFile = tempnam(sys_get_temp_dir(), 'popchain_wakeup_');
file_put_contents((string) $markerFile, 'WAKEUP_BYPASS_OK');
$blockedPayload = (new PayloadBuilder($wakeBlocked))->build($blockedByEntry['WakeBlockedApp'], (string) $markerFile);
check('payload 声明数大于实际、body 故意少写（CVE-2016-7124 绕过）', str_contains($blockedPayload, 'O:17:"WakeBlockedCacher":3:'), $blockedPayload);

require __DIR__ . '/fixtures/wakeup_blocked.php';
ob_start();
$blockedObject = @unserialize($blockedPayload);
$blockedRead = (string) ob_get_clean();
check('解析失败但析构照跑，__wakeup 被跳过', $blockedObject === false && str_contains($blockedRead, 'WAKEUP_BYPASS_OK'), $blockedRead);
if ($markerFile !== false) {
    unlink($markerFile);
}

$wakeOpen = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/wakeup_open.php');
$openByEntry = chainsByEntry((new ChainFinder($wakeOpen, new SinkScanner()))->find());
check('没有 __wakeup 的同构链仍然出链', isset($openByEntry['WakeOpenApp']));
$wakeOpenPayload = (new PayloadBuilder($wakeOpen))->build($openByEntry['WakeOpenApp'], 'echo OPEN_OK');
check('同构链 payload 写入私有的 filename', str_contains($wakeOpenPayload, "\0WakeOpenCacher\0filename"), $wakeOpenPayload);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/wakeup_blocked.php']);
$blockedCli = ob_get_clean();
check('CLI 提示需要 CVE-2016-7124', str_contains($blockedCli, 'CVE-2016-7124'), $blockedCli);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/wakeup_open.php']);
$openCli = ob_get_clean();
check('file_read 默认值使用文件路径', str_contains($openCli, '/etc/passwd'), $openCli);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/wakeup_open.php', '--obfuscate', '--pop-cmd=/flag']);
$obfuscated = ob_get_clean();
check('--obfuscate 用 S: 十六进制转义字符串', str_contains($obfuscated, 'S:') && str_contains($obfuscated, '\2f'), $obfuscated);
$payloadPart = substr($obfuscated, (int) strpos($obfuscated, 'payload:'));
check('--obfuscate 后 payload 不含明文 flag', !str_contains($payloadPart, 'flag'), $payloadPart);

$obfuscatedPayload = trim((string) preg_replace('/^.*payload:\s*/s', '', $obfuscated));
require __DIR__ . '/fixtures/wakeup_open.php';
$reparsed = @unserialize($obfuscatedPayload);
check('--obfuscate 的 payload 仍可被 PHP 解析', $reparsed !== false, $obfuscatedPayload);
unset($reparsed);

echo "== 文件类 sink 专用选项 ==\n";
$fileSinkCli = static function (array $extra) {
    ob_start();
    Application::run(array_merge(
        ['popchain', 'find', __DIR__ . '/fixtures/file_sinks.php'],
        $extra,
    ));
    return ob_get_clean();
};

$defaultRead = $fileSinkCli(['--class=ReadEntry']);
check('file_read 默认值使用文件路径', str_contains($defaultRead, '/etc/passwd'), $defaultRead);

$readFlag = $fileSinkCli(['--class=ReadEntry', '--pop-read-file=/flag']);
check('--pop-read-file 覆盖文件读取目标', str_contains($readFlag, '/flag'), $readFlag);

$writeFile = $fileSinkCli(['--class=WriteEntry', '--pop-write-file=/tmp/writeme.txt']);
check('--pop-write-file 覆盖写入目标', str_contains($writeFile, '/tmp/writeme.txt'), $writeFile);

$deleteFile = $fileSinkCli(['--class=DeleteEntry', '--pop-delete-file=/tmp/delete-me.txt']);
check('--pop-delete-file 覆盖删除目标', str_contains($deleteFile, '/tmp/delete-me.txt'), $deleteFile);

$moveFile = $fileSinkCli(['--class=MoveEntry', '--pop-move-file=/tmp/move-me.txt']);
check('--pop-move-file 覆盖移动目标', str_contains($moveFile, '/tmp/move-me.txt'), $moveFile);

$includeFile = $fileSinkCli(['--class=IncludeEntry', '--pop-read-file=/tmp/inc.php']);
check('--pop-read-file 同时覆盖 include 目标', str_contains($includeFile, '/tmp/inc.php'), $includeFile);

$chmodFile = $fileSinkCli(['--class=ChmodEntry', '--pop-chmod-file=/tmp/mode.txt']);
check('--pop-chmod-file 覆盖权限目标', str_contains($chmodFile, '/tmp/mode.txt'), $chmodFile);

$ssrfUrl = $fileSinkCli(['--class=SsrfEntry', '--pop-ssrf-url=http://169.254.169.254/']);
check('--pop-ssrf-url 覆盖 SSRF 目标', str_contains($ssrfUrl, 'http://169.254.169.254/'), $ssrfUrl);

$envValue = $fileSinkCli(['--class=EnvEntry', '--pop-env-value=POC=1']);
check('--pop-env-value 覆盖环境变量值', str_contains($envValue, 'POC=1'), $envValue);

$varValue = $fileSinkCli(['--class=VarEntry', '--pop-var-value=x=1']);
check('--pop-var-value 覆盖变量注入值', str_contains($varValue, 'x=1'), $varValue);

$mailTo = $fileSinkCli(['--class=MailEntry', '--pop-mail-to=test@example.test']);
check('--pop-mail-to 覆盖收件人', str_contains($mailTo, 'test@example.test'), $mailTo);

$serialized = $fileSinkCli(['--class=DeserializeEntry', '--pop-serialized=O:8:"stdClass":0:{}']);
check('--pop-serialized 覆盖反序列化数据', str_contains($serialized, 'stdClass'), $serialized);

ob_start();
Application::run(['popchain', 'help']);
$helpText = ob_get_clean();
check('帮助里列出 --pop-read-file / --pop-write-file', str_contains($helpText, '--pop-read-file') && str_contains($helpText, '--pop-write-file'));

echo "== URL 源码提取 ==\n";
$rawSource = "<?php class A { public \$x; }";
check('extractPhpSource 直接返回源码', Application::extractPhpSource($rawSource) === $rawSource);

$demoSource = (string) file_get_contents(__DIR__ . '/fixtures/method_demo.php');
ob_start();
highlight_string($demoSource);
$highlighted = (string) ob_get_clean();
$extracted = Application::extractPhpSource($highlighted);
check('能从 highlight_file 渲染页提取源码', is_string($extracted) && str_contains($extracted, 'class ChainEntry'), is_string($extracted) ? substr($extracted, 0, 80) : 'null');
check('普通 HTML 页面判为非源码', Application::extractPhpSource('<html><body><h1>hello</h1></body></html>') === null);

echo "== 属性守卫前置条件 ==\n";
$guard = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/guard_demo.php');
$guardByEntry = chainsByEntry((new ChainFinder($guard, new SinkScanner()))->find());
check('带属性比较守卫的链能找到', isset($guardByEntry['GuardEntry']));

$guardPayload = (new PayloadBuilder($guard))->build($guardByEntry['GuardEntry'], 'echo GUARD_OK');
check('payload 写入 is_admin=true', str_contains($guardPayload, 's:8:"is_admin";b:1;'), $guardPayload);
check('payload 写入 private token=admin_token', str_contains($guardPayload, "\0GuardSettings\0token") && str_contains($guardPayload, 'admin_token'), $guardPayload);

require __DIR__ . '/fixtures/guard_demo.php';
ob_start();
$guardObject = @unserialize($guardPayload);
unset($guardObject);
gc_collect_cycles();
$guardOutput = (string) ob_get_clean();
check('守卫条件满足后 payload 真实执行', str_contains($guardOutput, 'GUARD_OK'), $guardOutput);

$guardCases = [
    'GuardEarlyEntry' => ['s:3:"ttl";i:1;', 'GUARD_EARLY_OK'],
    'GuardIssetEntry' => ['s:4:"flag";', 'GUARD_ISSET_OK'],
    'GuardArrayEntry' => ['s:3:"arr";a:0:{}', 'GUARD_ARRAY_OK'],
    'GuardNotEqualEntry' => ['debug_x', 'GUARD_NOT_OK'],
    'GuardTruthyEntry' => ['s:7:"enabled";s:1:"x";', 'GUARD_TRUTHY_OK'],
];
foreach ($guardCases as $entry => [$fragment, $marker]) {
    $built = (new PayloadBuilder($guard))->build($guardByEntry[$entry], 'echo ' . $marker);
    check($entry . ' 写入守卫要求', str_contains($built, $fragment), $built);
    ob_start();
    $object = @unserialize($built);
    unset($object);
    gc_collect_cycles();
    $output = (string) ob_get_clean();
    check($entry . ' payload 真实执行', str_contains($output, $marker), $output);
}

echo "== payload 编码输出 ==\n";
ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/private_collision_demo.php', '--encode=url']);
$urlEncoded = ob_get_clean();
check('--encode=url 输出 URL 编码后的 payload', str_contains($urlEncoded, '%00') && !str_contains($urlEncoded, "\0"), $urlEncoded);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/private_collision_demo.php', '--encode=base64']);
$base64Out = ob_get_clean();
$base64Line = trim((string) preg_replace('/^.*payload:\s*/s', '', $base64Out));
$decodedPayload = base64_decode($base64Line, true);
check('--encode=base64 可解回 payload', is_string($decodedPayload) && str_starts_with($decodedPayload, 'O:'), $base64Line);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/private_collision_demo.php', '--encode=nope']);
ob_end_clean();
check('非法 --encode 退出码为 2', $exit === 2, 'exit=' . $exit);

echo "== CFG 与插件接口 ==\n";
$irClasses = (new ProjectParser())->parsePath(__DIR__ . '/fixtures/guard_demo.php');
$earlyCfg = $irClasses['GuardEarlyEntry']->method('__destruct')->cfg;
check('方法生成了语句级 CFG', $earlyCfg !== null && count($earlyCfg->nodes()) >= 5, $earlyCfg === null ? 'null' : (string) count($earlyCfg->nodes()));

$cfgKinds = [];
$cfgLabels = [];
foreach ($earlyCfg?->nodes() ?? [] as $cfgNode) {
    $cfgKinds[] = $cfgNode->kind;
    foreach ($cfgNode->successors as $successor) {
        $cfgLabels[] = $successor['label'];
    }
}
check('CFG 含 condition / return 节点', in_array('condition', $cfgKinds, true) && in_array('return', $cfgKinds, true), implode(',', $cfgKinds));
check('条件节点带 true / false 边', in_array('true', $cfgLabels, true) && in_array('false', $cfgLabels, true), implode(',', array_map(static fn ($l) => (string) $l, $cfgLabels)));
check('CFG 可序列化成 JSON', json_decode((string) json_encode($earlyCfg->toArray()), true) !== null);

check('检测器注册表包含 deserialization', in_array('deserialization', DetectorRegistry::names(), true));
check('检测器注册表包含 dom-xss', in_array('dom-xss', DetectorRegistry::names(), true));
check('未知检测器返回 null', DetectorRegistry::create('does-not-exist') === null);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/dom_xss.js', '--type=dom-xss', '--no-color']);
$domOut = ob_get_clean();
check('dom-xss 检出 innerHTML 动态拼接', $exit === 0
    && str_contains($domOut, '(dom-xss/DOM_XSS)')
    && str_contains($domOut, 'innerHTML'), $domOut);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/dom_xss.js', '--type=dom-xss', '--json']);
$domJson = json_decode(ob_get_clean(), true);
check('dom-xss JSON 输出', is_array($domJson)
    && ($domJson['findings'][0]['type'] ?? '') === 'dom-xss'
    && ($domJson['findings'][0]['category'] ?? '') === 'DOM_XSS', json_encode($domJson));

$gadgetDir = __DIR__ . '/fixtures/gadget_only';
ob_start();
Application::run(['popchain', 'find', $gadgetDir, '--no-color']);
$gadgetGated = ob_get_clean();
check('目录 all 无污点 unserialize 时抑制 gadget 链', !str_contains($gadgetGated, 'GadgetOnly::__destruct')
    && str_contains($gadgetGated, 'suppressed'), $gadgetGated);

ob_start();
Application::run(['popchain', 'find', $gadgetDir, '--type=deserialization', '--no-color']);
$gadgetDeser = ob_get_clean();
check('--type=deserialization 保留 gadget 链', str_contains($gadgetDeser, 'GadgetOnly::__destruct'), $gadgetDeser);
check('--type=deserialization 不附加 gadget 备注', !str_contains($gadgetDeser, 'verify this gadget chain'), $gadgetDeser);

ob_start();
Application::run(['popchain', 'find', $gadgetDir, '--sym-entry=' . $gadgetDir . '/Chain.php', '--no-color']);
$gadgetEntry = ob_get_clean();
check('没有 unserialize 候选时仍收口 gadget 链', str_contains($gadgetEntry, 'suppressed')
    && !str_contains($gadgetEntry, 'GadgetOnly::__destruct'), $gadgetEntry);

ob_start();
Application::run(['popchain', 'find', $gadgetDir, '--no-gadget-gate', '--no-color']);
$gadgetNoGate = ob_get_clean();
check('--no-gadget-gate 关闭收口', str_contains($gadgetNoGate, 'GadgetOnly::__destruct'), $gadgetNoGate);

$gadgetInputDir = __DIR__ . '/fixtures/gadget_only_input';
ob_start();
Application::run(['popchain', 'find', $gadgetInputDir, '--no-color']);
$gadgetInput = ob_get_clean();
check('php://input 赋值视为 unserialize 候选', str_contains($gadgetInput, 'GadgetOnlyInput::__destruct')
    && str_contains($gadgetInput, 'verify this gadget chain'), $gadgetInput);

$gadgetCommentDir = __DIR__ . '/fixtures/gadget_only_commented';
ob_start();
Application::run(['popchain', 'find', $gadgetCommentDir, '--no-color']);
$gadgetComment = ob_get_clean();
check('注释里的 unserialize 不算候选', !str_contains($gadgetComment, 'GadgetOnlyCommented::__destruct')
    && str_contains($gadgetComment, 'suppressed'), $gadgetComment);

ob_start();
$exit = Application::run(['popchain', '--list-types']);
$typesOut = ob_get_clean();
check('--list-types 列出检测类型', $exit === 0 && str_contains($typesOut, 'deserialization'), $typesOut);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php', '--type=does-not-exist']);
ob_end_clean();
check('未知检测类型退出码为 2', $exit === 2, 'exit=' . $exit);

$engineCmd = 'php ' . escapeshellarg(__DIR__ . '/fixtures/fake_engine.php');
ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php', '--engine-cmd=' . $engineCmd]);
$engineOut = ob_get_clean();
check('引擎返回 sat 时赋值写进 payload', str_contains($engineOut, 'engine_marker'), $engineOut);
check('引擎备注显示在输出里', str_contains($engineOut, 'fake engine'), $engineOut);

$badEngine = 'php ' . escapeshellarg(__DIR__ . '/fixtures/fake_engine_bad.php');
ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php', '--engine-cmd=' . $badEngine]);
$badEngineOut = ob_get_clean();
check('引擎输出非法 JSON 时回退启发式', str_contains($badEngineOut, 'payload:'), $badEngineOut);

$unsatEngine = 'php ' . escapeshellarg(__DIR__ . '/fixtures/fake_engine_unsat.php');
ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php', '--engine-cmd=' . $unsatEngine]);
$unsatOut = ob_get_clean();
check('引擎判定 unsat 时跳过链', !str_contains($unsatOut, 'payload:') && $exit === 1, $unsatOut);

require __DIR__ . '/fixtures/InProcessEngine.php';
\PopChain\Engine\EngineRegistry::register('fake-inproc', \TestFixture\FakeSymbolicEngine::class);
check('引擎注册表包含自定义进程内引擎', in_array('fake-inproc', \PopChain\Engine\EngineRegistry::names(), true));

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php', '--engine=fake-inproc']);
$inprocOut = ob_get_clean();
check('进程内引擎的赋值写进 payload', str_contains($inprocOut, 'inproc_marker'), $inprocOut);
check('进程内引擎备注显示在输出里', str_contains($inprocOut, 'fake in-process engine'), $inprocOut);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php', '--engine=none']);
$noneOut = ob_get_clean();
check('--engine=none 时只走启发式', !str_contains($noneOut, 'inproc_marker'), $noneOut);

echo "== 符号执行检测器（合并的 SymPHP）==\n";
$symbolicTarget = __DIR__ . '/../examples/symbolic/vuln/rce.php';
ob_start();
$exit = Application::run(['popchain', 'find', $symbolicTarget, '--type=symbolic']);
$symbolicOut = ob_get_clean();
check('--type=symbolic 能报出命令注入', $exit === 0 && str_contains($symbolicOut, 'OS Command Injection'), $symbolicOut);

ob_start();
Application::run(['popchain', 'find', $symbolicTarget, '--type=symbolic', '--json']);
$symbolicJson = ob_get_clean();
$symbolicDecoded = json_decode($symbolicJson, true);
check('符号执行结果可 JSON 输出', is_array($symbolicDecoded)
    && is_array($symbolicDecoded['findings'] ?? null)
    && ($symbolicDecoded['findings'][0]['category'] ?? '') === 'RCE'
    && ($symbolicDecoded['findings'][0]['type'] ?? '') === 'symbolic'
    && ($symbolicDecoded['findings'][0]['severity'] ?? '') !== '', $symbolicJson);
check('符号执行文本使用统一格式', str_contains($symbolicOut, '(symbolic/RCE)'), $symbolicOut);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/symbolic/vuln/safe.php', '--type=symbolic']);
$safeOut = ob_get_clean();
check('安全样例不报漏洞', $exit === 1 && !str_contains($safeOut, 'OS Command Injection'), $safeOut);

ob_start();
Application::run(['popchain', '--list-types']);
$typesOut2 = ob_get_clean();
check('--list-types 包含 symbolic', str_contains($typesOut2, 'symbolic'), $typesOut2);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/symbolic/vuln']);
$defaultAllOut = ob_get_clean();
check('默认运行全部检测器', $exit === 0 && str_contains($defaultAllOut, '(symbolic/XSS)'), $defaultAllOut);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/../examples/symbolic/vuln', '--type=symbolic', '--sym-max-paths=1']);
$truncatedOut = ob_get_clean();
check('预算截断时打印告警', str_contains($truncatedOut, '[warn]') && str_contains($truncatedOut, 'truncated'), $truncatedOut);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/../examples/symbolic/vuln', '--type=symbolic', '--sym-max-paths=1', '--json']);
$truncatedJson = json_decode(ob_get_clean(), true);
check('预算截断写入 JSON warnings', is_array($truncatedJson)
    && ($truncatedJson['warnings'] ?? []) !== [], json_encode($truncatedJson));

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--max-chains=1']);
$maxChainsOut = ob_get_clean();
check('max-chains 真截断时告警', str_contains($maxChainsOut, '[warn]')
    && str_contains($maxChainsOut, 'max-chains'), $maxChainsOut);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/object_injection_only.php']);
$objectOnlyOut = ob_get_clean();
check('对象注入会标注没有可用 POP 链', $exit === 0
    && str_contains($objectOnlyOut, 'PHP Object Injection')
    && str_contains($objectOnlyOut, 'no POP chain'), $objectOnlyOut);
check('--list-types 列出 all', str_contains($typesOut2, 'all'), $typesOut2);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/dynamic_lab.php']);
$dynamicOut = ob_get_clean();
check('动态回调链能被识别', $exit === 0 && str_contains($dynamicOut, 'DynamicEngine::__wakeup -> DynamicSys::doExecute'), $dynamicOut);
check('动态方法名反推出属性值', str_contains($dynamicOut, 's:7:"execute"'), $dynamicOut);
check('形参数组约束写进 payload', str_contains($dynamicOut, 'a:2:{i:0;s:5:"admin";i:1;i:1001;}'), $dynamicOut);
check('常量 sink 标注可达性', str_contains($dynamicOut, "sink argument is fixed: 'cat /flag'"), $dynamicOut);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/guard_demo.php']);
$conflictOut = ob_get_clean();
check('内置引擎能剪掉矛盾守卫的链', !str_contains($conflictOut, 'GuardConflictEntry'), $conflictOut);
check('可满足的守卫链仍然保留', str_contains($conflictOut, 'GuardEntry'), $conflictOut);

echo "== CLI ==\n";
ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--class=Entry', '--pop-cmd=echo CLI']);
$cliOutput = ob_get_clean();
check('find --class=Entry 退出码为 0', $exit === 0, 'exit=' . $exit);
check('find --class=Entry 输出 2 条 payload', substr_count($cliOutput, 'payload:') === 2, '实际 ' . substr_count($cliOutput, 'payload:'));

$tempOutput = tempnam(sys_get_temp_dir(), 'popchain_test_');
ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--output=' . $tempOutput]);
ob_end_clean();
$written = $tempOutput === false ? '' : (string) file_get_contents($tempOutput);
$writtenLines = array_values(array_filter(explode("\n", trim($written))));
check('--output 把 2 条 payload 写入文件', count($writtenLines) === 2 && str_starts_with($writtenLines[0], 'O:'), '实际 ' . count($writtenLines));
if ($tempOutput !== false) {
    unlink($tempOutput);
}

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/fixtures/string_demo.php', '--entry=all']);
$allOutput = ob_get_clean();
check('--entry=all 能找到 __toString 入口链', $exit === 0 && str_contains($allOutput, '__toString'));

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--json']);
$jsonOutput = ob_get_clean();
$decoded = json_decode($jsonOutput, true);
check('--json 输出可解析且包含 2 条结果', is_array($decoded) && count($decoded['findings'] ?? []) === 2);
check('--json 带工具名与版本', ($decoded['tool'] ?? '') === 'php_pop_framework' && ($decoded['version'] ?? '') === Application::VERSION);
ob_start();
Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--color']);
$colorOut = ob_get_clean();
check('--color 强制输出 ANSI 转义', str_contains($colorOut, "["), $colorOut);

ob_start();
Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php']);
$plainOut = ob_get_clean();
check('非终端默认不输出 ANSI', !str_contains($plainOut, "["), $plainOut);

check('统一 JSON 字段齐全', isset(
    $decoded['findings'][0]['type'],
    $decoded['findings'][0]['severity'],
    $decoded['findings'][0]['category'],
    $decoded['findings'][0]['sink']['display'],
    $decoded['findings'][0]['payload'],
) && $decoded['findings'][0]['type'] === 'deserialization');

echo "== CLI 健壮性 ==\n";
ob_start();
$exit = Application::run(['popchain', 'version']);
$versionOutput = ob_get_clean();
check('version 输出版本号', $exit === 0 && str_contains($versionOutput, Application::VERSION), trim($versionOutput));

ob_start();
Application::run(['popchain']);
$helpOutput = ob_get_clean();
check('无参数时打印英文帮助', str_contains($helpOutput, 'Usage:'));

ob_start();
Application::run(['popchain', 'help', '--lang=zh']);
$helpZh = ob_get_clean();
check('--lang=zh 打印中文帮助', str_contains($helpZh, '用法:'));

ob_start();
$exit = Application::run(['popchain', 'help']);
ob_end_clean();
check('help 退出码为 0', $exit === 0);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--max-depth=0']);
ob_end_clean();
check('--max-depth 非法时退出码为 2', $exit === 2, 'exit=' . $exit);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/no_such_path_xyz']);
ob_end_clean();
check('路径不存在时退出码为 2', $exit === 2, 'exit=' . $exit);

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--class=NoSuchClass']);
$noChainOutput = ob_get_clean();
check('没有匹配链时退出码为 1', $exit === 1, 'exit=' . $exit);
check('没有匹配链时给出提示', str_contains($noChainOutput, 'relax entries'));

ob_start();
$exit = Application::run(['popchain', 'find', __DIR__ . '/../examples/demo.php', '--lang=zh']);
$zhOutput = ob_get_clean();
check('--lang=zh 中文输出', $exit === 0 && str_contains($zhOutput, '扫描:'));

ob_start();
$exit = Application::run(['popchain', 'nosuchcommand']);
$unknownOutput = ob_get_clean();
check('未知命令退出码为 2 并打印帮助', $exit === 2 && str_contains($unknownOutput, 'Usage:'), 'exit=' . $exit);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
