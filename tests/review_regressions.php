<?php

class Module
{
    public function log()
    {
    }
}

class Language
{
    public static function _($key)
    {
        return $key;
    }
}

class Loader
{
    public static function loadModels()
    {
    }
}

require dirname(__DIR__) . '/virtfusion_direct_provisioning_mod.php';

class FakeResourceServerApi
{
    public $resources = ['traffic' => 100, 'memory' => 1024, 'cpuCores' => 1];
    public $failCpu = true;
    public $calls = [];

    public function get($server_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => ['settings' => ['resources' => $this->resources]]
            ])
        ];
    }

    public function getPkg($package_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode(['data' => ['traffic' => 100]])
        ];
    }

    public function modifyPrimaryTraffic($server_id, array $vars)
    {
        $this->calls[] = 'traffic';
        $this->resources['traffic'] = (int) $vars['traffic'];
        return ['info' => ['http_code' => 201], 'response' => ''];
    }

    public function modifyMemory($server_id, $memory)
    {
        $this->calls[] = 'memory';
        $this->resources['memory'] = (int) $memory;
        return ['info' => ['http_code' => 201], 'response' => ''];
    }

    public function modifyCpuCores($server_id, $cores)
    {
        $this->calls[] = 'cpuCores';
        if ($this->failCpu) {
            return ['info' => ['http_code' => 500], 'response' => ''];
        }
        $this->resources['cpuCores'] = (int) $cores;
        return ['info' => ['http_code' => 201], 'response' => ''];
    }
}

class TestableVirtfusionModule extends VirtfusionDirectProvisioningMod
{
    public $state;
    public $serverApi;
    public $PackageOptions;

    protected function getServerApiFromRow($row)
    {
        return $this->serverApi;
    }

    protected function acquireOperationLock($scope, $module_row_id, $server_id)
    {
        return 'test-lock';
    }

    protected function releaseOperationLock($name)
    {
    }

    protected function serviceOperationState($service_id, $field)
    {
        return $this->state;
    }

    protected function persistServiceOperationState($service_id, $field, array $state)
    {
        $this->state = $state;
        return true;
    }
}

function callPrivate($object, $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    return $reflection->invokeArgs($object, $arguments);
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$module = (new ReflectionClass(VirtfusionDirectProvisioningMod::class))->newInstanceWithoutConstructor();

$package = (object) ['meta' => (object) []];
assertSameValue(true, callPrivate($module, 'shouldAutoBuild', [$package]), 'Auto build must default to enabled.');
$package->meta->{'virtfusion-auto_build'} = 'false';
assertSameValue(false, callPrivate($module, 'shouldAutoBuild', [$package]), 'Package setting must disable auto build.');
assertSameValue(
    true,
    callPrivate($module, 'shouldAutoBuild', [$package, ['virtfusion-auto_build' => 'true']]),
    'The configurable Auto build option must override legacy package metadata.'
);

$create = callPrivate($module, 'applyCreateConfigOptions', [
    ['packageId' => 10, 'userId' => 20, 'hypervisorId' => 30],
    ['virtfusion-port_speed' => '2500']
]);
assertSameValue(2500, $create['networkSpeedInbound'], 'Combined port speed must set inbound speed.');
assertSameValue(2500, $create['networkSpeedOutbound'], 'Combined port speed must set outbound speed.');

$blocks = [
    (object) ['id' => 1, 'month' => 5, 'traffic' => 100, 'added' => '2026-07-18T01:00:00Z'],
    (object) ['id' => 2, 'month' => 5, 'traffic' => 100, 'added' => '2026-07-18T02:00:00Z'],
    (object) ['id' => 3, 'month' => 5, 'traffic' => 200, 'added' => '2026-07-18T03:00:00Z']
];
$matches = callPrivate($module, 'findNewTrafficBlocks', [['1'], $blocks, 5, 100]);
assertSameValue(1, count($matches), 'Traffic Block reconciliation must exclude the saved before snapshot.');
assertSameValue(2, $matches[0]->id, 'Traffic Block reconciliation must identify the only new matching block.');

assertSameValue(
    true,
    $module->validateAdminServerUrlTemplate('https://{hostname}/admin/servers/{server_id}', 'vf.example.com'),
    'The default HTTPS admin URL must validate.'
);
assertSameValue(
    false,
    $module->validateAdminServerUrlTemplate('javascript:alert(1)', 'vf.example.com'),
    'A javascript admin URL must be rejected.'
);
assertSameValue(
    false,
    $module->validateAdminServerUrlTemplate('https://evil.example/servers/{server_id}', 'vf.example.com'),
    'A cross-host admin URL must be rejected.'
);

$service = (object) ['fields' => [
    (object) ['key' => 'virtfusion_server_id', 'value' => '42', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_ipv6_cidr', 'value' => '2001:db8::/64', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_resource_change_operation', 'value' => '{}', 'encrypted' => 0]
]];
$merged = callPrivate($module, 'mergedServiceMeta', [
    $service,
    ['virtfusion_restart_required' => 'true'],
    [],
    ['virtfusion_resource_change_operation']
]);
$merged = array_column($merged, 'value', 'key');
assertSameValue('42', $merged['virtfusion_server_id'], 'Service edits must preserve the server ID.');
assertSameValue('2001:db8::/64', $merged['virtfusion_ipv6_cidr'], 'Service edits must preserve unrelated metadata.');
assertSameValue(false, isset($merged['virtfusion_resource_change_operation']), 'A completed journal must be removed.');

$resource_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$resource_module->serverApi = new FakeResourceServerApi();
$row = (object) ['id' => 9, 'meta' => (object) ['hostname' => 'vf.example.com']];
$resource_service = (object) ['id' => 77];
$resource_fields = (object) ['virtfusion_server_id' => 42];
$resource_package = (object) ['meta' => (object) ['package_id' => 12]];
$first_change = callPrivate($resource_module, 'applyJournaledResourceOptions', [
    $row,
    $resource_service,
    $resource_fields,
    $resource_package,
    ['memory' => 2048, 'cpuCores' => 2]
]);
assertSameValue('partial_failure', $resource_module->state['status'], 'A failed later resource step must be journaled.');
assertSameValue(2048, $resource_module->state['actual']['memory'], 'The journal must record actual remote resources after failure.');
assertSameValue(['memory', 'cpuCores'], $resource_module->serverApi->calls, 'The first attempt must stop after the failed CPU step.');
assertSameValue(true, !empty($first_change['errors']['err_msg']), 'A partial resource change must report an error.');

$resource_module->serverApi->failCpu = false;
$second_change = callPrivate($resource_module, 'applyJournaledResourceOptions', [
    $row,
    $resource_service,
    $resource_fields,
    $resource_package,
    ['memory' => 2048, 'cpuCores' => 2]
]);
assertSameValue('completed', $resource_module->state['status'], 'Retrying the same resource targets must complete the journal.');
assertSameValue(['memory', 'cpuCores', 'cpuCores'], $resource_module->serverApi->calls, 'Retry must skip memory that already reached its target.');
assertSameValue('', $second_change['errors']['err_msg'], 'A resumed resource change must complete without errors.');

$resource_module->PackageOptions = new class {
    public function getByPackageId($package_id)
    {
        return [
            (object) ['id' => 10, 'name' => 'virtfusion-os_template'],
            (object) ['id' => 11, 'name' => 'virtfusion-vnc'],
            (object) ['id' => 12, 'name' => 'virtfusion-backup_plan_id']
        ];
    }
};
$hidden_options = callPrivate($resource_module, 'provisioningOptionVisibilityHtml', [1, true]);
assertSameValue(true, strpos($hidden_options, '10') !== false, 'No-auto-build forms must hide the OS option.');
assertSameValue(true, strpos($hidden_options, '11') !== false, 'No-auto-build forms must hide the VNC option.');
assertSameValue(false, strpos($hidden_options, '12') !== false, 'Backup plan must remain available without auto build.');

$no_build_vnc = callPrivate($resource_module, 'applyConfigurableServerOptions', [
    $row,
    (object) ['virtfusion_server_id' => 42, 'virtfusion_vnc' => 'false'],
    ['configoptions' => ['virtfusion-vnc' => 'true']]
]);
assertSameValue('', $no_build_vnc['errors']['err_msg'], 'Services must ignore VNC config without an API call.');

echo "review regressions: ok\n";
