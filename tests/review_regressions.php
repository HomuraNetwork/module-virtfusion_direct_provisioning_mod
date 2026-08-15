<?php

class Module
{
    public function log()
    {
    }

    public function serviceFieldsToObject(array $fields)
    {
        $values = new stdClass();
        foreach ($fields as $field) {
            $values->{$field->key} = $field->value;
        }
        return $values;
    }
}

class Language
{
    public static function _($key)
    {
        $arguments = func_get_args();
        if ($key === 'VirtfusionDirectProvisioningMod.service_name.traffic_block') {
            return ($arguments[2] ?? '') . ' Traffic Block';
        }
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
            'response' => json_encode(['data' => ['traffic' => 100, 'primaryStorage' => 150]])
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

class FakeTaskServerApi
{
    public function get($server_id, $detailed = false)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => [
                    'built' => false,
                    'name' => 'Building server',
                    'tasks' => [
                        'active' => ['action' => 'build_server'],
                        'actions' => [
                            'pending' => [[
                                'id' => 126,
                                'action' => 'Memory Update (2048MB => 1024MB)'
                            ]]
                        ]
                    ]
                ]
            ])
        ];
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
$module_reflection = new ReflectionClass(VirtfusionDirectProvisioningMod::class);
foreach (['TRAFFIC_BLOCK_OPERATION_FIELD', 'RESOURCE_CHANGE_OPERATION_FIELD'] as $constant_name) {
    assertSameValue(
        true,
        strlen($module_reflection->getReflectionConstant($constant_name)->getValue()) <= 32,
        $constant_name . ' must fit Blesta service_fields.key.'
    );
}
foreach (['PRIMARY_IPV4_FIELD', 'SECONDARY_IPV4_FIELD', 'BUILD_STATE_FIELD'] as $constant_name) {
    assertSameValue(
        true,
        strlen($module_reflection->getReflectionConstant($constant_name)->getValue()) <= 32,
        $constant_name . ' must fit Blesta service_fields.key.'
    );
}

$legacy_network_fields = (object) [
    'virtfusion_ip' => '192.0.2.10',
    'virtfusion-base_ips' => '192.0.2.11',
    'additional_num_ips' => '192.0.2.12,192.0.2.13',
    'virtfusion_ipv4_quantity' => '4',
    'virtfusion_build_status' => 'built'
];
$normalized_network_fields = callPrivate($module, 'normalizeLegacyServiceFields', [$legacy_network_fields]);
assertSameValue(
    '192.0.2.10',
    $normalized_network_fields->virtfusion_primary_ipv4,
    'The legacy main IPv4 field must migrate to the canonical primary field.'
);
assertSameValue(
    '192.0.2.11,192.0.2.12,192.0.2.13',
    $normalized_network_fields->virtfusion_secondary_ipv4,
    'Legacy base and additional IPv4 fields must merge without losing addresses.'
);
assertSameValue(
    'built',
    $normalized_network_fields->virtfusion_build_state,
    'The legacy build status must migrate to the canonical build state.'
);
$network_groups = callPrivate($module, 'ipv4AddressGroups', [
    $normalized_network_fields,
    (object) ['meta' => (object) ['default_ipv4' => 2]]
]);
assertSameValue(['192.0.2.10'], $network_groups['main'], 'The first IPv4 address must be primary.');
assertSameValue(['192.0.2.11'], $network_groups['base'], 'Package-provided secondary IPv4 must remain base.');
assertSameValue(
    ['192.0.2.12', '192.0.2.13'],
    $network_groups['extra'],
    'IPv4 addresses beyond the package default must be treated as extras.'
);
$legacy_service = (object) ['fields' => [
    (object) ['key' => 'virtfusion_server_id', 'value' => '42', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_ip', 'value' => '192.0.2.10', 'encrypted' => 0],
    (object) ['key' => 'virtfusion-base_ips', 'value' => '192.0.2.11', 'encrypted' => 0],
    (object) ['key' => 'additional_num_ips', 'value' => '192.0.2.12,192.0.2.13', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_ipv4_quantity', 'value' => '4', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_build_status', 'value' => 'built', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_password', 'value' => 'secret', 'encrypted' => 1]
]];
$canonical_meta = callPrivate($module, 'canonicalServiceMeta', [$legacy_service]);
$canonical_meta = array_column($canonical_meta, null, 'key');
assertSameValue(false, isset($canonical_meta['additional_num_ips']), 'Migration must remove the legacy extra-IP key.');
assertSameValue(false, isset($canonical_meta['virtfusion_ipv4_quantity']), 'Migration must remove the redundant IPv4 count.');
assertSameValue(false, isset($canonical_meta['virtfusion_build_status']), 'Migration must remove the legacy build-status key.');
assertSameValue(
    '192.0.2.10',
    $canonical_meta['virtfusion_primary_ipv4']['value'],
    'Migration must retain the primary IPv4 address.'
);
assertSameValue(
    '192.0.2.11,192.0.2.12,192.0.2.13',
    $canonical_meta['virtfusion_secondary_ipv4']['value'],
    'Migration must retain every secondary IPv4 address.'
);
assertSameValue(1, $canonical_meta['virtfusion_password']['encrypted'], 'Migration must preserve encryption metadata.');
$mod_service = (object) [
    'package' => (object) ['meta' => (object) ['default_ipv4' => 2]],
    'fields' => [
        (object) ['key' => 'virtfusion_server_id', 'value' => '42', 'encrypted' => 0],
        (object) ['key' => 'virtfusion_primary_ipv4', 'value' => '192.0.2.10', 'encrypted' => 0],
        (object) ['key' => 'virtfusion_secondary_ipv4', 'value' => '192.0.2.11,192.0.2.12', 'encrypted' => 0],
        (object) ['key' => 'virtfusion_build_state', 'value' => 'built', 'encrypted' => 0]
    ]
];
$official_meta = callPrivate($module, 'officialServiceMeta', [$mod_service]);
$official_meta = array_column($official_meta, null, 'key');
assertSameValue('192.0.2.10', $official_meta['virtfusion_ip']['value'], 'Official sync must restore main IPv4.');
assertSameValue('192.0.2.11', $official_meta['virtfusion-base_ips']['value'], 'Official sync must restore base IPv4.');
assertSameValue('192.0.2.12', $official_meta['additional_num_ips']['value'], 'Official sync must restore extra IPv4.');
assertSameValue(false, isset($official_meta['virtfusion_primary_ipv4']), 'Official sync must remove the mod IP key.');
assertSameValue('built', $official_meta['virtfusion_build_state']['value'], 'Official sync must preserve safe mod state for rollback.');

assertSameValue(false, callPrivate($module, 'shouldAutoBuild'), 'Auto build must use the safe disabled default.');
assertSameValue(
    true,
    callPrivate($module, 'shouldAutoBuild', [['autoBuild' => 'true']]),
    'The canonical autoBuild option must explicitly enable Auto Build.'
);
assertSameValue(
    false,
    callPrivate($module, 'shouldAutoBuild', [['virtfusion-auto_build' => 'true']]),
    'Legacy Auto Build option names must not be accepted.'
);
assertSameValue(
    false,
    callPrivate($module, 'tasksBlockServerAction', ['restart', [], [['action' => 'Memory Update']]]),
    'A pending resource task must not block the restart needed to apply it.'
);
assertSameValue(
    false,
    callPrivate($module, 'tasksBlockServerAction', ['poweroff', [], [['action' => 'Memory Update']]]),
    'A pending resource task must not block power off.'
);
assertSameValue(
    true,
    callPrivate($module, 'tasksBlockServerAction', ['vnc', [], [['action' => 'Memory Update']]]),
    'A pending resource task must continue to block non-power actions.'
);
assertSameValue(
    true,
    callPrivate($module, 'tasksBlockServerAction', ['restart', [['action' => 'Restart']], []]),
    'An actively executing task must block another power action.'
);
assertSameValue(
    false,
    callPrivate($module, 'tasksBlockServerAction', ['vnc_disable', [['action' => 'Restart']], []]),
    'Disabling VNC must remain available as a cleanup action.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0], 'manage']),
    'Unbuilt servers must retain the Manage Server action.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0], 'refresh_ips']),
    'Unbuilt or freshly-created servers must still allow IP refresh checks.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0], 'refresh_state']),
    'Unbuilt servers must still allow state refresh checks.'
);
assertSameValue(
    false,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0], 'vnc']),
    'Unbuilt servers must continue to block actions that require a built guest.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 1], 'vnc']),
    'Built servers must retain their normal actions.'
);
assertSameValue(
    false,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 1, 'has_active_tasks' => true], 'restart']),
    'An active VirtFusion build must block local server actions.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0, 'has_active_tasks' => true], 'manage']),
    'An active VirtFusion build must still allow the control-panel handoff.'
);
assertSameValue(false, callPrivate($module, 'taskStateIsActive', [false]), 'A false active-task state must remain idle.');
assertSameValue(true, callPrivate($module, 'taskStateIsActive', [(object) ['action' => 'build_server']]), 'An active task object must lock local actions.');
$task_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$task_module->serverApi = new FakeTaskServerApi();
$task_info = callPrivate($task_module, 'getRemoteServerInfo', [(object) [], 42]);
assertSameValue(true, $task_info->has_active_tasks, 'Active build state must be read before an unbuilt server returns.');
assertSameValue(
    ['Memory Update (2048MB => 1024MB)'],
    $task_info->pending_tasks,
    'Pending VirtFusion action text must identify the exact resource change.'
);
$server_package = (object) ['meta' => (object) ['virtfusion-service_type' => 'server']];
$add_rules = callPrivate($module, 'getServiceRules', [
    ['configoptions' => ['autoBuild' => 'true']],
    false,
    $server_package
]);
assertSameValue(true, isset($add_rules['virtfusion_hostname']), 'Auto Build creation must require a hostname.');
$edit_rules = callPrivate($module, 'getServiceRules', [[], true, null]);
assertSameValue(false, isset($edit_rules['virtfusion_hostname']), 'Service edits must not require a build hostname.');

$create = callPrivate($module, 'applyCreateConfigOptions', [
    ['packageId' => 10, 'userId' => 20, 'hypervisorId' => 30],
    ['networkSpeed' => '2500', 'ipv4' => '3']
]);
assertSameValue(2500, $create['networkSpeedInbound'], 'Combined port speed must set inbound speed.');
assertSameValue(2500, $create['networkSpeedOutbound'], 'Combined port speed must set outbound speed.');
assertSameValue(3, $create['ipv4'], 'The canonical ipv4 option must map directly to the create request.');

$human_speed = callPrivate($module, 'applyCreateConfigOptions', [
    ['packageId' => 10, 'userId' => 20, 'hypervisorId' => 30],
    ['networkSpeed' => '1 Gbps']
]);
assertSameValue(125000, $human_speed['networkSpeedInbound'], '1 Gbps must convert to API kB/s.');
assertSameValue('1 Gbps', callPrivate($module, 'formatNetworkSpeed', [125000]), 'API speed must display as Gbps.');
assertSameValue('100 Mbps', callPrivate($module, 'formatNetworkSpeed', [12500]), 'API speed must display as Mbps.');
assertSameValue('2 Gbps', callPrivate($module, 'formatNetworkSpeed', ['2Gbps']), 'Unit-based speed strings must normalize for display.');

$ipv4_package = (object) ['meta' => (object) ['default_ipv4' => 1]];
assertSameValue(
    3,
    callPrivate($module, 'getIpv4Quantity', [$ipv4_package, ['additionalIpv4' => 2]]),
    'additionalIpv4 must be added to the package default.'
);
assertSameValue(
    4,
    callPrivate($module, 'getIpv4Quantity', [$ipv4_package, ['ipv4' => 4, 'additionalIpv4' => 2]]),
    'The absolute ipv4 API field must take precedence over additionalIpv4.'
);

$traffic_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$traffic_package = (object) ['meta' => (object) [
    'virtfusion-service_type' => 'traffic_block',
    'traffic_block_gb' => '1000'
]];
assertSameValue(
    1000,
    callPrivate($traffic_module, 'getTrafficBlockAmount', [$traffic_package, []]),
    'A Traffic Block package must provision its fixed GB value without a Configurable Option.'
);
assertSameValue(
    1500,
    callPrivate($traffic_module, 'getTrafficBlockAmount', [$traffic_package, ['traffic' => '1500']]),
    'The traffic Configurable Option must override the package Traffic Block size.'
);
assertSameValue(
    2500,
    callPrivate($traffic_module, 'getTrafficBlockAmount', [
        $traffic_package,
        ['traffic' => '1500', 'addon_traffic' => '2500']
    ]),
    'The addon_traffic Configurable Option must take priority over traffic.'
);
assertSameValue(
    1500,
    callPrivate($traffic_module, 'getTrafficBlockAmount', [
        $traffic_package,
        ['addon_traffic' => null, 'traffic' => '1500']
    ]),
    'An empty addon_traffic value must fall through to traffic.'
);
assertSameValue(
    null,
    callPrivate($traffic_module, 'getTrafficBlockAmount', [$traffic_package, ['addon_traffic' => '0']]),
    'An explicitly submitted override must remain a positive whole number.'
);
assertSameValue(
    1000,
    callPrivate($traffic_module, 'getTrafficBlockAmount', [$traffic_package, ['customBlockSize' => '2500']]),
    'Arbitrary Configurable Option names must not override the package Traffic Block size.'
);
assertSameValue('999 GB', callPrivate($traffic_module, 'formatTrafficBlockSize', [999]), 'GB values must remain in GB.');
assertSameValue('1000 GB', callPrivate($traffic_module, 'formatTrafficBlockSize', [1000]), 'Values below 1024 GB must remain in GB.');
assertSameValue('1 TB', callPrivate($traffic_module, 'formatTrafficBlockSize', [1024]), '1024 GB must display as 1 TB.');
assertSameValue('1.5 TB', callPrivate($traffic_module, 'formatTrafficBlockSize', [1536]), 'TB display must retain useful binary-unit decimals.');
assertSameValue('10 TB', callPrivate($traffic_module, 'formatTrafficBlockSize', [10240]), '10240 GB must display as 10 TB.');
$traffic_rules = callPrivate($traffic_module, 'getPackageRules', [[
    'meta' => ['virtfusion-service_type' => 'traffic_block']
]]);
assertSameValue(true, isset($traffic_rules['meta[traffic_block_gb]']), 'Traffic Block packages must require a fixed GB size.');
assertSameValue(
    false,
    isset($traffic_rules['meta[traffic_block_option_id]']),
    'Traffic Block packages must use the standard traffic option names instead of a numeric option override.'
);

$public_label = callPrivate($module, 'publicServiceLabel');
assertSameValue(39, strlen($public_label), 'Public service labels must use a prefixed UUID.');
assertSameValue(
    true,
    (bool) preg_match('/^vf-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $public_label),
    'Public service labels must be UUIDv4 values.'
);
assertSameValue(
    '2026-08-19T23:59:59Z',
    callPrivate($module, 'serviceExtraUtcExpiry', ['2026-08-19 23:59:59']),
    'Timezone-free VirtFusion period ends must be explicitly normalized as UTC.'
);
assertSameValue(
    '2026-08-19T14:59:59Z',
    callPrivate($module, 'serviceExtraUtcExpiry', ['2026-08-19T23:59:59+09:00']),
    'Timezone-aware service expiry must preserve the exact instant.'
);
assertSameValue(
    null,
    callPrivate($module, 'serviceExtraUtcExpiry', ['invalid']),
    'Invalid module expiry must not be passed to the Service Extras plugin.'
);
$module_source = file_get_contents(dirname(__DIR__) . '/virtfusion_direct_provisioning_mod.php');
$language_source = file_get_contents(
    dirname(__DIR__) . '/language/en_us/virtfusion_direct_provisioning_mod.php'
);
$client_manage_template = file_get_contents(__DIR__ . '/../views/default/tabManage.pdt');
$admin_manage_template = file_get_contents(__DIR__ . '/../views/default/tabAdminManage.pdt');
assertSameValue(
    true,
    strpos($client_manage_template, 'if ($server_unbuilt)') !== false
        && strpos($client_manage_template, 'value="manage"') !== false,
    'The client view must reduce an unbuilt server to its Manage Server action.'
);
assertSameValue(
    true,
    strpos($admin_manage_template, 'if ($server_unbuilt)') !== false
        && strpos($admin_manage_template, '$admin_server_url') !== false,
    'The admin view must reduce an unbuilt server to its direct management action.'
);
assertSameValue(
    true,
    strpos($client_manage_template, '$server_info->pending_tasks') !== false
        && strpos($admin_manage_template, '$server_info->pending_tasks') !== false,
    'Both Manage views must describe pending resource changes from VirtFusion task data.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'if ($server_busy)') !== false
        && strpos($admin_manage_template, 'if ($server_busy)') !== false,
    'Both Manage views must overlay active builds while retaining the control-panel handoff.'
);
assertSameValue(
    false,
    strpos($client_manage_template, 'virtfusion_restart_required') !== false
        || strpos($admin_manage_template, 'virtfusion_restart_required') !== false,
    'Restart banners must not read locally persisted state.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'traffic_total') !== false
        && strpos($client_manage_template, 'traffic_server') !== false
        && strpos($client_manage_template, "traffic_reset, 'date'") !== false,
    'The client traffic panel must show total and server traffic with a date-only reset.'
);
assertSameValue(
    true,
    strpos($admin_manage_template, 'traffic_total') !== false
        && strpos($admin_manage_template, 'traffic_server') !== false
        && strpos($admin_manage_template, "traffic_reset, 'date'") !== false,
    'The admin traffic panel must show total and server traffic with a date-only reset.'
);
assertSameValue(
    true,
    strpos($language_source, 'Estimated traffic reset date: %1$s') !== false
        && strpos($language_source, 'valid until this reset date') !== false
        && strpos($language_source, 'shown above') === false
        && strpos($language_source, 'reservation API') === false,
    'Traffic Block preview guidance must include the estimated date without layout or API assumptions.'
);
assertSameValue(
    true,
    strpos($module_source, 'public function getServiceExtraDefinition') !== false,
    'The module must derive a Service Extra definition from the selected package Product Type.'
);
assertSameValue(
    true,
    strpos($module_source, 'public function getServiceExtraAvailability') !== false
        && strpos($module_source, "'available' => false") !== false
        && strpos($module_source, "'available' => true") !== false,
    'Traffic Block compatibility must be checked by the module during the purchase preview.'
);
assertSameValue(
    false,
    strpos($module_source, 'trafficBlocksEnabled') !== false
        || strpos($module_source, "'traffic_blocks_enabled'") !== false,
    'VirtFusion Server products must not require a separate Traffic Block module-row switch.'
);
assertSameValue(
    false,
    strpos($module_source, 'public function getServiceExtraCapabilities') !== false,
    'The module must not require an administrator-selected capability name.'
);
assertSameValue(
    true,
    strpos($module_source, 'public function previewServiceExtra') !== false,
    'The module must provide a Service Extras purchase preview.'
);
assertSameValue(
    true,
    strpos($module_source, "'_service_extra' => [") !== false
        && strpos($module_source, "'review_html' => '<div class=\"alert alert-info") !== false
        && strpos($module_source, "'VirtfusionDirectProvisioningMod.service_extra.period_heading'") !== false
        && strpos($module_source, '$reset_date') !== false
        && strpos($module_source, 'htmlspecialchars(') !== false,
    'Traffic Block previews must expose the end time and escaped markup containing its estimated date.'
);
assertSameValue(
    true,
    strpos($module_source, 'private function getServiceExtraParentReference(') !== false
        && strpos($module_source, "'parent_reference' => \$parent_reference") !== false
        && strpos($module_source, "\$data->data->uuid") !== false,
    'Traffic Block reviews must identify the parent server by its opaque VirtFusion UUID.'
);
assertSameValue(
    true,
    substr_count($module_source, "\$this->Date->cast(\$period['starts_at'], 'Y-m-d')") === 1
        && substr_count($module_source, "\$this->Date->cast(\$period['ends_at'], 'Y-m-d')") === 1,
    'Traffic Block preview period dates must not include midnight timestamps.'
);
$expiry_sync_position = strpos(
    $module_source,
    "syncTrafficBlockServiceEnd(\$service_id, \$period['ends_at'])"
);
$remote_submit_position = strpos($module_source, 'addTrafficBlock($server_id');
assertSameValue(
    true,
    $expiry_sync_position !== false
        && $remote_submit_position !== false
        && $expiry_sync_position < $remote_submit_position,
    'Paid Traffic Blocks must save the actual period end before submitting a remote change.'
);
$opaque_service = (object) ['fields' => [
    (object) ['key' => 'virtfusion_server_id', 'value' => '499']
]];
assertSameValue(
    'VirtfusionDirectProvisioningMod.service_name.server',
    $module->getServiceName($opaque_service),
    'Existing services without a public label must not expose the VirtFusion server ID.'
);
$labeled_service = (object) ['fields' => [
    (object) ['key' => 'virtfusion_server_id', 'value' => '499'],
    (object) ['key' => 'virtfusion_public_label', 'value' => $public_label]
]];
assertSameValue($public_label, $module->getServiceName($labeled_service), 'New services must use the opaque public label.');
$traffic_service = (object) ['fields' => [
    (object) ['key' => 'virtfusion_traffic_block_gb', 'value' => '10240']
]];
assertSameValue(
    '10 TB Traffic Block',
    $traffic_module->getServiceName($traffic_service),
    'Traffic Block service names must include the normalized purchased capacity.'
);

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
$vnc_row = (object) ['meta' => (object) ['hostname' => 'vf.example.com']];
assertSameValue(
    'wss://vf.example.com/vnc/?token=test-token',
    callPrivate($module, 'vncWebSocketUrl', [$vnc_row, '/vnc/?token=test-token']),
    'The VirtFusion VNC path must be converted to a WSS URL.'
);
assertSameValue(
    null,
    callPrivate($module, 'vncWebSocketUrl', [$vnc_row, 'wss://evil.example/vnc/?token=test-token']),
    'A VNC WebSocket URL on another host must be rejected.'
);
$vnc_template = file_get_contents(__DIR__ . '/../views/default/action_result.pdt');
foreach (['sendCtrlAltDel', 'scaleViewport', 'viewOnly', 'resizeSession', 'clipboardPasteFrom', 'requestFullscreen'] as $vnc_feature) {
    assertSameValue(
        true,
        strpos($vnc_template, $vnc_feature) !== false,
        'The embedded VNC console must retain its ' . $vnc_feature . ' control.'
    );
}
assertSameValue(
    true,
    strpos($vnc_template, "addEventListener('clipboard'") !== false,
    'The embedded VNC console must receive remote clipboard updates.'
);
assertSameValue(
    true,
    strpos($vnc_template, 'import(noVncModuleUrl)') !== false,
    'The VNC console must dynamically load noVNC from AJAX-rendered tab content.'
);
assertSameValue(
    true,
    strpos($vnc_template, "window.jQuery.fn.modal") !== false,
    'The VNC popup must support the Bootstrap 4 client theme.'
);
assertSameValue(
    true,
    strpos($vnc_template, "Modal.getOrCreateInstance") !== false,
    'The VNC popup must support the Bootstrap 5 administrator theme.'
);
assertSameValue(
    false,
    strpos($vnc_template, '<script type="module"') !== false,
    'The VNC initializer must not rely on module scripts executing after an AJAX tab replacement.'
);

$service = (object) ['fields' => [
    (object) ['key' => 'virtfusion_server_id', 'value' => '42', 'encrypted' => 0],
    (object) ['key' => 'virtfusion_ipv6_cidr', 'value' => '2001:db8::/64', 'encrypted' => 0],
    (object) ['key' => 'vf_resource_change_operation', 'value' => '{}', 'encrypted' => 0]
]];
$merged = callPrivate($module, 'mergedServiceMeta', [
    $service,
    ['virtfusion_public_label' => 'Test server'],
    [],
    ['vf_resource_change_operation']
]);
$merged = array_column($merged, 'value', 'key');
assertSameValue('42', $merged['virtfusion_server_id'], 'Service edits must preserve the server ID.');
assertSameValue('2001:db8::/64', $merged['virtfusion_ipv6_cidr'], 'Service edits must preserve unrelated metadata.');
assertSameValue(false, isset($merged['vf_resource_change_operation']), 'A completed journal must be removed.');

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
            (object) ['id' => 10, 'name' => 'operatingSystemId'],
            (object) ['id' => 11, 'name' => 'vnc'],
            (object) ['id' => 12, 'name' => 'backupPlanId'],
            (object) ['id' => 13, 'name' => 'autoBuild'],
            (object) ['id' => 14, 'name' => 'networkSpeed'],
            (object) ['id' => 15, 'name' => 'storage']
        ];
    }
};
$hidden_options = callPrivate($resource_module, 'provisioningOptionVisibilityHtml', [1, true]);
assertSameValue(true, strpos($hidden_options, '10') !== false, 'No-auto-build forms must hide the OS option.');
assertSameValue(true, strpos($hidden_options, '11') !== false, 'No-auto-build forms must hide the VNC option.');
assertSameValue(false, strpos($hidden_options, '12') !== false, 'Backup plan must remain available without auto build.');

$edit_hidden_options = callPrivate($resource_module, 'provisioningOptionVisibilityHtml', [1, true, true]);
assertSameValue(true, strpos($edit_hidden_options, '13') !== false, 'Service edits must hide autoBuild.');
assertSameValue(true, strpos($edit_hidden_options, '14') !== false, 'Service edits must hide create-only networkSpeed.');
assertSameValue(false, strpos($edit_hidden_options, '12') !== false, 'Service edits must keep backupPlanId available.');
assertSameValue(false, strpos($edit_hidden_options, '15') !== false, 'Service edits must keep the storage option editable.');

$no_build_vnc = callPrivate($resource_module, 'applyConfigurableServerOptions', [
    $row,
    (object) ['virtfusion_server_id' => 42, 'virtfusion_vnc' => 'false'],
    ['configoptions' => ['vnc' => 'true']]
]);
assertSameValue('', $no_build_vnc['errors']['err_msg'], 'Services must ignore VNC config without an API call.');

$storage_service = (object) ['options' => [
    (object) ['option_name' => 'storage', 'option_type' => 'quantity', 'qty' => 200]
]];
assertSameValue(
    200,
    callPrivate($resource_module, 'storageMismatch', [
        $row,
        $resource_package,
        $storage_service,
        (object) ['disk' => 100]
    ])->expected,
    'Configurable storage must take precedence over the package disk.'
);
assertSameValue(
    null,
    callPrivate($resource_module, 'storageMismatch', [
        $row,
        $resource_package,
        $storage_service,
        (object) ['disk' => 200]
    ]),
    'Matching service and VirtFusion storage must not flag a ticket.'
);
assertSameValue(
    null,
    callPrivate($resource_module, 'storageMismatch', [$row, $resource_package, $storage_service, null]),
    'A missing server snapshot must not flag a storage ticket.'
);
assertSameValue(
    null,
    callPrivate($resource_module, 'storageMismatch', [
        $row,
        $resource_package,
        $storage_service,
        (object) ['disk' => null]
    ]),
    'An unknown remote disk must not flag a storage ticket.'
);
$package_storage_mismatch = callPrivate($resource_module, 'storageMismatch', [
    $row,
    $resource_package,
    (object) ['options' => []],
    (object) ['disk' => 100]
]);
assertSameValue(150, $package_storage_mismatch->expected, 'The VF package disk must be used without a storage option.');
assertSameValue(100, $package_storage_mismatch->actual, 'The mismatch must include the actual VF disk.');
assertSameValue(
    true,
    callPrivate($resource_module, 'storageMismatch', [
        $row,
        $resource_package,
        (object) ['options' => []],
        (object) ['disk' => 200]
    ]) !== null,
    'Any package and VirtFusion disk mismatch must require manual adjustment.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'storage.ticket_required') !== false
        && strpos($admin_manage_template, 'storage.ticket_required') !== false
        && strpos($client_manage_template, 'alert alert-danger') !== false
        && strpos($client_manage_template, 'storage.open_ticket') !== false,
    'Manage views must show a red storage mismatch warning and client ticket link.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'vf-task-overlay') !== false
        && strpos($admin_manage_template, 'vf-task-overlay') !== false
        && strpos($client_manage_template, 'vf-task-refresh 5s') !== false
        && strpos($admin_manage_template, 'vf-task-refresh 5s') !== false,
    'Active VF tasks must use the five-second Manage-page waiting overlay.'
);

echo "review regressions: ok\n";
