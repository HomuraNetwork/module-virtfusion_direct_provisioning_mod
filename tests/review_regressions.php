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
        if ($key === 'VirtfusionDirectProvisioningMod.service_info.unlimited') {
            return 'Unlimited';
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

    public function getTemplates($server_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => [[
                    'name' => 'Ubuntu',
                    'templates' => [[
                        'id' => 49,
                        'name' => 'Ubuntu Server',
                        'version' => '24.04 LTS',
                        'variant' => 'Minimal'
                    ]]
                ]]
            ])
        ];
    }
}

class FakeLiveServerApi
{
    public $ipv6Enabled = true;

    public function get($server_id, $detailed = false)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => [
                    'built' => true,
                    'name' => 'Live server',
                    'hostname' => 'live.example.com',
                    'owner' => ['id' => 7],
                    'state' => 'running',
                    'tasks' => ['active' => false, 'actions' => ['pending' => []]],
                    'settings' => [
                        'resources' => [
                            'cpuCores' => 4,
                            'memory' => 6144,
                            'storage' => 120,
                            'traffic' => 2000
                        ],
                        'backupPlan' => 0
                    ],
                    'traffic' => [
                        'public' => [
                            'currentPeriod' => [
                                'limit' => 2500,
                                'end' => '2026-08-31T23:59:59+00:00'
                            ]
                        ]
                    ],
                    'network' => [
                        'interfaces' => [[
                            'mac' => '00:11:22:33:44:55',
                            'ipv4' => [[
                                'address' => '192.0.2.42',
                                'gateway' => '192.0.2.1'
                            ]],
                            'ipv6' => [[
                                'subnet' => '2001:db8:42::',
                                'cidr' => 64,
                                'enabled' => $this->ipv6Enabled,
                                'gateway' => '2001:db8:42::1'
                            ]],
                            'inAverage' => 1000,
                            'outAverage' => 1000
                        ]]
                    ],
                    'remoteState' => [
                        'running' => true,
                        'state' => 'running',
                        'cpu' => 9.8,
                        'memory' => [
                            'memtotal' => '419924',
                            'memfree' => '159096',
                            'memavailable' => '319428'
                        ],
                        'agent' => [
                            'fsinfo' => [[
                                'name' => 'vda3',
                                'mountpoint' => '/',
                                'total-bytes' => 9913355264,
                                'used-bytes' => 2701046784,
                                'type' => 'ext4'
                            ]]
                        ]
                    ]
                ]
            ])
        ];
    }

    public function getTraffic($server_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => [
                    'monthly' => [[
                        'start' => '2026-08-01T00:00:00+00:00',
                        'end' => '2026-08-31T23:59:59+00:00',
                        'rx' => 1847110337,
                        'tx' => 1270421,
                        'total' => 1848380758,
                        'limit' => 2000,
                        'blocks' => [['traffic' => 500]]
                    ]]
                ]
            ])
        ];
    }

    public function getTemplates($server_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => [[
                    'name' => 'Ubuntu',
                    'templates' => [
                        [
                            'id' => 49,
                            'name' => 'Ubuntu Server',
                            'version' => '24.04 LTS',
                            'variant' => 'Minimal'
                        ]
                    ]
                ]]
            ])
        ];
    }

    public function getUserSshKeys($user_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => [
                    [
                        'id' => 19,
                        'name' => 'Laptop',
                        'type' => 'OpenSSH',
                        'enabled' => true,
                        'publicKey' => 'ssh-ed25519 ' . base64_encode(str_repeat('e', 64)) . ' laptop@test'
                    ],
                    [
                        'id' => 20,
                        'name' => 'Key Without Enabled Flag',
                        'type' => 'OpenSSH',
                        'publicKey' => 'ssh-ed25519 ' . base64_encode(str_repeat('f', 64)) . ' second@test'
                    ]
                ]
            ])
        ];
    }
}

class FakeAllocationOnlyServerApi extends FakeLiveServerApi
{
    public function get($server_id, $detailed = false)
    {
        $request = parent::get($server_id, $detailed);
        $data = json_decode($request['response']);
        $data->data->remoteState = (object) [
            'running' => true,
            'state' => 'running'
        ];
        $request['response'] = json_encode($data);
        return $request;
    }
}

class FakeDom0TelemetryServerApi extends FakeLiveServerApi
{
    public function get($server_id, $detailed = false)
    {
        $request = parent::get($server_id, $detailed);
        $data = json_decode($request['response']);
        $data->data->remoteState->memory = (object) [
            'actual' => '524288',
            'unused' => '208012',
            'available' => '426856',
            'usable' => '319064',
            'disk_caches' => '100576',
            '_source' => 'dom0'
        ];
        $data->data->remoteState->agent = (object) [];
        $data->data->remoteState->disk = (object) [
            'vda' => (object) [
                'name' => 'vda',
                'allocation' => '1159135232',
                'capacity' => '10737418240',
                'physical' => '1159544832'
            ]
        ];
        $request['response'] = json_encode($data);
        return $request;
    }
}

class FakeBuildServerApi
{
    public $builds = [];
    public $createdSshKeys = [];
    public $buildResponseCode = 200;
    public $buildErrorResponse = ['errors' => ['ipv6' => ['IPv6 is unavailable.']]];
    public $queueStatus = [
        'serverId' => 42,
        'finished' => false,
        'failed' => false,
        'progress' => 35
    ];

    public function get($server_id, $detailed = false)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => ['tasks' => ['active' => false, 'actions' => ['pending' => []]]]
            ])
        ];
    }

    public function build($server_id, array $vars)
    {
        $this->builds[] = ['server_id' => $server_id, 'vars' => $vars];
        if ($this->buildResponseCode !== 200) {
            return [
                'info' => ['http_code' => $this->buildResponseCode],
                'response' => json_encode($this->buildErrorResponse)
            ];
        }
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => ['settings' => ['decryptedPassword' => 'new-build-password']]
            ])
        ];
    }

    public function createSshKey(array $vars)
    {
        $this->createdSshKeys[] = $vars;
        return [
            'info' => ['http_code' => 201],
            'response' => json_encode(['data' => ['id' => 88]])
        ];
    }

    public function resetPassword($server_id, array $vars)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode([
                'data' => ['queueId' => 7692, 'expectedPassword' => 'expected-password']
            ])
        ];
    }

    public function getQueue($queue_id)
    {
        return [
            'info' => ['http_code' => 200],
            'response' => json_encode(['data' => $this->queueStatus])
        ];
    }
}

class TestableVirtfusionModule extends VirtfusionDirectProvisioningMod
{
    public $state;
    public $serverApi;
    public $PackageOptions;
    public $Services;
    public $Input;
    public $Date;
    public $logs = [];

    public function log(...$arguments)
    {
        $this->logs[] = $arguments;
    }

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
assertSameValue(
    ['First error', 'Second error'],
    callPrivate($module, 'manageErrorMessages', [[
        'field' => ['first' => 'First error', 'duplicate' => 'First error'],
        'api' => (object) ['response' => 'Second error']
    ]]),
    'Manage JSON errors must be flattened, deduplicated, and free of internal field structure.'
);

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
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0], 'build']),
    'Unbuilt servers must allow a user-selected OS build.'
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
    false,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0, 'has_active_tasks' => true], 'build']),
    'An active VirtFusion task must block another OS build.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 0, 'has_active_tasks' => true], 'manage']),
    'An active VirtFusion build must still allow the control-panel handoff.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 1, 'has_active_tasks' => true], 'resetpass_status']),
    'An active VirtFusion task must not block read-only password queue polling.'
);
assertSameValue(
    true,
    callPrivate($module, 'serverAllowsAction', [(object) ['built' => 1, 'has_active_tasks' => true], 'vnc_disable']),
    'Closing VNC must remain available while another VirtFusion task is active.'
);
assertSameValue(false, callPrivate($module, 'taskStateIsActive', [false]), 'A false active-task state must remain idle.');
assertSameValue(false, callPrivate($module, 'taskStateIsActive', ['false']), 'A string false task state must remain idle.');
assertSameValue(
    false,
    callPrivate($module, 'taskStateIsActive', [(object) ['active' => false, 'lastOn' => '2026-08-18 09:26:50']]),
    'Task history metadata must not make an explicitly inactive task active.'
);
assertSameValue(
    false,
    callPrivate($module, 'taskStateIsActive', [(object) ['state' => 'completed', 'lastOn' => '2026-08-18 09:26:50']]),
    'A completed task state must release the Manage overlay.'
);
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
assertSameValue(49, $task_info->os_templates[0]->templates[0]->id, 'Unbuilt servers must load templates for manual installation.');
$live_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$live_module->serverApi = new FakeLiveServerApi();
$live_module->Date = new class {
    public function cast($value, $format)
    {
        return date($format, strtotime($value));
    }
};
$live_info = callPrivate($live_module, 'getRemoteServerInfo', [(object) [], 42]);
assertSameValue(4, $live_info->cpu, 'The resource panel must use the CPU allocation currently reported by VirtFusion.');
assertSameValue(6144, $live_info->memory, 'The resource panel must use the memory currently reported by VirtFusion.');
assertSameValue(120, $live_info->disk, 'The resource panel must use the disk currently reported by VirtFusion.');
assertSameValue(9.8, $live_info->resource_usage->cpu, 'CPU utilization must use the remoteState percentage.');
assertSameValue(23.9, $live_info->resource_usage->memory, 'Memory utilization must use guest available memory.');
assertSameValue(27.2, $live_info->resource_usage->disk, 'Disk utilization must use the guest root filesystem.');
assertSameValue(['192.0.2.42'], $live_info->network_addresses->ipv4, 'Manage must expose a flat remote IPv4 list.');
assertSameValue(['2001:db8:42::/64'], $live_info->network_addresses->ipv6_blocks, 'Manage must expose remote IPv6 blocks.');
assertSameValue(true, $live_info->ipv6_available, 'An assigned IPv6 block must be recognized as available.');
assertSameValue(true, $live_info->ipv6_enabled, 'An enabled IPv6 block must be recognized as active.');
$disabled_ipv6_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$disabled_ipv6_api = new FakeLiveServerApi();
$disabled_ipv6_api->ipv6Enabled = false;
$disabled_ipv6_module->serverApi = $disabled_ipv6_api;
$disabled_ipv6_info = callPrivate($disabled_ipv6_module, 'getRemoteServerInfo', [(object) [], 42]);
assertSameValue(true, $disabled_ipv6_info->ipv6_available, 'A disabled IPv6 allocation must remain available to enable.');
assertSameValue(false, $disabled_ipv6_info->ipv6_enabled, 'A disabled IPv6 allocation must not be displayed as active.');
assertSameValue([], $disabled_ipv6_info->network_addresses->ipv6_blocks, 'Disabled IPv6 blocks must not appear active.');
assertSameValue(
    ['2001:db8:42::/64'],
    $disabled_ipv6_info->network_addresses->ipv6_available_blocks,
    'The assigned IPv6 block must remain available to the enable workflow.'
);
$legacy_ipv6_package = (object) ['meta' => (object) []];
$disabled_ipv6_package = (object) ['meta' => (object) ['ipv6' => '0']];
$legacy_ipv6_service = (object) [];
$disabled_ipv6_service = (object) ['virtfusion_ipv6_available' => '0'];
assertSameValue(true, callPrivate($module, 'packageHasIpv6', [$legacy_ipv6_package]), 'Existing packages must default new services to IPv6 capable.');
assertSameValue(false, callPrivate($module, 'packageHasIpv6', [$disabled_ipv6_package]), 'A package must be able to create services without IPv6 capability.');
assertSameValue(true, callPrivate($module, 'serviceHasIpv6', [$legacy_ipv6_service]), 'Existing services must default to IPv6 capable.');
assertSameValue(false, callPrivate($module, 'serviceHasIpv6', [$disabled_ipv6_service]), 'A service must retain its own IPv6 capability.');
$live_info = callPrivate($live_module, 'applyIpv6ServiceCapability', [$legacy_ipv6_service, $live_info]);
$capable_disabled_ipv6_info = clone $disabled_ipv6_info;
$capable_disabled_ipv6_info = callPrivate($disabled_ipv6_module, 'applyIpv6ServiceCapability', [$legacy_ipv6_service, $capable_disabled_ipv6_info]);
$disabled_ipv6_info = callPrivate($disabled_ipv6_module, 'applyIpv6ServiceCapability', [$disabled_ipv6_service, $disabled_ipv6_info]);
assertSameValue('1.85 GB', $live_info->traffic_in_display, 'Inbound traffic must be formatted independently.');
assertSameValue('1.27 MB', $live_info->traffic_out_display, 'Outbound traffic must be formatted independently.');
assertSameValue('1.85 GB', $live_info->traffic_used_display, 'Total traffic must use the API total.');
assertSameValue(2500, $live_info->traffic_total, 'Traffic blocks must be included in the available traffic total.');
assertSameValue('Ubuntu', $live_info->os_templates[0]->name, 'OS templates must retain their VirtFusion group.');
assertSameValue(49, $live_info->os_templates[0]->templates[0]->id, 'OS templates must retain their VirtFusion ID.');
assertSameValue(7, $live_info->owner_id, 'The remote server owner must be retained for SSH key imports.');
assertSameValue('Laptop', $live_info->ssh_keys[0]->name, 'Enabled owner SSH keys must be available to the reinstall form.');
assertSameValue(
    'Key Without Enabled Flag',
    $live_info->ssh_keys[1]->name,
    'SSH keys without an enabled field must remain available unless explicitly disabled.'
);
assertSameValue(true, strpos($live_info->ssh_keys[0]->fingerprint, 'SHA256:') === 0, 'Owner SSH keys must expose a SHA256 fingerprint.');
assertSameValue(false, property_exists($live_info->ssh_keys[0], 'publicKey'), 'SSH public key material must not be exposed to the view.');
$light_live_info = callPrivate($live_module, 'getRemoteServerInfo', [(object) [], 42, false]);
assertSameValue([], $light_live_info->os_templates, 'State polling must not fetch or return the OS catalog.');
assertSameValue([], $light_live_info->ssh_keys, 'State polling must not fetch or return SSH keys.');
$manage_state = callPrivate($live_module, 'manageStatePayload', [$light_live_info]);
assertSameValue('4 vCPU', $manage_state['resources']['cpu']['value'], 'State JSON must provide presentation-ready resource values.');
assertSameValue(['192.0.2.42'], $manage_state['network']['ipv4'], 'State JSON must expose only normalized address strings.');
assertSameValue(false, array_key_exists('owner_id', $manage_state), 'State JSON must not expose internal VirtFusion ownership data.');
assertSameValue(false, array_key_exists('remoteState', $manage_state), 'State JSON must not expose the raw VirtFusion response.');
assertSameValue(
    'Ubuntu Server 24.04 LTS - Minimal',
    $live_info->os_templates[0]->templates[0]->label,
    'OS template labels must include the useful version and variant.'
);
assertSameValue(true, callPrivate($live_module, 'serverHasOsTemplate', [$live_info, 49]), 'Available OS template IDs must validate.');
assertSameValue(false, callPrivate($live_module, 'serverHasOsTemplate', [$live_info, 999]), 'Unknown OS template IDs must be rejected.');
assertSameValue(true, callPrivate($module, 'hostnameIsValid', ['server.example.com']), 'A normal FQDN must pass reinstall validation.');
assertSameValue(false, callPrivate($module, 'hostnameIsValid', ['invalid_hostname']), 'Invalid hostnames must not reach VirtFusion.');
assertSameValue(true, callPrivate($live_module, 'serverHasSshKeys', [$live_info, [19]]), 'The owner SSH key list must authorize a selected key.');
assertSameValue(false, callPrivate($live_module, 'serverHasSshKeys', [$live_info, [999]]), 'An SSH key outside the owner list must be rejected.');
assertSameValue(false, callPrivate($module, 'sshPublicKeyIsValid', ['-----BEGIN OPENSSH PRIVATE KEY-----']), 'Private key input must be rejected.');
assertSameValue('999 B', callPrivate($module, 'formatTrafficBytes', [999]), 'Small traffic values must retain byte units.');
assertSameValue('1.5 KB', callPrivate($module, 'formatTrafficBytes', [1500]), 'Traffic formatting must scale without false precision.');
assertSameValue('Unlimited', callPrivate($module, 'formatNetworkSpeed', [0]), 'A zero VirtFusion port speed must display as unlimited.');
$allocation_only_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$allocation_only_module->serverApi = new FakeAllocationOnlyServerApi();
$allocation_only_info = callPrivate($allocation_only_module, 'getRemoteServerInfo', [(object) [], 42]);
assertSameValue(null, $allocation_only_info->resource_usage->cpu, 'Missing CPU telemetry must not create a percentage.');
assertSameValue(null, $allocation_only_info->resource_usage->memory, 'Missing memory telemetry must not create a percentage.');
assertSameValue(null, $allocation_only_info->resource_usage->disk, 'Missing disk telemetry must not create a percentage.');
$dom0_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$dom0_module->serverApi = new FakeDom0TelemetryServerApi();
$dom0_info = callPrivate($dom0_module, 'getRemoteServerInfo', [(object) [], 42]);
assertSameValue(25.3, $dom0_info->resource_usage->memory, 'Dom0 available and usable memory must provide utilization.');
assertSameValue(10.8, $dom0_info->resource_usage->disk, 'Block allocation and capacity must provide disk utilization.');
$build_module = (new ReflectionClass(TestableVirtfusionModule::class))->newInstanceWithoutConstructor();
$build_api = new FakeBuildServerApi();
$build_module->Services = new class {
    public $fields = [];

    public function editField($service_id, array $field)
    {
        $this->fields[$field['key']] = $field;
    }

    public function errors()
    {
        return false;
    }
};
$build_module->Input = new class {
    public $errors = [];

    public function setErrors(array $errors)
    {
        $this->errors = $errors;
    }
};
$password_reset_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    ['action' => 'resetpass'],
    $live_info
]);
assertSameValue('password', $password_reset_result['type'], 'A password reset must return a sensitive result.');
assertSameValue('expected-password', $password_reset_result['password'], 'The expected reset password must be displayed once.');
assertSameValue(7692, $password_reset_result['queue_id'], 'The reset queue ID must be retained for status polling.');
$password_pending_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    ['action' => 'resetpass_status', 'queue_id' => '7692'],
    $live_info
]);
assertSameValue(
    ['type' => 'password_status', 'status' => 'pending', 'progress' => 35.0],
    $password_pending_result,
    'A running reset queue must expose only its normalized status and progress.'
);
$build_api->queueStatus['finished'] = true;
$build_api->queueStatus['progress'] = 100;
$password_complete_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    ['action' => 'resetpass_status', 'queue_id' => '7692'],
    $live_info
]);
assertSameValue('complete', $password_complete_result['status'], 'A finished reset queue must be reported as complete.');
$build_api->queueStatus['failed'] = true;
$password_failed_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    ['action' => 'resetpass_status', 'queue_id' => '7692'],
    $live_info
]);
assertSameValue('failed', $password_failed_result['status'], 'A failed reset queue must override completion.');
$build_api->queueStatus['serverId'] = 99;
$password_foreign_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    ['action' => 'resetpass_status', 'queue_id' => '7692'],
    $live_info
]);
assertSameValue('unknown', $password_foreign_result['status'], 'A queue belonging to another server must never be exposed.');
$build_api->queueStatus = ['serverId' => 42, 'finished' => false, 'failed' => false, 'progress' => 35];
$build_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    [
        'action' => 'build',
        'operating_system_id' => 49,
        'hostname' => 'new.example.com',
        'ipv6' => '0',
        'ssh_key_ids' => ['19']
    ],
    $live_info
]);
assertSameValue(
    'build',
    $build_result['type'],
    'A valid OS installation must return a persistent build progress result.'
);
assertSameValue('build', $build_result['action'], 'The build progress result must retain the requested action.');
assertSameValue('ssh_key', $build_result['auth_mode'], 'Selecting a key must use SSH key authentication.');
assertSameValue(null, $build_result['password'], 'SSH key installs must not expose the generated password.');
assertSameValue(
    [
        'operatingSystemId' => 49,
        'hostname' => 'new.example.com',
        'email' => false,
        'ipv6' => true,
        'sshKeys' => [19]
    ],
    $build_api->builds[0]['vars'],
    'Manual OS installation must submit the validated hostname and selected owner SSH keys.'
);
$valid_public_key = 'ssh-ed25519 ' . base64_encode(str_repeat('k', 64)) . ' imported@test';
$import_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'hostname' => 'reinstalled.example.com',
        'ssh_key_name' => 'Imported Key',
        'ssh_public_key' => $valid_public_key
    ],
    $live_info
]);
assertSameValue(
    'build',
    $import_result['type'],
    'A validated public key must be importable during reinstall.'
);
assertSameValue('ssh_key', $import_result['auth_mode'], 'Imported keys must retain SSH key authentication mode.');
assertSameValue(
    ['userId' => 7, 'name' => 'Imported Key', 'publicKey' => $valid_public_key],
    $build_api->createdSshKeys[0],
    'SSH key imports must target the remote server owner.'
);
assertSameValue([88], $build_api->builds[1]['vars']['sshKeys'], 'A newly imported SSH key must be applied immediately.');
$existing_public_key = 'ssh-ed25519 ' . base64_encode(str_repeat('e', 64)) . ' duplicate@test';
$duplicate_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'hostname' => 'duplicate.example.com',
        'ssh_key_name' => 'Duplicate Laptop Key',
        'ssh_public_key' => $existing_public_key
    ],
    $live_info
]);
assertSameValue('build', $duplicate_result['type'], 'Reusing an existing public key must still start reinstall.');
assertSameValue(1, count($build_api->createdSshKeys), 'Importing the same public key must not create a duplicate VirtFusion key.');
assertSameValue([19], $build_api->builds[2]['vars']['sshKeys'], 'A duplicate import must reuse the existing key ID.');
$password_build_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'hostname' => 'password.example.com',
        'password_login' => '1'
    ],
    $live_info
]);
assertSameValue('build', $password_build_result['type'], 'Password login must still return build progress.');
assertSameValue('password', $password_build_result['auth_mode'], 'Password login must be explicit in the build result.');
assertSameValue('new-build-password', $password_build_result['password'], 'Password login must display the generated password.');
assertSameValue(
    [
        'operatingSystemId' => 49,
        'hostname' => 'password.example.com',
        'email' => true,
        'ipv6' => true
    ],
    $build_api->builds[3]['vars'],
    'Password login must request an emailed credential without submitting SSH keys.'
);
$optional_hostname_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'hostname' => '',
        'password_login' => '1'
    ],
    $live_info
]);
assertSameValue('build', $optional_hostname_result['type'], 'Reinstall must allow an omitted hostname.');
assertSameValue(
    ['operatingSystemId' => 49, 'email' => true, 'ipv6' => true],
    $build_api->builds[4]['vars'],
    'An empty optional hostname must be omitted from the VirtFusion build request.'
);
$build_module->Input->errors = [];
$missing_auth_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'hostname' => 'missing-key.example.com'
    ],
    $live_info
]);
assertSameValue(null, $missing_auth_result, 'SSH key mode must reject reinstall without a selected or imported key.');
assertSameValue(
    true,
    isset($build_module->Input->errors['ssh_key_ids']['required']),
    'The missing SSH key error must identify every valid authentication choice.'
);
assertSameValue(5, count($build_api->builds), 'A missing authentication choice must not call the build API.');
$disabled_ipv6_build = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42, 'virtfusion_ipv6_available' => '1'],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'password_login' => '1'
    ],
    $capable_disabled_ipv6_info
]);
assertSameValue('build', $disabled_ipv6_build['type'], 'A client may rebuild an IPv6-capable service without enabling IPv6.');
assertSameValue(false, isset($build_api->builds[5]['vars']['ipv6']), 'An unchecked IPv6 option must be omitted from the build request.');
$enabled_ipv6_build = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42, 'virtfusion_ipv6_available' => '1'],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'password_login' => '1',
        'ipv6' => '1'
    ],
    $capable_disabled_ipv6_info
]);
assertSameValue('build', $enabled_ipv6_build['type'], 'A client must be able to enable IPv6 for a capable service.');
assertSameValue(true, $build_api->builds[6]['vars']['ipv6'], 'A checked IPv6 option must enable IPv6 during build.');
$forged_ipv6_build = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42, 'virtfusion_ipv6_available' => '0'],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'password_login' => '1',
        'ipv6' => '1'
    ],
    $disabled_ipv6_info
]);
assertSameValue('build', $forged_ipv6_build['type'], 'A forged client IPv6 field must not prevent an otherwise valid rebuild.');
assertSameValue(false, isset($build_api->builds[7]['vars']['ipv6']), 'A forged client IPv6 field must not bypass the saved service capability.');
$build_api->buildResponseCode = 422;
$build_module->Input->errors = [];
$ipv6_422_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42, 'virtfusion_ipv6_available' => '1'],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'password_login' => '1',
        'ipv6' => '1'
    ],
    $capable_disabled_ipv6_info
]);
assertSameValue(null, $ipv6_422_result, 'A VirtFusion validation failure must not report a successful rebuild.');
assertSameValue(
    'VirtfusionDirectProvisioningMod.!error.rebuild.ipv6_unavailable',
    $build_module->Input->errors['api']['response'] ?? null,
    'An IPv6-related 422 response must show a clear client-safe error instead of raw API data.'
);
$last_build_log = end($build_module->logs);
assertSameValue(
    true,
    strpos((string) ($last_build_log[1] ?? ''), 'HTTP 422; server=42; ipv6_requested=true; ipv6_available=true') !== false,
    'A 422 build response must log the server, client request, and service IPv6 capability.'
);
$build_api->buildErrorResponse = ['errors' => ['hostname' => ['The hostname is unavailable.']]];
$build_module->Input->errors = [];
$other_422_result = callPrivate($build_module, 'handleServerAction', [
    (object) ['meta' => (object) ['hostname' => 'vf.example.com']],
    $build_api,
    (object) ['id' => 77, 'client_id' => 5],
    (object) ['virtfusion_server_id' => 42, 'virtfusion_ipv6_available' => '1'],
    [
        'action' => 'rebuild',
        'operating_system_id' => 49,
        'password_login' => '1',
        'ipv6' => '1'
    ],
    $capable_disabled_ipv6_info
]);
assertSameValue(null, $other_422_result, 'A non-IPv6 422 response must still fail the rebuild.');
assertSameValue(
    'VirtFusion API returned HTTP 422.',
    $build_module->Input->errors['api']['response'] ?? null,
    'A non-IPv6 422 response must not be mislabeled as an IPv6 failure.'
);
$build_api->buildResponseCode = 200;
assertSameValue(
    true,
    $build_api->builds[0]['vars']['ipv6'],
    'A server with active IPv6 must keep it enabled even when the submitted build form omits IPv6.'
);
assertSameValue('built', $build_module->Services->fields['virtfusion_build_state']['value'], 'Manual installation must persist build state.');
assertSameValue(true, $build_module->Services->fields['virtfusion_password']['encrypted'], 'The new build password must stay encrypted.');
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
$server_package_rules = callPrivate($module, 'getPackageRules', [[
    'meta' => ['virtfusion-service_type' => 'server', 'ipv6' => '1']
]]);
assertSameValue(true, isset($server_package_rules['meta[ipv6]']), 'Server packages must validate the IPv6 service default.');

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
$server_overview_template = file_get_contents(__DIR__ . '/../views/default/server_overview.pdt');
$server_actions_template = file_get_contents(__DIR__ . '/../views/default/server_actions.pdt');
$server_more_features_template = file_get_contents(__DIR__ . '/../views/default/server_more_features.pdt');
$server_os_management_template = file_get_contents(__DIR__ . '/../views/default/server_os_management.pdt');
$action_confirm_template = file_get_contents(__DIR__ . '/../views/default/action_confirm.pdt');
$action_result_template = file_get_contents(__DIR__ . '/../views/default/action_result.pdt');
$manage_ajax_template = file_get_contents(__DIR__ . '/../views/default/manage_ajax.pdt');
$task_status_template = file_get_contents(__DIR__ . '/../views/default/task_status.pdt');
$os_install_template = file_get_contents(__DIR__ . '/../views/default/os_install.pdt');
$os_build_options_template = file_get_contents(__DIR__ . '/../views/default/os_build_options.pdt');
$network_template = file_get_contents(__DIR__ . '/../views/default/network_addresses.pdt');
$client_service_info_template = file_get_contents(__DIR__ . '/../views/default/client_service_info.pdt');
$api_source = file_get_contents(__DIR__ . '/../apis/virtfusion_api.php');
$server_api_source = file_get_contents(__DIR__ . '/../apis/commands/virtfusion_server.php');
assertSameValue(
    true,
    strpos($api_source, "(int) (\$info['http_code'] ?? 0) === 422") !== false
        && strpos($api_source, 'call_user_func($this->response_logger, $type, $command, $response)') !== false
        && strpos($module_source, "'| HTTP 422 '") !== false,
    'Every VirtFusion HTTP 422 response must write its complete raw JSON to the administrator module log.'
);
assertSameValue(
    true,
    strpos($module_source, "fieldSelect(\n            'meta[ipv6]'") !== false
        && strpos($module_source, "fieldSelect(\n            self::IPV6_AVAILABLE_FIELD") !== false
        && strpos($module_source, "isset(\$vars['staff_id']) && array_key_exists(self::IPV6_AVAILABLE_FIELD, \$vars)") !== false
        && strpos($module_source, "'key' => self::IPV6_AVAILABLE_FIELD") !== false
        && strpos($module_source, "fieldCheckbox(\n                'virtfusion_enable_ipv6'") !== false
        && strpos($module_source, "\$enable_ipv6 = \$virtfusion_has_ipv6") !== false
        && strpos($module_source, "\$configured === null || \$configured === ''") !== false
        && strpos($module_source, "isset(\$create_config_options['ipv6'])") === false
        && strpos($module_source, "in_array(\$name, ['vnc', 'ipv6'], true)") !== false,
    'IPv6 capability must be copied from a default-enabled package field into staff-managed service metadata while legacy Configurable Options remain hidden.'
);
assertSameValue(
    true,
    strpos($client_manage_template, '$server_unbuilt') !== false
        && strpos($server_more_features_template, 'value="manage"') !== false,
    'The client view must retain a control-panel handoff for an unbuilt server.'
);
assertSameValue(
    true,
    strpos($admin_manage_template, '$server_unbuilt') !== false
        && strpos($server_more_features_template, '$admin_server_url') !== false,
    'The admin view must retain a control-panel handoff for an unbuilt server.'
);
assertSameValue(
    true,
    strpos($client_manage_template, '$server_info->pending_tasks') !== false
        && strpos($admin_manage_template, '$server_info->pending_tasks') !== false,
    'Both Manage views must describe pending resource changes from VirtFusion task data.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'task_status.pdt') !== false
        && strpos($admin_manage_template, 'task_status.pdt') !== false,
    'Both Manage views must show active build status while retaining the control-panel handoff.'
);
assertSameValue(
    false,
    strpos($client_manage_template, 'virtfusion_restart_required') !== false
        || strpos($admin_manage_template, 'virtfusion_restart_required') !== false,
    'Restart banners must not read locally persisted state.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'server_overview.pdt') !== false
        && strpos($server_overview_template, 'traffic_total') !== false
        && strpos($server_overview_template, 'traffic_server') !== false
        && strpos($server_overview_template, "traffic_reset, 'M j, Y'") !== false,
    'The client traffic panel must show total and server traffic with a concise next-reset date.'
);
assertSameValue(
    true,
    strpos($admin_manage_template, 'server_overview.pdt') !== false
        && strpos($server_overview_template, 'traffic_total') !== false
        && strpos($server_overview_template, 'traffic_server') !== false
        && strpos($server_overview_template, "traffic_reset, 'M j, Y'") !== false,
    'The admin traffic panel must show total and server traffic with a concise next-reset date.'
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
assertSameValue(
    true,
    strpos($vnc_template, 'value="manage"') !== false
        && strpos($vnc_template, "['target' => '_blank']") !== false,
    'Opening VirtFusion from VNC must request a one-time login before opening a new window.'
);
assertSameValue(
    true,
    strpos($vnc_template, 'data-vf-build-progress') !== false
        && strpos($vnc_template, 'data-vf-build-refresh') !== false
        && strpos($vnc_template, 'vf-build-progress-bar') !== false
        && substr_count($vnc_template, 'data-vf-manage-overlay') >= 2
        && substr_count($vnc_template, 'data-vf-action-overlay') >= 2
        && strpos($manage_ajax_template, 'syncBuildProgress') !== false,
    'Password and OS installation results must use in-panel overlays with animated progress.'
);
assertSameValue(
    true,
    strpos($task_status_template, 'data-vf-task-status') !== false
        && strpos($task_status_template, 'data-vf-manage-overlay') !== false
        && strpos($task_status_template, 'vf-manage-overlay-panel') !== false
        && strpos($manage_ajax_template, "current.appendChild(modal)") !== false
        && strpos($manage_ajax_template, "document.body.appendChild(modal)") === false
        && strpos($manage_ajax_template, "modal.className = 'modal fade vf-working-modal'") === false
        && strpos($manage_ajax_template, 'isolation: isolate') === false
        && strpos($manage_ajax_template, 'z-index: 1040') === false
        && strpos($manage_ajax_template, '#tabManage .vf-manage-overlay') !== false,
    'Active tasks and slow action requests must block only the Manage panel instead of the whole page.'
);
assertSameValue(
    true,
    strpos($vnc_template, 'data-bs-dismiss="modal"') !== false
        && strpos($vnc_template, 'keyboard: true') !== false
        && strpos($vnc_template, 'hidden.bs.modal') !== false,
    'The VNC popup must allow dismissal and disable the remote console after closing.'
);
assertSameValue(
    true,
    strpos($server_overview_template, '$vf_resources') !== false
        && strpos($server_overview_template, 'data-resource-meter=') !== false
        && strpos($server_overview_template, 'resource_usage') !== false
        && strpos($server_overview_template, 'is_numeric($vf_usage)') !== false
        && strpos($server_overview_template, 'traffic_in_display') !== false
        && strpos($server_overview_template, 'traffic_out_display') !== false
        && strpos($server_overview_template, 'vf-traffic-totals') !== false
        && strpos($server_overview_template, 'vf-server-stat-heading') !== false
        && strpos($server_overview_template, 'network_in') === false
        && strpos($server_overview_template, 'network_out') === false
        && strpos($server_overview_template, 'col-lg-6 vf-server-overview-section') === false,
    'The shared overview must compact resource headings and stack them above split traffic without port speed.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'server_actions.pdt') === false
        && strpos($admin_manage_template, 'server_actions.pdt') === false
        && strpos($server_overview_template, 'server_actions.pdt') !== false
        && strpos($server_overview_template, 'value="refresh_ips"') !== false
        && strpos($client_manage_template, 'server_os_management.pdt') > strpos($client_manage_template, 'network_addresses.pdt')
        && strpos($client_manage_template, 'server_more_features.pdt') > strpos($client_manage_template, 'server_os_management.pdt')
        && strpos($server_actions_template, 'data-vf-action-row="power"') !== false
        && strpos($server_actions_template, 'data-vf-action-row="maintenance"') !== false
        && strpos($server_actions_template, 'value="resetpass"') !== false
        && strpos($server_actions_template, 'os_install.pdt') !== false
        && strpos($server_os_management_template, 'os_install.pdt') !== false
        && strpos($server_more_features_template, 'os_install.pdt') === false
        && strpos($server_more_features_template, 'value="manage"') !== false,
    'Power actions must stay in the overview while password reset and reinstall share a second action row.'
);
assertSameValue(
    true,
    strpos($server_actions_template, 'data-vf-confirm=') !== false
        && strpos($server_actions_template, 'onclick="return confirm') === false
        && strpos($action_confirm_template, "document.addEventListener('submit'") !== false
        && strpos($action_confirm_template, 'event.defaultPrevented') !== false
        && strpos($action_confirm_template, 'data-vf-confirm-submit') !== false,
    'Power and password actions must use the shared second-step confirmation modal.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'manage_ajax.pdt') !== false
        && strpos($admin_manage_template, 'manage_ajax.pdt') !== false
        && strpos($manage_ajax_template, "'X-Requested-With': 'XMLHttpRequest'") !== false
        && strpos($manage_ajax_template, "method: 'GET'") !== false
        && strpos($manage_ajax_template, 'data-vf-refresh-seconds') !== false
        && strpos($manage_ajax_template, 'data-vf-dirty') !== false
        && strpos($manage_ajax_template, "form.getAttribute('action')") !== false
        && strpos($manage_ajax_template, 'requestJson(form.action') === false
        && strpos($manage_ajax_template, 'data-vf-refresh-section') !== false
        && strpos($manage_ajax_template, 'current.replaceWith') === false
        && strpos($manage_ajax_template, "patchSection(current, incoming, 'feedback')") !== false
        && strpos($manage_ajax_template, 'event.defaultPrevented') !== false
        && strpos($manage_ajax_template, 'Date.now() % 5000') !== false
        && strpos($manage_ajax_template, "url.searchParams.set('vf_response', 'state')") !== false
        && strpos($manage_ajax_template, "formData.set('vf_response', 'action')") !== false
        && strpos($manage_ajax_template, "data.responseType === 'action'") !== false
        && strpos($manage_ajax_template, 'updateActionResponse(current, data)') !== false
        && strpos($manage_ajax_template, 'updateState(current, data.state)') !== false
        && strpos($manage_ajax_template, 'element.textContent =') !== false
        && strpos($manage_ajax_template, 'list.replaceChildren()') !== false
        && strpos($manage_ajax_template, 'releaseSubmittedModal') !== false
        && strpos($manage_ajax_template, "document.querySelectorAll('.modal-backdrop')") !== false
        && strpos($client_manage_template, 'data-vf-refresh-seconds=') !== false
        && strpos($admin_manage_template, 'data-vf-refresh-seconds=') !== false
        && strpos($client_manage_template, 'data-vf-refresh-section=') !== false
        && strpos($admin_manage_template, 'data-vf-refresh-section=') !== false,
    'Manage actions must use AJAX while timed refreshes update existing DOM values from filtered state JSON.'
);
assertSameValue(
    true,
    strpos($module_source, "['state', 'action', 'password_status']") !== false
        && strpos($module_source, "'responseType' => 'action'") !== false
        && strpos($module_source, 'renderManageActionResult') !== false
        && strpos($module_source, 'JSON_INVALID_UTF8_SUBSTITUTE') !== false,
    'Manage module methods must emit filtered JSON directly for state, actions, and queue status.'
);
assertSameValue(
    true,
    strpos($server_api_source, "'queue/' . (int) \$queueId") !== false
        && strpos($module_source, "case 'resetpass_status':") !== false
        && strpos($action_result_template, "fieldHidden('vf_response', 'password_status')") !== false
        && strpos($action_result_template, "disableData.set('vf_response', 'action')") !== false
        && strpos($action_result_template, "typeof data.status !== 'string'") !== false
        && strpos($action_result_template, 'holder.innerHTML = data.content') === false
        && strpos($action_result_template, 'window.setTimeout(checkStatus, 5000)') !== false
        && strpos($action_result_template, 'vf-password-control') !== false
        && strpos($action_result_template, "queue->errors") === false,
    'Password reset must poll its queue, present a compact copy control, and avoid exposing raw queue errors.'
);
assertSameValue(
    true,
    strpos($os_install_template, 'max-height: calc(100dvh - 2rem)') !== false
        && strpos($os_install_template, 'overflow-y: auto') !== false,
    'The reinstall modal must remain vertically scrollable within the viewport.'
);
assertSameValue(
    true,
    substr_count($module_source, "setMessage('silent', '')") === 2,
    'Module POST actions must suppress Blesta generic success messages.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'server_os_management.pdt') !== false
        && strpos($admin_manage_template, 'server_os_management.pdt') !== false
        && strpos($server_os_management_template, 'os_install.pdt') !== false
        && strpos($os_install_template, "'build'") !== false
        && strpos($os_install_template, "'rebuild'") !== false
        && strpos($os_install_template, 'data-vf-os-category') !== false
        && strpos($os_install_template, 'data-vf-os-template') !== false
        && strpos($os_install_template, 'confirm.reinstall') !== false
        && strpos($os_install_template, 'data-vf-confirm-danger="true"') !== false
        && strpos($os_install_template, 'data-vf-os-selection-status') !== false
        && strpos($os_install_template, 'select_os_first') !== false
        && strpos($os_install_template, "category.addEventListener('invalid'") !== false
        && strpos($os_install_template, "template.addEventListener('invalid'") !== false
        && strpos($os_install_template, 'submit.disabled') === false
        && strpos($os_install_template, '<div class="alert alert-danger">') === false
        && strpos($os_install_template, 'data-vf-os-open') !== false
        && strpos($os_install_template, 'hidden.bs.modal') !== false
        && strpos($os_install_template, 'onclick="return confirm') === false,
    'Both Manage views must expose two-level OS build controls and a modal reinstall flow.'
);
assertSameValue(
    true,
    strpos($server_api_source, "'/templates', 'GET'") !== false
        && strpos($server_api_source, "'ssh_keys/user/'") !== false
        && strpos($server_api_source, "'ssh_keys', 'POST'") !== false
        && strpos($module_source, "case 'build':") !== false
        && strpos($module_source, "case 'rebuild':") !== false
        && strpos($module_source, "'operatingSystemId' => (int) \$template_id") !== false
        && strpos($module_source, "if (\$hostname !== '')") !== false
        && strpos($module_source, "\$build_params['hostname'] = \$hostname") !== false
        && strpos($module_source, "\$build_params['sshKeys'] = \$ssh_key_ids") !== false
        && strpos($module_source, "\$build_params['email'] = \$password_login") !== false
        && strpos($module_source, "\$build_params['ipv6']") !== false,
    'OS reinstall must submit its template, optional hostname, selected owner keys, service-controlled IPv6, and password-login preference.'
);
assertSameValue(
    true,
    strpos($os_build_options_template, 'name="hostname"') !== false
        && strpos($os_build_options_template, 'name="ssh_key_ids[]"') !== false
        && strpos($os_build_options_template, 'name="ssh_public_key"') !== false
        && strpos($os_build_options_template, 'name="password_login"') !== false
        && strpos($os_build_options_template, 'data-vf-auth-mode="ssh" checked') !== false
        && strpos($os_build_options_template, 'data-vf-ssh-empty') !== false
        && strpos($os_build_options_template, 'data-vf-ssh-import-toggle') !== false
        && strpos($os_build_options_template, 'data-vf-ipv6-toggle') !== false
        && strpos($os_build_options_template, '$vf_ipv6_manageable') !== false
        && strpos($os_build_options_template, '.vf-ipv6-option .form-check-input { position: static;') !== false
        && strpos($os_build_options_template, 'class="vf-ipv6-control"') !== false
        && strpos($os_build_options_template, 'class="vf-ipv6-copy"') !== false
        && strpos($os_build_options_template, 'class="vf-network-options"') !== false
        && strpos($os_build_options_template, 'network_settings') !== false
        && strpos($os_build_options_template, 'checked disabled') !== false
        && strpos($os_build_options_template, 'publicKey') === false
        && strpos($os_build_options_template, '#<?php echo (int) $vf_ssh_key->id; ?>') === false
        && preg_match('/name="ssh_key_ids\[\]"[^>]*checked/s', $os_build_options_template) === 0
        && preg_match('/name="hostname"[^>]*required/s', $os_build_options_template) === 0
        && strpos($os_install_template, 'sshKeyRequired') !== false,
    'The OS popup must let clients enable service-capable IPv6 without disabling active IPv6 and retain explicit authentication without preselecting a saved key.'
);
assertSameValue(
    true,
    strpos($os_build_options_template, 'data-vf-password-auth-control') !== false
        && strpos($os_install_template, 'function clearSshAuthentication()') !== false
        && strpos($os_install_template, 'control.hidden = usePassword') !== false
        && strpos($os_install_template, 'checkbox.checked = false') !== false
        && strpos($os_install_template, 'importToggle.checked = false') !== false,
    'Password login must hide and clear SSH key controls while presenting its safety notice.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'network_addresses.pdt') !== false
        && strpos($admin_manage_template, 'network_addresses.pdt') !== false
        && strpos($network_template, "'ipv4'") !== false
        && strpos($network_template, "'ipv6_blocks'") !== false
        && strpos($network_template, 'ipv6_available_blocks') !== false
        && strpos($network_template, 'data-vf-ipv6-choice-open') !== false
        && strpos($network_template, '$vf_ipv6_manageable') !== false
        && strpos($manage_ajax_template, 'state.network.ipv6Manageable') !== false
        && strpos($network_template, 'virtfusionOsInstallers') !== false
        && strpos($network_template, 'port_speed_inbound') !== false
        && strpos($network_template, 'port_speed_outbound') !== false
        && strpos($network_template, "['main', 'base', 'extra', 'ipv6']") === false,
    'Manage network must display normalized IP lists and inbound/outbound port speed.'
);
assertSameValue(
    false,
    strpos($client_service_info_template, 'ip_data') !== false
        || strpos($client_service_info_template, 'virtfusion_primary_ipv4') !== false,
    'Stored network snapshots must not be rendered in the client service summary.'
);
assertSameValue(
    true,
    strpos($server_overview_template, 'backup_plan') === false
        && strpos($server_overview_template, 'latest_backup') === false
        && strpos($module_source, 'getBackups($server_id)') === false
        && strpos($server_api_source, 'function getBackups') === false,
    'The Manage overview must not display or request backup information.'
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
    strpos($client_manage_template, 'storage.ticket_required') === false
        && strpos($admin_manage_template, 'storage.ticket_required') === false
        && strpos($server_overview_template, 'vf-server-stat-warning') !== false
        && strpos($server_overview_template, 'storage.disk_mismatch') !== false
        && strpos($server_overview_template, 'storage.open_ticket') !== false,
    'Storage mismatch must appear inside the Disk resource block with the client ticket link.'
);
assertSameValue(
    true,
    strpos($client_manage_template, 'task_status.pdt') !== false
        && strpos($admin_manage_template, 'task_status.pdt') !== false
        && strpos($client_manage_template, '$server_busy ? 5 : 60') !== false
        && strpos($admin_manage_template, '$server_busy ? 5 : 60') !== false
        && strpos($client_manage_template, 'window.location.replace') === false
        && strpos($admin_manage_template, 'window.location.replace') === false,
    'Active VF tasks must use five-second AJAX polling without reloading the page.'
);

echo "review regressions: ok\n";
