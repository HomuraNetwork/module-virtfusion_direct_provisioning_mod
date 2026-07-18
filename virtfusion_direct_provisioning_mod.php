<?php

/**
 * VirtFusion Direct Provisioning Module
 *
 * @link https://docs.virtfusion.com/integrations/blesta VirtFusion
 */
class VirtfusionDirectProvisioningMod extends Module
{
    private const OFFICIAL_MODULE_CLASS = 'virtfusion_direct_provisioning';
    private const MOD_MODULE_CLASS = 'virtfusion_direct_provisioning_mod';
    private const AUTO_BUILD_OPTION = 'autoBuild';
    private const NETWORK_SPEED_OPTION = 'networkSpeed';
    private const ADDITIONAL_IPV4_OPTION = 'additionalIpv4';
    private const ADDITIONAL_TRAFFIC_OPTION = 'additionalTraffic';
    private const BACKUP_PLAN_OPTION = 'backupPlanId';
    private const CPU_THROTTLE_OPTION = 'cpuThrottle';
    private const TRAFFIC_BLOCK_CAPABILITY = 'traffic_block';
    private const TRAFFIC_BLOCK_AMOUNT_OPTION = 'amount';
    private const TRAFFIC_BLOCK_OPERATION_FIELD = 'vf_traffic_block_operation';
    private const RESOURCE_CHANGE_OPERATION_FIELD = 'vf_resource_change_operation';
    private const PRIMARY_IPV4_FIELD = 'virtfusion_primary_ipv4';
    private const SECONDARY_IPV4_FIELD = 'virtfusion_secondary_ipv4';
    private const BUILD_STATE_FIELD = 'virtfusion_build_state';
    private const LEGACY_NETWORK_FIELDS = [
        'virtfusion_ip',
        'virtfusion-base_ips',
        'additional_num_ips',
        'virtfusion_ipv4_quantity'
    ];

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load the language required by this module
        Language::loadLang('virtfusion_direct_provisioning_mod', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load components required by this module
        Loader::loadComponents($this, ['Input', 'Record']);

        // Load module config
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    private function getApi($api_token, $hostname, $allow_insecure_tls = false)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'virtfusion_api.php');

        return new VirtfusionApi($api_token, $hostname, 443, !$this->boolValue($allow_insecure_tls));
    }

    protected function getApiFromRow($row)
    {
        return $this->getApi(
            $row->meta->api_token,
            $row->meta->hostname,
            $row->meta->allow_insecure_tls ?? false
        );
    }

    protected function getServerApiFromRow($row)
    {
        $api = $this->getApiFromRow($row);
        $api->loadCommand('virtfusion_server');
        return new VirtfusionServer($api);
    }

    private function shouldAutoBuild(array $config_options = [])
    {
        return array_key_exists(self::AUTO_BUILD_OPTION, $config_options)
            && $config_options[self::AUTO_BUILD_OPTION] !== ''
            && $this->boolValue($config_options[self::AUTO_BUILD_OPTION]);
    }

    private function isTrafficBlockPackage($package)
    {
        return ($package->meta->{'virtfusion-service_type'} ?? 'server') === self::TRAFFIC_BLOCK_CAPABILITY;
    }

    private function trafficBlocksEnabled($row)
    {
        return $row && $this->boolValue($row->meta->traffic_blocks_enabled ?? false);
    }

    private function getTrafficBlockAmount(array $config_options = [])
    {
        $amount = $config_options[self::TRAFFIC_BLOCK_AMOUNT_OPTION] ?? null;
        return $amount !== null && $amount !== '' && $this->validateOptionalPositiveInteger($amount)
            ? (int) $amount
            : null;
    }

    private function getIpv4Quantity($package, array $config_options = [])
    {
        $default = (int) $package->meta->default_ipv4;
        if (isset($config_options['ipv4']) && $config_options['ipv4'] !== '') {
            return (int) $config_options['ipv4'];
        }
        if (isset($config_options[self::ADDITIONAL_IPV4_OPTION])
            && $config_options[self::ADDITIONAL_IPV4_OPTION] !== '') {
            return $default + (int) $config_options[self::ADDITIONAL_IPV4_OPTION];
        }

        return $default;
    }

    private function getPackagePricing($package, $pricing_id)
    {
        foreach (($package->pricing ?? []) as $pricing) {
            if ((int) ($pricing->id ?? 0) === (int) $pricing_id) {
                return $pricing;
            }
        }

        return null;
    }

    private function boolValue($value)
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function parseNetworkSpeed($value)
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return (int) $value;
        }

        if (!is_string($value)
            || !preg_match('/^\s*(\d+(?:\.\d+)?)\s*(Mbps|Gbps)\s*$/i', $value, $matches)) {
            return null;
        }

        $amount = (float) $matches[1];
        $kilobytes_per_second = strcasecmp($matches[2], 'Gbps') === 0
            ? ($amount * 1000000 / 8)
            : ($amount * 1000 / 8);

        return (int) round($kilobytes_per_second);
    }

    private function formatNetworkSpeed($value)
    {
        if (!is_numeric($value)) {
            $value = $this->parseNetworkSpeed($value);
            if ($value === null) {
                return null;
            }
        }

        $megabits = ((float) $value * 8) / 1000;
        $unit = 'Mbps';
        $display = $megabits;
        if ($megabits >= 1000) {
            $display = $megabits / 1000;
            $unit = 'Gbps';
        }

        $precision = $display >= 100 ? 0 : ($display >= 10 ? 1 : 2);
        $formatted = number_format($display, $precision, '.', '');
        if ($precision > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted . ' ' . $unit;
    }

    private function uuidV4()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    private function publicServiceLabel($prefix = 'vf')
    {
        return $prefix . '-' . $this->uuidV4();
    }

    private function csvInts($value)
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value)));
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $value))));
    }

    private function applyCreateConfigOptions(array $request_params, array $config_options)
    {
        $integer_fields = [
            'hypervisorId',
            'ipv4',
            'storage',
            'traffic',
            'memory',
            'cpuCores',
            'storageProfile',
            'networkProfile',
            'additionalStorage1Profile',
            'additionalStorage2Profile',
            'additionalStorage1Capacity',
            'additionalStorage2Capacity'
        ];
        $boolean_fields = ['additionalStorage1Enable', 'additionalStorage2Enable'];
        $array_fields = ['firewallRulesets', 'hypervisorAssetGroups'];

        foreach ($integer_fields as $field) {
            if (isset($config_options[$field]) && $config_options[$field] !== ''
                && is_numeric($config_options[$field])) {
                $request_params[$field] = (int) $config_options[$field];
            }
        }

        foreach ($boolean_fields as $field) {
            if (isset($config_options[$field]) && $config_options[$field] !== '') {
                $request_params[$field] = $this->boolValue($config_options[$field]);
            }
        }

        foreach ($array_fields as $field) {
            if (isset($config_options[$field]) && $config_options[$field] !== '') {
                $request_params[$field] = $this->csvInts($config_options[$field]);
            }
        }

        foreach (['networkSpeedInbound', 'networkSpeedOutbound'] as $field) {
            if (isset($config_options[$field]) && $config_options[$field] !== '') {
                $network_speed = $this->parseNetworkSpeed($config_options[$field]);
                if ($network_speed !== null && $network_speed >= 0) {
                    $request_params[$field] = $network_speed;
                }
            }
        }

        if (isset($config_options[self::NETWORK_SPEED_OPTION])
            && $config_options[self::NETWORK_SPEED_OPTION] !== '') {
            $network_speed = $this->parseNetworkSpeed($config_options[self::NETWORK_SPEED_OPTION]);
            if ($network_speed !== null && $network_speed >= 0) {
                $request_params['networkSpeedInbound'] = $network_speed;
                $request_params['networkSpeedOutbound'] = $network_speed;
            }
        }

        return $request_params;
    }

    private function normalizeLegacyServiceFields($service_fields)
    {
        if (!isset($service_fields->virtfusion_server_id) && isset($service_fields->server_id)) {
            $service_fields->virtfusion_server_id = $service_fields->server_id;
        }

        if (!isset($service_fields->{self::PRIMARY_IPV4_FIELD})) {
            $legacy_addresses = $this->csvValues($service_fields->virtfusion_ip ?? null);
            if (!empty($legacy_addresses)) {
                $service_fields->{self::PRIMARY_IPV4_FIELD} = $legacy_addresses[0];
            }
        }

        if (!isset($service_fields->{self::SECONDARY_IPV4_FIELD})) {
            $secondary_addresses = array_merge(
                $this->csvValues($service_fields->{'virtfusion-base_ips'} ?? null),
                $this->csvValues($service_fields->additional_num_ips ?? null)
            );
            if (!empty($secondary_addresses)) {
                $service_fields->{self::SECONDARY_IPV4_FIELD} = implode(',', array_values(array_unique($secondary_addresses)));
            }
        }

        if (!isset($service_fields->{self::BUILD_STATE_FIELD})
            && isset($service_fields->virtfusion_build_status)) {
            $service_fields->{self::BUILD_STATE_FIELD} = $service_fields->virtfusion_build_status;
        }

        return $service_fields;
    }

    private function csvValues($value)
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map('trim', $values), function ($item) {
            return $item !== '';
        }));
    }

    private function ipv4Addresses($service_fields)
    {
        $service_fields = $this->normalizeLegacyServiceFields($service_fields);
        return array_values(array_unique(array_merge(
            $this->csvValues($service_fields->{self::PRIMARY_IPV4_FIELD} ?? null),
            $this->csvValues($service_fields->{self::SECONDARY_IPV4_FIELD} ?? null)
        )));
    }

    private function ipv4AddressGroups($service_fields, $package)
    {
        $addresses = $this->ipv4Addresses($service_fields);
        $base_quantity = max(1, (int) ($package->meta->default_ipv4 ?? 1));

        return [
            'all' => $addresses,
            'main' => array_slice($addresses, 0, 1),
            'base' => array_slice($addresses, 1, max(0, $base_quantity - 1)),
            'extra' => array_slice($addresses, $base_quantity)
        ];
    }

    private function canonicalServiceMeta($service)
    {
        $legacy_keys = array_merge(self::LEGACY_NETWORK_FIELDS, ['virtfusion_build_status']);
        $has_legacy_fields = false;
        foreach (($service->fields ?? []) as $field) {
            if (in_array($field->key ?? null, $legacy_keys, true)) {
                $has_legacy_fields = true;
                break;
            }
        }

        if (!$has_legacy_fields) {
            return null;
        }

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields ?? []));
        $overrides = [];
        foreach ([self::PRIMARY_IPV4_FIELD, self::SECONDARY_IPV4_FIELD, self::BUILD_STATE_FIELD] as $field) {
            if (isset($service_fields->{$field})) {
                $overrides[$field] = $service_fields->{$field};
            }
        }

        return $this->mergedServiceMeta($service, $overrides, [], $legacy_keys);
    }

    private function officialServiceMeta($service)
    {
        if (isset($service->package) && $this->isTrafficBlockPackage($service->package)) {
            return $this->mergedServiceMeta($service, []);
        }

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields ?? []));
        $package = $service->package ?? (object) ['meta' => (object) ['default_ipv4' => 1]];
        $address_groups = $this->ipv4AddressGroups($service_fields, $package);
        $overrides = [
            'virtfusion_ip' => $address_groups['main'][0] ?? '',
            'virtfusion-base_ips' => implode(',', $address_groups['base']),
            'additional_num_ips' => implode(',', $address_groups['extra'])
        ];

        return $this->mergedServiceMeta(
            $service,
            $overrides,
            [],
            [
                self::PRIMARY_IPV4_FIELD,
                self::SECONDARY_IPV4_FIELD,
                'virtfusion_build_status',
                'virtfusion_ipv4_quantity'
            ]
        );
    }

    private function apiRequestSucceeded($request, array $status_codes)
    {
        return isset($request['info']['http_code'])
            && in_array((int) $request['info']['http_code'], $status_codes, true);
    }

    private function apiErrorMessage($request)
    {
        if (!empty($request['error'])) {
            return 'VirtFusion API connection failed: ' . $request['error'];
        }

        $status_code = (int) ($request['info']['http_code'] ?? 0);
        return $status_code > 0
            ? 'VirtFusion API returned HTTP ' . $status_code . '.'
            : 'VirtFusion API did not return a response.';
    }

    protected function serviceOperationState($service_id, $field)
    {
        if ((int) $service_id < 1) {
            return null;
        }

        Loader::loadModels($this, ['Services']);
        $service = $this->Services->get((int) $service_id);
        foreach (($service->fields ?? []) as $service_field) {
            if (($service_field->key ?? null) !== $field) {
                continue;
            }

            $state = json_decode((string) $service_field->value, true);
            return is_array($state) ? $state : null;
        }

        return null;
    }

    protected function persistServiceOperationState($service_id, $field, array $state)
    {
        if ((int) $service_id < 1) {
            return false;
        }

        Loader::loadModels($this, ['Services']);
        $this->Services->editField((int) $service_id, [
            'key' => $field,
            'value' => json_encode($state),
            'encrypted' => false
        ]);

        if ($this->Services->errors()) {
            $this->Input->setErrors([
                'operation' => [
                    'persist' => Language::_('VirtfusionDirectProvisioningMod.!error.operation.persist', true)
                ]
            ]);
            return false;
        }

        return true;
    }

    protected function acquireOperationLock($scope, $module_row_id, $server_id)
    {
        $name = substr('vf_mod_' . $scope . '_' . (int) $module_row_id . '_' . (int) $server_id, 0, 64);
        $statement = $this->Record->query('SELECT GET_LOCK(?, 10) AS acquired', [$name]);
        $result = $statement ? $statement->fetch() : null;

        return !empty($result->acquired) ? $name : null;
    }

    protected function releaseOperationLock($name)
    {
        if ($name) {
            try {
                $this->Record->query('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable $e) {
                // The DB connection also releases named locks when the request ends.
            }
        }
    }

    private function setModuleActionException($row, $action, \Throwable $exception)
    {
        $this->log(
            ($row->meta->hostname ?? 'VirtFusion') . '| ' . $action,
            $exception->getMessage(),
            'output',
            false
        );
        $this->Input->setErrors([
            'api' => [
                'response' => Language::_('VirtfusionDirectProvisioningMod.!error.api.action', true)
            ]
        ]);
    }

    private function getTrafficBlockPeriod($server_api, $server_id)
    {
        $request = $server_api->getTrafficBlocks($server_id);
        $this->log(
            $this->getModuleRow()->meta->hostname . '| traffic blocks availability',
            serialize($request),
            'output',
            $this->apiRequestSucceeded($request, [200])
        );

        if (!$this->apiRequestSucceeded($request, [200])) {
            $this->Input->setErrors([
                'api' => ['response' => $this->apiErrorMessage($request)]
            ]);
            return null;
        }

        $data = json_decode($request['response']);
        $period = $data->data->available->current ?? null;
        if (!$period || !isset($period->month, $period->start, $period->end)) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'period' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.period', true)
                ]
            ]);
            return null;
        }

        return [
            'month' => (int) $period->month,
            'starts_at' => (string) $period->start,
            'ends_at' => (string) $period->end,
            'assigned' => (array) ($data->data->assigned ?? [])
        ];
    }

    private function trafficBlockServiceMeta(
        $server_id,
        $amount,
        $month = null,
        $starts_at = null,
        $ends_at = null,
        $block_id = null,
        ?array $operation = null
    ) {
        $values = [
            'virtfusion_addon_capability' => self::TRAFFIC_BLOCK_CAPABILITY,
            'virtfusion_parent_server_id' => (int) $server_id,
            'virtfusion_traffic_block_gb' => (int) $amount,
            'virtfusion_traffic_block_month' => $month,
            'virtfusion_traffic_block_start' => $starts_at,
            'virtfusion_traffic_block_end' => $ends_at,
            'virtfusion_traffic_block_id' => $block_id,
            self::TRAFFIC_BLOCK_OPERATION_FIELD => $operation === null ? null : json_encode($operation)
        ];
        $meta = [];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $meta[] = ['key' => $key, 'value' => $value, 'encrypted' => 0];
            }
        }

        return $meta;
    }

    private function findNewTrafficBlocks(array $before_ids, array $after, $month, $amount)
    {
        $before_ids = array_fill_keys(array_map('strval', $before_ids), true);

        $matches = [];
        foreach ($after as $block) {
            if (!isset($block->id)
                || isset($before_ids[(string) $block->id])
                || (int) ($block->month ?? 0) !== (int) $month
                || (int) ($block->traffic ?? 0) !== (int) $amount) {
                continue;
            }
            $matches[] = $block;
        }
        usort($matches, function ($left, $right) {
            return strtotime($right->added ?? '') <=> strtotime($left->added ?? '');
        });

        return $matches;
    }

    private function trafficBlockIds(array $blocks)
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (isset($block->id)) {
                $ids[] = (string) $block->id;
            }
        }

        return $ids;
    }

    private function completedTrafficBlockMeta(array $operation, $block_id = null)
    {
        $operation['status'] = 'completed';
        $operation['block_id'] = $block_id;
        $operation['updated_at'] = date('c');

        return $this->trafficBlockServiceMeta(
            $operation['server_id'],
            $operation['amount'],
            $operation['month'],
            $operation['starts_at'],
            $operation['ends_at'],
            $block_id,
            $operation
        );
    }

    private function provisionTrafficBlock($package, array $vars, $parent_service)
    {
        $row = $this->getModuleRow();
        $amount = $this->getTrafficBlockAmount($vars['configoptions'] ?? []);
        if (!$row || !$this->trafficBlocksEnabled($row)) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'disabled' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.disabled', true)
                ]
            ]);
            return;
        }

        if (!$parent_service || ($parent_service->status ?? null) !== 'active') {
            $this->Input->setErrors([
                'traffic_block' => [
                    'parent' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.parent', true)
                ]
            ]);
            return;
        }

        if ((int) ($row->id ?? 0) !== (int) ($parent_service->module_row_id ?? 0)) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'module_row' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.module_row', true)
                ]
            ]);
            return;
        }

        $parent_fields = $this->normalizeLegacyServiceFields(
            $this->serviceFieldsToObject($parent_service->fields ?? [])
        );
        $server_id = $parent_fields->virtfusion_server_id ?? null;
        if (!is_numeric($server_id) || (int) $server_id < 1) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'server_id' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.server_id', true)
                ]
            ]);
            return;
        }
        if (!$amount || $amount < 1) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'amount' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.amount', true)
                ]
            ]);
            return;
        }

        // Pending services are recorded and invoiced first. The current VirtFusion
        // month is intentionally queried only when Blesta activates the paid service.
        if (($vars['use_module'] ?? 'true') !== 'true') {
            return $this->trafficBlockServiceMeta($server_id, $amount);
        }

        $service_id = (int) ($vars['service_id'] ?? 0);
        if ($service_id < 1) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'pending_required' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.pending_required', true)
                ]
            ]);
            return;
        }

        try {
            $lock = $this->acquireOperationLock('traffic', $row->id, $server_id);
        } catch (\Throwable $e) {
            $lock = null;
        }
        if (!$lock) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'locked' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.locked', true)
                ]
            ]);
            return;
        }

        try {
            $server_api = $this->getServerApiFromRow($row);
            $period = $this->getTrafficBlockPeriod($server_api, $server_id);
            if (!$period) {
                return;
            }

            $operation = $this->serviceOperationState($service_id, self::TRAFFIC_BLOCK_OPERATION_FIELD);
            if ($operation) {
                if ((int) ($operation['module_row_id'] ?? 0) !== (int) $row->id
                    || (int) ($operation['server_id'] ?? 0) !== (int) $server_id
                    || (int) ($operation['amount'] ?? 0) !== (int) $amount) {
                    $this->Input->setErrors([
                        'traffic_block' => [
                            'conflict' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.conflict', true)
                        ]
                    ]);
                    return;
                }

                if (($operation['status'] ?? null) === 'completed') {
                    return $this->completedTrafficBlockMeta($operation, $operation['block_id'] ?? null);
                }

                $matches = $this->findNewTrafficBlocks(
                    $operation['before_ids'] ?? [],
                    $period['assigned'],
                    $operation['month'],
                    $operation['amount']
                );
                if (count($matches) === 1) {
                    return $this->completedTrafficBlockMeta($operation, $matches[0]->id);
                }

                if (($operation['status'] ?? null) === 'retry_confirmed'
                    && (int) $operation['month'] === (int) $period['month']) {
                    $operation['before_ids'] = $this->trafficBlockIds($period['assigned']);
                    $operation['status'] = 'submitting';
                    $operation['updated_at'] = date('c');
                    if (!$this->persistServiceOperationState(
                        $service_id,
                        self::TRAFFIC_BLOCK_OPERATION_FIELD,
                        $operation
                    )) {
                        return;
                    }
                } else {
                    $operation['status'] = 'provisioning_unknown';
                    $operation['updated_at'] = date('c');
                    $this->persistServiceOperationState(
                        $service_id,
                        self::TRAFFIC_BLOCK_OPERATION_FIELD,
                        $operation
                    );
                    $this->Input->setErrors([
                        'traffic_block' => [
                            'unknown' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.unknown', true)
                        ]
                    ]);
                    return;
                }
            }

            if (!$operation) {
                $operation = [
                    'operation_key' => hash('sha256', implode(':', [
                        $service_id,
                        $row->id,
                        $server_id,
                        $period['month'],
                        $amount
                    ])),
                    'service_id' => $service_id,
                    'module_row_id' => (int) $row->id,
                    'server_id' => (int) $server_id,
                    'amount' => (int) $amount,
                    'month' => (int) $period['month'],
                    'starts_at' => $period['starts_at'],
                    'ends_at' => $period['ends_at'],
                    'before_ids' => $this->trafficBlockIds($period['assigned']),
                    'status' => 'submitting',
                    'created_at' => date('c'),
                    'updated_at' => date('c')
                ];
                if (!$this->persistServiceOperationState(
                    $service_id,
                    self::TRAFFIC_BLOCK_OPERATION_FIELD,
                    $operation
                )) {
                    return;
                }
            }

            $request = $server_api->addTrafficBlock($server_id, $period['month'], $amount);
            $success = $this->apiRequestSucceeded($request, [201]);
            $this->log($row->meta->hostname . '| add traffic block', serialize($request), 'output', $success);

            $block_id = null;
            $response_data = json_decode((string) ($request['response'] ?? ''));
            if (isset($response_data->data->id)) {
                $block_id = $response_data->data->id;
            } elseif (isset($response_data->id)) {
                $block_id = $response_data->id;
            }

            $after_request = $server_api->getTrafficBlocks($server_id);
            if ($this->apiRequestSucceeded($after_request, [200])) {
                $after_data = json_decode((string) $after_request['response']);
                $matches = $this->findNewTrafficBlocks(
                    $operation['before_ids'],
                    (array) ($after_data->data->assigned ?? []),
                    $operation['month'],
                    $operation['amount']
                );
                if (count($matches) === 1) {
                    $block_id = $matches[0]->id;
                    $success = true;
                }
            }

            if (!$success) {
                $operation['status'] = 'provisioning_unknown';
                $operation['updated_at'] = date('c');
                $this->persistServiceOperationState(
                    $service_id,
                    self::TRAFFIC_BLOCK_OPERATION_FIELD,
                    $operation
                );
                $this->Input->setErrors([
                    'traffic_block' => [
                        'unknown' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.unknown', true)
                    ]
                ]);
                return;
            }

            $meta = $this->completedTrafficBlockMeta($operation, $block_id);
            $completed_state = json_decode(end($meta)['value'] ?? '', true);
            if (!$completed_state || !$this->persistServiceOperationState(
                $service_id,
                self::TRAFFIC_BLOCK_OPERATION_FIELD,
                $completed_state
            )) {
                return;
            }
            return $meta;
        } finally {
            $this->releaseOperationLock($lock);
        }
    }

    public function getProductAddonCapabilities($parent_package, $parent_service)
    {
        $row = $this->getModuleRow();
        if (!$this->trafficBlocksEnabled($row)
            || !$parent_service
            || ($parent_service->status ?? null) !== 'active'
            || (int) ($row->id ?? 0) !== (int) ($parent_service->module_row_id ?? 0)) {
            return [];
        }

        $fields = $this->normalizeLegacyServiceFields(
            $this->serviceFieldsToObject($parent_service->fields ?? [])
        );
        if (empty($fields->virtfusion_server_id)) {
            return [];
        }

        return [
            self::TRAFFIC_BLOCK_CAPABILITY => [
                'label' => Language::_('VirtfusionDirectProvisioningMod.product_addon.traffic_block', true),
                'one_time' => true,
                'requires_active_parent' => true,
                'requires_provisioned_parent' => true,
                'requires_parent_module_row' => true
            ]
        ];
    }

    public function previewProductAddon(
        $capability,
        $parent_package,
        $parent_service,
        $addon_package,
        array $config_options = []
    ) {
        if ($capability !== self::TRAFFIC_BLOCK_CAPABILITY
            || !$this->isTrafficBlockPackage($addon_package)
            || (int) ($parent_package->module_id ?? 0) !== (int) ($addon_package->module_id ?? 0)) {
            $this->Input->setErrors([
                'product_addon' => [
                    'capability' => Language::_('VirtfusionDirectProvisioningMod.!error.product_addon.capability', true)
                ]
            ]);
            return;
        }

        $capabilities = $this->getProductAddonCapabilities($parent_package, $parent_service);
        $amount = $this->getTrafficBlockAmount($config_options);
        if (!isset($capabilities[$capability]) || !$amount || $amount < 1) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'amount' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.amount', true)
                ]
            ]);
            return;
        }

        $fields = $this->normalizeLegacyServiceFields(
            $this->serviceFieldsToObject($parent_service->fields ?? [])
        );
        $api = $this->getApiFromRow($this->getModuleRow());
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);
        $period = $this->getTrafficBlockPeriod($server_api, $fields->virtfusion_server_id);
        if (!$period) {
            return;
        }

        Loader::loadHelpers($this, ['Date']);

        return [
            'traffic_block' => $amount . ' GB',
            'valid_from' => $this->Date->cast($period['starts_at'], 'date_time'),
            'valid_until' => $this->Date->cast($period['ends_at'], 'date_time'),
            'notice' => Language::_('VirtfusionDirectProvisioningMod.product_addon.period_notice', true)
        ];
    }

    private function getPrimaryStorage($server_data)
    {
        foreach (($server_data->data->storage ?? []) as $storage) {
            if (!empty($storage->primary)) {
                return (int) $storage->capacity;
            }
        }

        if (isset($server_data->data->settings->resources->storage)) {
            return (int) $server_data->data->settings->resources->storage;
        }

        return null;
    }

    private function selectCurrentTrafficMonth(array $monthly, $period_end = null)
    {
        $now = time();
        $fallback = null;
        foreach ($monthly as $month) {
            if ($period_end && isset($month->end) && (string) $month->end === (string) $period_end) {
                return $month;
            }

            $start = isset($month->start) ? strtotime($month->start . ' UTC') : false;
            $end = isset($month->end) ? strtotime($month->end . ' UTC') : false;
            if ($start !== false && $end !== false && $start <= $now && $now <= $end) {
                return $month;
            }

            if (!$fallback || $end > strtotime(($fallback->end ?? '') . ' UTC')) {
                $fallback = $month;
            }
        }

        return $fallback;
    }

    private function getServiceConfigOptionValue($service, $option_name)
    {
        foreach (($service->options ?? []) as $option) {
            if (($option->option_name ?? null) !== $option_name) {
                continue;
            }

            return ($option->option_type ?? null) === 'quantity'
                ? ($option->qty ?? null)
                : ($option->value ?? $option->option_value ?? null);
        }

        return null;
    }

    private function configOptionValuesEqual($left, $right)
    {
        $normalize = function ($value) {
            if (is_array($value)) {
                $value = array_map('strval', $value);
                sort($value);
                return json_encode($value);
            }

            return (string) $value;
        };

        return $normalize($left) === $normalize($right);
    }

    private function requestedNetworkSpeed(array $config_options)
    {
        return $config_options[self::NETWORK_SPEED_OPTION] ?? null;
    }

    private function currentNetworkSpeed($service)
    {
        return $this->getServiceConfigOptionValue($service, self::NETWORK_SPEED_OPTION);
    }

    private function setRestartRecommended($service_id, $recommended)
    {
        if (empty($service_id)) {
            return;
        }

        Loader::loadModels($this, ['Services']);
        $this->Services->editField($service_id, [
            'key' => 'virtfusion_restart_required',
            'value' => $recommended ? 'true' : 'false',
            'encrypted' => false
        ]);
    }

    private function mergedServiceMeta($service, array $overrides, array $encrypted_overrides = [], array $exclude = [])
    {
        $fields = [];
        foreach (($service->fields ?? []) as $field) {
            if (in_array($field->key, $exclude, true)) {
                continue;
            }
            $fields[$field->key] = [
                'key' => $field->key,
                'value' => $field->value,
                'encrypted' => (int) ($field->encrypted ?? 0)
            ];
        }

        foreach ($overrides as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $fields[$key] = [
                'key' => $key,
                'value' => $value,
                'encrypted' => in_array($key, $encrypted_overrides, true) ? 1 : 0
            ];
        }

        return array_values($fields);
    }

    private function getAdminServerUrl($module_row, $server_id)
    {
        $template = trim((string) ($module_row->meta->admin_server_url ?? ''));
        if ($template === '' || !$this->validateAdminServerUrlTemplate($template, $module_row->meta->hostname)) {
            $template = 'https://{hostname}/admin/servers/{server_id}';
        }

        return str_replace(
            ['{hostname}', '{server_id}'],
            [$module_row->meta->hostname, (int) $server_id],
            $template
        );
    }

    private function vncWebSocketUrl($module_row, $path)
    {
        $path = trim((string) $path);
        if ($path === '' || preg_match('/[\r\n]/', $path)) {
            return null;
        }

        if (strpos($path, 'wss://') === 0) {
            $parts = parse_url($path);
            if (!$parts
                || strcasecmp((string) ($parts['host'] ?? ''), (string) $module_row->meta->hostname) !== 0) {
                return null;
            }
            return $path;
        }

        if ($path[0] !== '/') {
            return null;
        }

        return 'wss://' . $module_row->meta->hostname . $path;
    }

    private function moduleHasOwnedData($module_id)
    {
        foreach (['module_rows', 'module_groups', 'module_meta', 'packages'] as $table) {
            if ($this->Record->select('module_id')
                ->from($table)
                ->where('module_id', '=', $module_id)
                ->fetch()) {
                return true;
            }
        }

        return false;
    }

    private function syncModuleOwnership($module, $action)
    {
        Loader::loadModels($this, ['ModuleManager']);

        $company_id = $module->company_id ?? Configure::get('Blesta.company_id');
        $official_modules = $this->ModuleManager->getByClass(self::OFFICIAL_MODULE_CLASS, $company_id);
        $mod_modules = $this->ModuleManager->getByClass(self::MOD_MODULE_CLASS, $company_id);

        if (count($official_modules) !== 1 || count($mod_modules) !== 1) {
            return [
                'success' => false,
                'message' => Language::_('VirtfusionDirectProvisioningMod.sync.error.modules_missing', true)
            ];
        }

        $official_module = $official_modules[0];
        $mod_module = $mod_modules[0];
        $sync_from_official = $action === 'from_official';
        $source_module = $sync_from_official ? $official_module : $mod_module;
        $destination_module = $sync_from_official ? $mod_module : $official_module;

        if ($this->moduleHasOwnedData($destination_module->id)) {
            return [
                'success' => false,
                'message' => Language::_('VirtfusionDirectProvisioningMod.sync.error.destination_not_empty', true)
            ];
        }

        // The official module needs its original IP field layout. Convert it
        // before moving ownership; the mod can still read that layout if the
        // ownership transaction is later rolled back.
        if (!$sync_from_official) {
            $failed_service_id = $this->migrateModuleServiceFieldSchema($source_module->id, 'official');
            if ($failed_service_id !== null) {
                return [
                    'success' => false,
                    'message' => Language::_(
                        'VirtfusionDirectProvisioningMod.sync.error.service_fields',
                        true,
                        $failed_service_id
                    )
                ];
            }
        }

        try {
            $this->Record->begin();

            foreach (['module_rows', 'module_groups', 'module_meta', 'packages'] as $table) {
                $this->Record->where('module_id', '=', $source_module->id)
                    ->update($table, ['module_id' => $destination_module->id]);
            }

            $this->Record->commit();
        } catch (\Throwable $e) {
            try {
                $this->Record->rollBack();
            } catch (\Throwable $rollback_error) {
                // The original database error is the useful one to report.
            }

            return [
                'success' => false,
                'message' => Language::_('VirtfusionDirectProvisioningMod.sync.error.database', true)
            ];
        }

        $message = Language::_(
            $sync_from_official
                ? 'VirtfusionDirectProvisioningMod.sync.success.from_official'
                : 'VirtfusionDirectProvisioningMod.sync.success.to_official',
            true
        );
        if ($sync_from_official) {
            $failed_service_id = $this->migrateModuleServiceFieldSchema($destination_module->id, 'mod');
            if ($failed_service_id !== null) {
                $message .= ' ' . Language::_(
                    'VirtfusionDirectProvisioningMod.sync.warning.service_fields',
                    true,
                    $failed_service_id
                );
            }
        }

        return [
            'success' => true,
            'module_id' => $destination_module->id,
            'message' => $message
        ];
    }

    /**
     * Performs any necessary bootstraping actions
     */
    public function install()
    {
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version. Sets Input errors on failure, preventing
     * the module from being upgraded.
     *
     * @param string $current_version The current installed version of this module
     */
    public function upgrade($current_version)
    {
        if (version_compare($current_version, '1.0.1', '<')) {
            if (!isset($this->ModuleManager)) {
                Loader::loadModels($this, ['ModuleManager', 'Services']);
            }
            $modules = $this->ModuleManager->getByClass('virtfusion_direct_provisioning_mod');

            // get mod info
            foreach ($modules as $module) {
                $rows = $this->ModuleManager->getRows($module->id);
                foreach ($rows as $row) {
                    $this->upgrade1_0_1($row);
                }
            }
        }

        if (version_compare($current_version, '2026.07.18.6', '<')) {
            $this->upgradeServiceFieldSchema();
        }
    }

    private function upgradeServiceFieldSchema()
    {
        Loader::loadModels($this, ['ModuleManager', 'Services']);

        foreach ($this->ModuleManager->getByClass(self::MOD_MODULE_CLASS) as $module) {
            $failed_service_id = $this->migrateModuleServiceFieldSchema($module->id, 'mod');
            if ($failed_service_id !== null) {
                $this->Input->setErrors([
                    'service_fields' => [
                        'migration' => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.service_fields.migration',
                            true,
                            $failed_service_id
                        )
                    ]
                ]);
                return;
            }
        }
    }

    private function migrateModuleServiceFieldSchema($module_id, $target)
    {
        Loader::loadModels($this, ['ModuleManager', 'Services']);
        $migrated_services = [];

        foreach ($this->ModuleManager->getRows($module_id) as $row) {
            $services = $this->Services->getAll(
                ['date_added' => 'DESC'],
                true,
                ['status' => 'all'],
                ['services' => ['module_row_id' => $row->id]]
            );

            foreach ($services as $service) {
                if (isset($migrated_services[$service->id])) {
                    continue;
                }
                $migrated_services[$service->id] = true;

                $fields = $target === 'official'
                    ? $this->officialServiceMeta($service)
                    : $this->canonicalServiceMeta($service);
                if ($fields === null) {
                    continue;
                }

                $this->Services->setFields($service->id, $fields);
                if ($this->Services->errors()) {
                    return $service->id;
                }
            }
        }

        return null;
    }

    private function upgrade1_0_1($row)
    {
        $api_token = null;
        $hostname = null;
        $module_row_id = null;

        $meta = (array)$row->meta;

        if (isset($meta['api_token']) && isset($meta['hostname'])) {
            $api_token = $meta['api_token'];
            $hostname = $meta['hostname'];
            $module_row_id = $row->id;
        }

        if ($api_token && $hostname && $module_row_id) {
            $services = $this->Services->getAll(
                ['date_added' => 'DESC'],
                true,
                [],
                [
                    'services' => [
                        'module_row_id' => $module_row_id
                    ]
                ]
            );

            $api = $this->getApi($api_token, $hostname);

            foreach ($services as $service) {
                $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

                $server_id = $service_fields->virtfusion_server_id;
                $virtfusion_ipv6 = null;

                $server_info = $api->get_query("servers/$server_id");
                $server_data = json_decode($server_info['response']);
                if (isset($server_data->data->network->interfaces[0]->ipv6[0])) {
                    $ipv6_data = $server_data->data->network->interfaces[0]->ipv6[0];
                    $virtfusion_ipv6 = $ipv6_data->subnet . '/' . $ipv6_data->cidr;
                }

                $insert = [
                    'key' => 'virtfusion_ipv6_cidr',
                    'value' => $virtfusion_ipv6
                ];

                $this->Services->editField($service->id, $insert);
                unset($virtfusion_ipv6);
            }
        }
    }

    /**
     * Performs any necessary cleanup actions. Sets Input errors on failure
     * after the module has been uninstalled.
     *
     * @param int $module_id The ID of the module being uninstalled
     * @param bool $last_instance True if $module_id is the last instance
     *  across all companies for this module, false otherwise
     */
    public function uninstall($module_id, $last_instance)
    {
    }

    /**
     * Returns the value used to identify a particular service
     *
     * @param stdClass $service A stdClass object representing the service
     * @return string A value used to identify this service amongst other similar services
     */
    public function getServiceName($service)
    {
        $public_label = null;
        $traffic_block_gb = null;

        foreach ($service->fields as $field) {
            if ($field->key == 'virtfusion_hostname') {
                return $field->value;
            }

            if ($field->key === 'virtfusion_public_label') {
                $public_label = $field->value;
            }

            if ($field->key === 'virtfusion_traffic_block_gb') {
                $traffic_block_gb = $field->value;
            }
        }

        if ($traffic_block_gb !== null) {
            return Language::_('VirtfusionDirectProvisioningMod.service_name.traffic_block', true, $traffic_block_gb);
        }

        return $public_label ?: Language::_('VirtfusionDirectProvisioningMod.service_name.server', true);
    }

    /**
     * Returns the rendered view of the manage module page.
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data submitted to or on the manager module
     *  page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the manager module page
     */
    public function manageModule($module, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $sync_result = null;
        if (isset($vars['sync_action']) && in_array($vars['sync_action'], ['from_official', 'to_official'], true)) {
            $sync_result = $this->syncModuleOwnership($module, $vars['sync_action']);
            // Prevent AdminCompanyModules from persisting the sync action as module metadata.
            $vars = [];

            if ($sync_result['success']) {
                Loader::loadModels($this, ['ModuleManager']);
                $module = $this->ModuleManager->get($module->id);
            }
        }

        $this->view->set('module', $module);
        $this->view->set('sync_result', $sync_result);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page.
     *
     * @param array $vars An array of post data submitted to or on the add module
     *  row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the add module row page
     */
    public function manageAddRow(array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (!empty($vars)) {
            // Set unset checkboxes
            $checkbox_fields = [];

            foreach ($checkbox_fields as $checkbox_field) {
                if (!isset($vars[$checkbox_field])) {
                    $vars[$checkbox_field] = 'false';
                }
            }
        }

        // Fetch module
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass(
            \Illuminate\Support\Str::snake(get_class($this)),
            Configure::get('Blesta.company_id')
        );
        $module = ($module[0] ?? []);
        $this->view->set('module', (object) $module);
        $this->view->set('vars', (object)$vars);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data submitted to or on the edit
     *  module row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the edit module row page
     */
    public function manageEditRow($module_row, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = $module_row->meta;
        } else {
            // Set unset checkboxes
            $checkbox_fields = [];

            foreach ($checkbox_fields as $checkbox_field) {
                if (!isset($vars[$checkbox_field])) {
                    $vars[$checkbox_field] = 'false';
                }
            }
        }

        // Fetch module
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass(
            \Illuminate\Support\Str::snake(get_class($this)),
            Configure::get('Blesta.company_id')
        );
        $module = ($module[0] ?? []);
        $this->view->set('module', (object) $module);
        $this->view->set('vars', (object)$vars);

        return $this->view->fetch();
    }

    /**
     * Adds the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being added. Returns a set of data, which may be
     * a subset of $vars, that is stored for this module row.
     *
     * @param array $vars An array of module info to add
     * @return array A numerically indexed array of meta fields for the module row containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function addModuleRow(array &$vars)
    {
        $meta_fields = [
            'name',
            'hostname',
            'api_token',
            'admin_server_url',
            'traffic_blocks_enabled',
            'allow_insecure_tls'
        ];
        $encrypted_fields = ['api_token'];

        // Set unset checkboxes
        $checkbox_fields = ['traffic_blocks_enabled', 'allow_insecure_tls'];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            $vars['hostname'] = strtolower($vars['hostname']);
            // Build the meta data for this row
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Edits the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being updated. Returns a set of data, which may be
     * a subset of $vars, that is stored for this module row.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array A numerically indexed array of meta fields for the module row containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function editModuleRow($module_row, array &$vars)
    {
        $meta_fields = [
            'name',
            'hostname',
            'api_token',
            'admin_server_url',
            'traffic_blocks_enabled',
            'allow_insecure_tls'
        ];
        $encrypted_fields = ['api_token'];

        // Set unset checkboxes
        $checkbox_fields = ['traffic_blocks_enabled', 'allow_insecure_tls'];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            $vars['hostname'] = strtolower($vars['hostname']);
            // Build the meta data for this row
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Builds and returns the rules required to add/edit a module row (e.g. server).
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getRowRules(&$vars)
    {
        $rules = [
            'name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.name.empty', true)
                ]
            ],
            'hostname' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.hostname.empty', true)
                ],
            ],
            'api_token' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.api_token.empty', true)
                ],
                'valid' => [
                    'rule' => [[$this, 'validateApiCredentials'], $vars],
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.api_token.valid', true)
                ]
            ],
            'admin_server_url' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [
                        [$this, 'validateAdminServerUrlTemplate'],
                        $vars['hostname'] ?? ''
                    ],
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.admin_server_url.valid', true)
                ]
            ]
        ];

        return $rules;
    }

    // ping server to make sure we have valid host and api token
    public function validateApiCredentials($api_token, $vars)
    {
        try {
            $api = $this->getApi(
                $vars['api_token'],
                $vars['hostname'],
                $vars['allow_insecure_tls'] ?? false
            );
            $request = $api->get_query('packages');

            if (!$this->apiRequestSucceeded($request, [200])) {
                $msg =  ($request['response']) ? json_decode($request['response']) : 'Invalid API Token';
                $this->log($vars['hostname'], serialize($msg), 'output', false);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Trap any errors encountered, could not validate connection
            return false;
        }
    }

    public function validateAdminServerUrlTemplate($template, $hostname)
    {
        $template = trim((string) $template);
        if ($template === '') {
            return true;
        }

        $url = str_replace(['{hostname}', '{server_id}'], [(string) $hostname, '1'], $template);
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && empty($parts['user'])
            && empty($parts['pass'])
            && strcasecmp(rtrim((string) $parts['host'], '.'), rtrim((string) $hostname, '.')) === 0;
    }


    /**
     * Returns an array of available service delegation order methods. The module
     * will determine how each method is defined. For example, the method "first"
     * may be implemented such that it returns the module row with the least number
     * of services assigned to it.
     *
     * @return array An array of order methods in key/value pairs where the key
     *  is the type to be stored for the group and value is the name for that option
     * @see Module::selectModuleRow()
     */
    public function getGroupOrderOptions()
    {
        return [
            'first' => Language::_('VirtfusionDirectProvisioningMod.order_options.first', true)
        ];
    }

    /**
     * Determines which module row should be attempted when a service is provisioned
     * for the given group based upon the order method set for that group.
     *
     * @return int The module row ID to attempt to add the service with
     * @see Module::getGroupOrderOptions()
     */
    public function selectModuleRow($module_group_id)
    {
        if (!isset($this->ModuleManager)) {
            Loader::loadModels($this, ['ModuleManager']);
        }

        $group = $this->ModuleManager->getGroup($module_group_id);

        if ($group) {
            switch ($group->add_order) {
                default:
                case 'first':
                    foreach ($group->rows as $row) {
                        return $row->id;
                    }

                    break;
            }
        }
        return 0;
    }

    /**
     * Validates input data when attempting to add a package, returns the meta
     * data to save when adding a package. Performs any action required to add
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being added.
     *
     * @param array An array of key/value pairs used to add the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addPackage(?array $vars = null)
    {
        // Set rules to validate input data
        $this->Input->setRules($this->getPackageRules($vars));

        // Build meta data to return
        $meta = [];
        if ($this->Input->validates($vars)) {
            if (!isset($vars['meta'])) {
                return [];
            }

            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Validates input data when attempting to edit a package, returns the meta
     * data to save when editing a package. Performs any action required to edit
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being edited.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array An array of key/value pairs used to edit the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editPackage($package, ?array $vars = null)
    {
        // Set rules to validate input data
        $this->Input->setRules($this->getPackageRules($vars));

        // Build meta data to return
        $meta = [];
        if ($this->Input->validates($vars)) {
            if (!isset($vars['meta'])) {
                return [];
            }

            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Deletes the package on the remote server. Sets Input errors on failure,
     * preventing the package from being deleted.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function deletePackage($package)
    {
    }

    /**
     * Builds and returns rules required to be validated when adding/editing a package.
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getPackageRules(array $vars)
    {
        $service_type = $vars['meta']['virtfusion-service_type'] ?? 'server';
        $service_type_rule = [
            'valid' => [
                'if_set' => true,
                'rule' => ['in_array', ['server', self::TRAFFIC_BLOCK_CAPABILITY]],
                'message' => Language::_('VirtfusionDirectProvisioningMod.!error.meta.service_type.valid', true)
            ]
        ];

        if ($service_type === self::TRAFFIC_BLOCK_CAPABILITY) {
            return [
                'meta[virtfusion-service_type]' => $service_type_rule
            ];
        }

        // Validate the package fields
        $rules = [
            'meta[virtfusion-service_type]' => $service_type_rule,
            'meta[hypervisor_group_id]' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.meta[hypervisor_group_id].valid', true)
                ]
            ],
            'meta[default_ipv4]' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.meta[default_ipv4].valid', true)
                ]
            ],
            'meta[package_id]' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.meta[package_id].valid', true)
                ]
            ]
        ];

        return $rules;
    }

    public function validateOptionalPositiveInteger($value)
    {
        return $value === null
            || $value === ''
            || (is_numeric($value) && (int) $value > 0 && (float) $value === (float) (int) $value);
    }

    /**
     * Returns all fields used when adding/editing a package, including any
     * javascript to execute when the page is rendered with these fields.
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to
     *  render as well as any additional HTML markup to include
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        $service_type = $fields->label(
            Language::_('VirtfusionDirectProvisioningMod.package_fields.service_type', true),
            'virtfusion_direct_provisioning_mod_service_type'
        );
        $service_type->attach(
            $fields->fieldSelect(
                'meta[virtfusion-service_type]',
                [
                    'server' => Language::_('VirtfusionDirectProvisioningMod.package_fields.service_type.server', true),
                    self::TRAFFIC_BLOCK_CAPABILITY => Language::_('VirtfusionDirectProvisioningMod.package_fields.service_type.traffic_block', true)
                ],
                ($vars->meta['virtfusion-service_type'] ?? 'server'),
                ['id' => 'virtfusion_direct_provisioning_mod_service_type']
            )
        );
        $fields->setField($service_type);

        // Set the Hypervisor Group ID field
        $hypervisor_group_id = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.hypervisor_group_id', true), 'virtfusion_direct_provisioning_mod_hypervisor_group_id');
        $hypervisor_group_id->attach(
            $fields->fieldText(
                'meta[hypervisor_group_id]',
                ($vars->meta['hypervisor_group_id'] ?? null),
                ['id' => 'virtfusion_direct_provisioning_mod_hypervisor_group_id']
            )
        );
        $fields->setField($hypervisor_group_id);

        // Set the Default IPv4 field
        $default_ipv4 = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.default_ipv4', true), 'virtfusion_direct_provisioning_mod_default_ipv4');
        $default_ipv4->attach(
            $fields->fieldText(
                'meta[default_ipv4]',
                ($vars->meta['default_ipv4'] ?? null),
                ['id' => 'virtfusion_direct_provisioning_mod_default_ipv4']
            )
        );
        $fields->setField($default_ipv4);

        // Set the Package ID field
        $package_id = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.package_id', true), 'virtfusion_direct_provisioning_mod_package_id');
        $package_id->attach(
            $fields->fieldText(
                'meta[package_id]',
                ($vars->meta['package_id'] ?? null),
                ['id' => 'virtfusion_direct_provisioning_mod_package_id']
            )
        );
        $fields->setField($package_id);

        $fields->setHtml('
            <script type="text/javascript">
                (function () {
                    var type = document.getElementById("virtfusion_direct_provisioning_mod_service_type");
                    var serverIds = [
                        "virtfusion_direct_provisioning_mod_hypervisor_group_id",
                        "virtfusion_direct_provisioning_mod_default_ipv4",
                        "virtfusion_direct_provisioning_mod_package_id"
                    ];
                    function updateVirtFusionProductType() {
                        var isBlock = type && type.value === "traffic_block";
                        serverIds.forEach(function (id) {
                            var field = document.getElementById(id);
                            if (field && field.closest(".mb-3")) {
                                field.closest(".mb-3").style.display = isBlock ? "none" : "";
                            }
                        });
                    }
                    if (type) {
                        type.addEventListener("change", updateVirtFusionProductType);
                    }
                    updateVirtFusionProductType();
                }());
            </script>
        ');

        return $fields;
    }

    /**
     * Adds the service to the remote server. Sets Input errors on failure,
     * preventing the service from being added.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being added (if the current service is an addon service
     *  service and parent service has already been provisioned)
     * @param string $status The status of the service being added. These include:
     *  - active
     *  - canceled
     *  - pending
     *  - suspended
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addService(
        $package,
        ?array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        $vars = $vars ?? [];
        if ($this->isTrafficBlockPackage($package)) {
            $this->validateService($package, $vars);
            if ($this->Input->errors()) {
                return;
            }

            return $this->provisionTrafficBlock($package, $vars, $parent_service);
        }

        // $this->Input->setErrors(['api' => ['response' => print_r($client, true)]]);
        // return;
        // Set unset checkboxes
        $checkbox_fields = [];

        $create_config_options = $vars['configoptions'] ?? [];
        $virtfusion_os_id = $create_config_options['operatingSystemId'] ?? null;
        $auto_build = $this->shouldAutoBuild($create_config_options);
        $ipv4_count = $this->getIpv4Quantity($package, $create_config_options);
        $domain = isset($vars['virtfusion_hostname']) ? trim($vars['virtfusion_hostname']) : '';
        $server_id = 0;
        $virtfusion_password = '';
        $virtfusion_primary_ipv4 = '';
        $virtfusion_secondary_ipv4 = '';
        $virtfusion_ipv6_cidr = '';
        $virtfusion_backup_plan_id = '';
        $virtfusion_build_state = $auto_build ? 'pending' : 'skipped';
        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        // Load the API
        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors([
                'module_row' => [
                    'missing' => Language::_('VirtfusionDirectProvisioningMod.!error.module_row.missing', true)
                ]
            ]);
            return;
        }

        $api = $this->getApiFromRow($row);

        // Get the fields for the service
        //$params = $this->getFieldsFromInput($vars, $package);

        // Validate the service-specific fields
        $this->validateService($package, $vars);
        if ($this->Input->errors()) {
            return;
        }

        // Only provision the service if 'use_module' is true
        if (($vars['use_module'] ?? 'true') == 'true') {
            /**
             *
             * We need to check if a user exists in VirtFusion based on the extrelid
             *
             */
            // $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

            $api->loadCommand('virtfusion_client');

            try {
                $server_api = new VirtfusionClient($api);

                $this->log($row->meta->hostname . '| client check', $vars['client_id'], 'input', true);
                $request = $server_api->check($vars['client_id'], []);


                if (isset($request['info'])) {
                    $this->log(
                        $row->meta->hostname . '| client check result',
                        'HTTP ' . (int) ($request['info']['http_code'] ?? 0),
                        'output',
                        $this->apiRequestSucceeded($request, [200, 404])
                    );
                    switch ($request['info']['http_code']) {
                        case 200:
                            $data = json_decode($request['response']);
                            /**
                             *
                             * A user already exists
                             *
                             */
                            break;

                        case 404:
                            Loader::loadModels($this, ['Clients']);

                            $this->log($row->meta->hostname . '| get client', $vars['client_id'], 'input', true);
                            $client = $this->Clients->get($vars['client_id'], false);

                            $request = $server_api->create([
                                'name' => $client->first_name . ' ' . $client->last_name,
                                'email' => $client->email,
                                'extRelationId' => $vars['client_id']
                            ]);

                            $this->log(
                                $row->meta->hostname . '| client create',
                                'HTTP ' . (int) ($request['info']['http_code'] ?? 0),
                                'output',
                                $this->apiRequestSucceeded($request, [201])
                            );

                            if (isset($request['info'])) {
                                if ($request['info']['http_code'] !== 201) {
                                    $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                                    return;
                                }
                            } else {
                                $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                                return;
                            }

                            $data = json_decode($request['response']);

                            break;

                        default:
                            $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                            return;
                    };


                    /**
                     *
                     * Create server
                     *
                     */

                    $api->loadCommand('virtfusion_server');

                    $server_api = new VirtfusionServer($api);

                    $hypervisor_group_id = (int) $package->meta->hypervisor_group_id;
                    $request_params = [
                        'packageId' => (int) $package->meta->package_id,
                        'userId' => (int) $data->data->id,
                        'hypervisorId' => $hypervisor_group_id,
                        'ipv4' => $ipv4_count,
                    ];

                    $request_params = $this->applyCreateConfigOptions($request_params, $create_config_options);

                    if (!array_key_exists('traffic', $create_config_options)
                        && isset($create_config_options[self::ADDITIONAL_TRAFFIC_OPTION])
                        && $create_config_options[self::ADDITIONAL_TRAFFIC_OPTION] !== '') {
                        $additional_traffic = $create_config_options[self::ADDITIONAL_TRAFFIC_OPTION];
                        if (!is_numeric($additional_traffic) || (int) $additional_traffic < 0) {
                            $this->Input->setErrors([
                                'configoptions' => [
                                    self::ADDITIONAL_TRAFFIC_OPTION => Language::_(
                                        'VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum',
                                        true
                                    )
                                ]
                            ]);
                            return;
                        }

                        $package_request = $server_api->getPkg($package->meta->package_id);
                        if (!$this->apiRequestSucceeded($package_request, [200])) {
                            $this->Input->setErrors([
                                'api' => [
                                    'response' => Language::_(
                                        'VirtfusionDirectProvisioningMod.!error.package.target',
                                        true
                                    )
                                ]
                            ]);
                            return;
                        }
                        $package_data = json_decode((string) $package_request['response']);
                        $target_traffic = (int) ($package_data->data->traffic ?? 0)
                            + (int) $additional_traffic;
                        if ($target_traffic > 999999999) {
                            $this->Input->setErrors([
                                'configoptions' => [
                                    self::ADDITIONAL_TRAFFIC_OPTION => Language::_(
                                        'VirtfusionDirectProvisioningMod.!error.configoption.traffic.maximum',
                                        true
                                    )
                                ]
                            ]);
                            return;
                        }
                        $request_params['traffic'] = $target_traffic;
                    }

                    $request = $server_api->create($request_params);

                    $this->log(
                        $row->meta->hostname . '| create server',
                        'HTTP ' . (int) ($request['info']['http_code'] ?? 0),
                        'output',
                        $this->apiRequestSucceeded($request, [201])
                    );

                    if (!$this->apiRequestSucceeded($request, [201])) {
                        $this->Input->setErrors(['api' => ['response' => $this->apiErrorMessage($request)]]);
                        return;
                    }

                    $data = json_decode($request['response']);

                    $server_id = $data->data->id;

                    /**
                     *
                     * Build server
                     *
                     */

                    $this->log($row->meta->hostname . '| build os id', $virtfusion_os_id, 'input', true);

                    $hasError = $auto_build;

                    // check that is int no hiccups in extraction
                    if ($auto_build && is_numeric($virtfusion_os_id) && !empty($domain)) {
                        $server_name = substr($domain, 0, strrpos($domain, '.'));
                        $build_params = [
                            'operatingSystemId' => (int) $virtfusion_os_id,
                            'name' => $server_name,
                            'hostname' => $domain,
                            'ipv6' => true
                        ];

                        if (!empty($create_config_options['sshKeys'])) {
                            $build_params['sshKeys'] = $this->csvInts($create_config_options['sshKeys']);
                        }

                        if (isset($create_config_options['ipv6'])) {
                            $build_params['ipv6'] = $this->boolValue($create_config_options['ipv6']);
                        }

                        if (isset($create_config_options['email'])) {
                            $build_params['email'] = $this->boolValue($create_config_options['email']);
                        }

                        if (isset($create_config_options['swap']) && $create_config_options['swap'] !== '') {
                            $build_params['swap'] = (float) $create_config_options['swap'];
                        }

                        $build_request = $server_api->build(
                            $server_id,
                            $build_params
                        );

                        $build_data = json_decode($build_request['response']);

                        if ($this->apiRequestSucceeded($build_request, [200])) {
                            $hasError = false;
                            $virtfusion_build_state = 'built';

                            $virtfusion_password = $build_data->data->settings->decryptedPassword ?? '';

                            // if 200 we should have this
                            $ip_addresses = [];
                            foreach (($build_data->data->network->interfaces[0]->ipv4 ?? []) as $ip) {
                                if (isset($ip->address)) {
                                    $ip_addresses[] = $ip->address;
                                }
                            }
                            if (isset($ip_addresses[0])) {
                                $virtfusion_primary_ipv4 = $ip_addresses[0];
                            }
                            $virtfusion_secondary_ipv4 = implode(',', array_slice($ip_addresses, 1));

                            for ($i = 0; $i < 5; $i++) {
                                if ($i > 0) {
                                    sleep(2);
                                }
                                $server_info = $api->get_query("servers/$server_id");
                                $server_data = json_decode($server_info['response']);
                                if (isset($server_data->data->network->interfaces[0]->ipv6[0])) {
                                    $ipv6_data = $server_data->data->network->interfaces[0]->ipv6[0];
                                    $virtfusion_ipv6_cidr = $ipv6_data->subnet . '/' . $ipv6_data->cidr;
                                    $this->log($row->meta->hostname . '| get ipv6', serialize($ipv6_data), 'output', $server_info['info']['http_code'] == 200);
                                    break;
                                }
                            }
                        }

                        $this->log(
                            $row->meta->hostname . '| build server',
                            'HTTP ' . (int) ($build_request['info']['http_code'] ?? 0),
                            'output',
                            $this->apiRequestSucceeded($build_request, [200])
                        );
                    } elseif (!$auto_build) {
                        $this->log($row->meta->hostname . '| build server', 'Skipped automatic build by configuration.', 'output', true);
                    }

                    if ($hasError) {
                        // The build request may have reached VirtFusion even when its
                        // response was lost. Keep the successfully-created server under
                        // Blesta management instead of issuing a destructive cancel.
                        $virtfusion_build_state = 'failed_or_unknown';
                        $this->log(
                            $row->meta->hostname . '| build result retained',
                            'Server #' . (int) $server_id . ' requires build reconciliation.',
                            'output',
                            false
                        );
                    }

                    if (isset($create_config_options[self::BACKUP_PLAN_OPTION])) {
                        $virtfusion_backup_plan_id = $create_config_options[self::BACKUP_PLAN_OPTION];
                    }

                    if ($virtfusion_backup_plan_id !== '' && is_numeric($virtfusion_backup_plan_id)) {
                        $backup_request = $server_api->setBackupPlan($server_id, $virtfusion_backup_plan_id);
                        $this->log($row->meta->hostname . '| backup plan', serialize($backup_request), 'output', in_array($backup_request['info']['http_code'], [200, 201, 204]));
                    }

                    if (isset($create_config_options[self::CPU_THROTTLE_OPTION])
                        && $create_config_options[self::CPU_THROTTLE_OPTION] !== ''
                        && is_numeric($create_config_options[self::CPU_THROTTLE_OPTION])) {
                        $cpu_throttle_request = $server_api->modifyCpuThrottle($server_id, [
                            'percent' => (int) $create_config_options[self::CPU_THROTTLE_OPTION]
                        ]);
                        $this->log(
                            $row->meta->hostname . '| cpu throttle',
                            serialize($cpu_throttle_request),
                            'output',
                            $this->apiRequestSucceeded($cpu_throttle_request, [200, 201, 204])
                        );
                    }

                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                    return;
                }
            } catch (\Throwable $e) {
                $this->setModuleActionException($row, 'create service exception', $e);
                return;
            }
        }

        $service_meta = [
            [
                'key' => 'virtfusion_public_label',
                'value' => $this->publicServiceLabel('vf'),
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_server_id',
                'value' => $server_id,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_backup_plan_id',
                'value' => $virtfusion_backup_plan_id,
                'encrypted' => 0
            ],
            [
                'key' => self::BUILD_STATE_FIELD,
                'value' => $virtfusion_build_state,
                'encrypted' => 0
            ]
        ];

        if (!$auto_build) {
            return $service_meta;
        }

        return array_merge($service_meta, [
            [
                'key' => 'virtfusion-os_template',
                'value' => $virtfusion_os_id,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_hostname',
                'value' => $domain,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_password',
                'value' => $virtfusion_password,
                'encrypted' => 1
            ],
            [
                'key' => self::PRIMARY_IPV4_FIELD,
                'value' => $virtfusion_primary_ipv4,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_ipv6_cidr',
                'value' => $virtfusion_ipv6_cidr,
                'encrypted' => 0
            ],
            [
                'key' => self::SECONDARY_IPV4_FIELD,
                'value' => $virtfusion_secondary_ipv4,
                'encrypted' => 0
            ]
        ]);

        // Return service fields
    }

    /**
     * Edits the service on the remote server. Sets Input errors on failure,
     * preventing the service from being edited.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being edited (if the current service is an addon service)
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editService($package, $service, ?array $vars = null, $parent_package = null, $parent_service = null)
    {
        if ($this->isTrafficBlockPackage($package)) {
            // Traffic blocks are one-shot audit services. Editing, suspending, or
            // canceling the Blesta record must not modify the remote block.
            return null;
        }

        // Set unset checkboxes
        $checkbox_fields = [];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

        $this->Input->setRules($this->getServiceRules($vars, true, $package));
        if (!$this->Input->validates($vars)) {
            return;
        }

        // Only update the service if 'use_module' is true
        if (($vars['use_module'] ?? 'true') == 'true') {
            // we need the api
            if ($module_row = $this->getModuleRow()) {
                $config_options = $vars['configoptions'] ?? [];
                $resource_data = $this->applyJournaledResourceOptions(
                    $module_row,
                    $service,
                    $service_fields,
                    $package,
                    $config_options
                );
                if (!empty($resource_data['errors']['err_msg'])) {
                    $this->Input->setErrors([
                        'api' => ['response' => $resource_data['errors']['err_msg']]
                    ]);
                    return;
                }
                foreach ($resource_data['service_fields'] as $field => $value) {
                    $vars[$field] = $value;
                }
                
                $data = $this->adjustIpAddresses($module_row, $service_fields, $vars, $package);

                if (!empty($data['errors']['err_msg'])) {
                    // if not staff override error
                    // since removing is not possible from this page
                    // give user some guidance
                    if (!isset($vars['staff_id'])) {
                        $this->Input->setErrors(['Internal' => [ 'Error' => 'You cannot remove IPs from this tab, please try again from IP Addresses tab' ] ]);
                    } else {
                        $this->Input->setErrors(['api' => ['response' => $data['errors']['err_msg']]]);
                    }

                    return;
                }

                // Reset vars with the canonical network snapshot.
                foreach ([self::PRIMARY_IPV4_FIELD, self::SECONDARY_IPV4_FIELD] as $network_field) {
                    if (isset($data['service_fields']->{$network_field})) {
                        $vars[$network_field] = $data['service_fields']->{$network_field};
                    }
                }

                $data = $this->applyConfigurableServerOptions($module_row, $service_fields, $vars);
                if (!empty($data['errors']['err_msg'])) {
                    $this->Input->setErrors(['api' => ['response' => $data['errors']['err_msg']]]);
                    return;
                }

                foreach ($data['service_fields'] as $field => $value) {
                    $vars[$field] = $value;
                }
            }
        }

        // Return all service fields because Blesta replaces, rather than merges,
        // module metadata after editService(). Operation journals are removed only
        // after the whole edit has completed successfully.
        $fields = [
            'virtfusion_public_label',
            'virtfusion_server_id',
            'virtfusion_hostname',
            'virtfusion-os_template',
            'virtfusion_password',
            self::PRIMARY_IPV4_FIELD,
            self::SECONDARY_IPV4_FIELD,
            'virtfusion_ipv6_cidr',
            'virtfusion_backup_plan_id',
            self::BUILD_STATE_FIELD,
            'virtfusion_cpu_throttle',
            'virtfusion_restart_required'
        ];
        $encrypted_fields = ['virtfusion_password'];
        $overrides = [];
        foreach ($fields as $field) {
            if (isset($vars[$field])) {
                $overrides[$field] = $vars[$field];
            } elseif (isset($service_fields->{$field})) {
                $overrides[$field] = $service_fields->{$field};
            } elseif (!isset($service_fields->{$field}) && $field === 'virtfusion_server_id') {
                $overrides[$field] = $service_fields->server_id ?? null;
            }
        }

        return $this->mergedServiceMeta(
            $service,
            array_filter($overrides, function ($value) {
                return $value !== null;
            }),
            $encrypted_fields,
            array_merge(
                [self::RESOURCE_CHANGE_OPERATION_FIELD, 'virtfusion_build_status'],
                self::LEGACY_NETWORK_FIELDS
            )
        );
    }

    /**
     * Suspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being suspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being suspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if ($this->isTrafficBlockPackage($package)) {
            return true;
        }

        if (($row = $this->getModuleRow())) {
            $api = $this->getApiFromRow($row);
            $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

            $api->loadCommand('virtfusion_server');

            try {
                $server_api = new VirtfusionServer($api);
                $request = $server_api->suspend($service_fields->virtfusion_server_id, []);

                $success = false;

                if (isset($request['info'])) {
                    if ($request['info']['http_code'] === 204) {
                        $success = true;
                    } else {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                    }
                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                }

                $this->log($row->meta->hostname . '| suspend', serialize($request), 'output', $success);

                if (!$success) {
                    return;
                }
                return true;
            } catch (\Throwable $e) {
                $this->setModuleActionException($row, 'suspend exception', $e);
                return;
            }
        }

        return null;
    }

    /**
     * Unsuspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being unsuspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being unsuspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if ($this->isTrafficBlockPackage($package)) {
            return true;
        }

        if (($row = $this->getModuleRow())) {
            $api = $this->getApiFromRow($row);
            $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

            $api->loadCommand('virtfusion_server');

            try {
                $server_api = new VirtfusionServer($api);
                $request = $server_api->unsuspend($service_fields->virtfusion_server_id, []);

                $success = false;

                if (isset($request['info'])) {
                    if ($request['info']['http_code'] === 204) {
                        $success = true;
                    } else {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                    }
                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                }

                $this->log($row->meta->hostname . '| unsuspend', serialize($request), 'output', $success);

                if (!$success) {
                    return;
                }
                return true;
            } catch (\Throwable $e) {
                $this->setModuleActionException($row, 'unsuspend exception', $e);
                return;
            }
        }

        return null;
    }

    /**
     * Cancels the service on the remote server. Sets Input errors on failure,
     * preventing the service from being canceled.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being canceled (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        if ($this->isTrafficBlockPackage($package)) {
            return true;
        }

        if (($row = $this->getModuleRow())) {
            $api = $this->getApiFromRow($row);
            $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

            $api->loadCommand('virtfusion_server');

            try {
                $server_api = new VirtfusionServer($api);
                $request = $server_api->cancel($service_fields->virtfusion_server_id, []);

                $success = false;

                if (isset($request['info'])) {
                    if ($request['info']['http_code'] === 204) {
                        $success = true;
                    } else {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                    }
                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                }

                $this->log($row->meta->hostname . '| cancel', serialize($request), 'output', $success);

                if (!$success) {
                    return;
                }
                return true;
            } catch (\Throwable $e) {
                $this->setModuleActionException($row, 'cancel exception', $e);
                return;
            }
        }
        return null;
    }

    /**
     * Attempts to validate service info. This is the top-level error checking method. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @return bool True if the service validates, false otherwise. Sets Input errors when false.
     */
    public function validateService($package, ?array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars, false, $package));
        if (!$this->Input->validates($vars)) {
            return false;
        }

        if (!$this->isTrafficBlockPackage($package)) {
            $config_options = $vars['configoptions'] ?? [];
            foreach ([
                'hypervisorId',
                'ipv4',
                self::ADDITIONAL_IPV4_OPTION,
                'storage',
                'traffic',
                self::ADDITIONAL_TRAFFIC_OPTION,
                'memory',
                'cpuCores',
                'storageProfile',
                'networkProfile',
                'additionalStorage1Profile',
                'additionalStorage2Profile',
                'additionalStorage1Capacity',
                'additionalStorage2Capacity',
                'backupPlanId',
                'cpuThrottle'
            ] as $option_name) {
                if (($option_name === self::ADDITIONAL_IPV4_OPTION
                        && isset($config_options['ipv4']) && $config_options['ipv4'] !== '')
                    || ($option_name === self::ADDITIONAL_TRAFFIC_OPTION
                        && isset($config_options['traffic']) && $config_options['traffic'] !== '')) {
                    continue;
                }
                if (isset($config_options[$option_name])
                    && $config_options[$option_name] !== ''
                    && !is_numeric($config_options[$option_name])) {
                    $this->Input->setErrors([
                        'configoptions' => [
                            $option_name => Language::_(
                                'VirtfusionDirectProvisioningMod.!error.configoption.numeric',
                                true,
                                $option_name
                            )
                        ]
                    ]);
                    return false;
                }
            }

            if (isset($config_options['ipv4']) && $config_options['ipv4'] !== ''
                && (int) $config_options['ipv4'] < max(1, (int) $package->meta->default_ipv4)) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'ipv4' => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.ipv4.package_minimum',
                            true
                        )
                    ]
                ]);
                return false;
            }

            if (isset($config_options['hypervisorId']) && $config_options['hypervisorId'] !== ''
                && (int) $config_options['hypervisorId'] < 1) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'hypervisorId' => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.hypervisor.minimum',
                            true
                        )
                    ]
                ]);
                return false;
            }

            if (isset($config_options[self::ADDITIONAL_IPV4_OPTION])
                && $config_options[self::ADDITIONAL_IPV4_OPTION] !== ''
                && (!isset($config_options['ipv4']) || $config_options['ipv4'] === '')
                && (int) $config_options[self::ADDITIONAL_IPV4_OPTION] < 0) {
                $this->Input->setErrors([
                    'configoptions' => [
                        self::ADDITIONAL_IPV4_OPTION => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.ipv4.additional_minimum',
                            true
                        )
                    ]
                ]);
                return false;
            }

            if (isset($config_options[self::ADDITIONAL_TRAFFIC_OPTION])
                && $config_options[self::ADDITIONAL_TRAFFIC_OPTION] !== ''
                && (!isset($config_options['traffic']) || $config_options['traffic'] === '')
                && (int) $config_options[self::ADDITIONAL_TRAFFIC_OPTION] < 0) {
                $this->Input->setErrors([
                    'configoptions' => [
                        self::ADDITIONAL_TRAFFIC_OPTION => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum',
                            true
                        )
                    ]
                ]);
                return false;
            }

            if (isset($config_options['traffic']) && $config_options['traffic'] !== ''
                && ((int) $config_options['traffic'] < 0 || (int) $config_options['traffic'] > 999999999)) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'traffic' => Language::_(
                            (int) $config_options['traffic'] < 0
                                ? 'VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum'
                                : 'VirtfusionDirectProvisioningMod.!error.configoption.traffic.maximum',
                            true
                        )
                    ]
                ]);
                return false;
            }

            if (isset($config_options['memory']) && $config_options['memory'] !== ''
                && (int) $config_options['memory'] < 256) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'memory' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.memory.minimum', true)
                    ]
                ]);
                return false;
            }

            if (isset($config_options['cpuCores']) && $config_options['cpuCores'] !== ''
                && ((int) $config_options['cpuCores'] < 1 || (int) $config_options['cpuCores'] > 600)) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'cpuCores' => Language::_(
                            (int) $config_options['cpuCores'] < 1
                                ? 'VirtfusionDirectProvisioningMod.!error.configoption.cpu.minimum'
                                : 'VirtfusionDirectProvisioningMod.!error.configoption.cpu.maximum',
                            true
                        )
                    ]
                ]);
                return false;
            }

            foreach (['networkSpeed', 'networkSpeedInbound', 'networkSpeedOutbound'] as $option_name) {
                if (isset($config_options[$option_name]) && $config_options[$option_name] !== '') {
                    $network_speed = $this->parseNetworkSpeed($config_options[$option_name]);
                    if ($network_speed !== null && $network_speed >= 0) {
                        continue;
                    }
                    $this->Input->setErrors([
                        'configoptions' => [
                            $option_name => Language::_(
                                'VirtfusionDirectProvisioningMod.!error.configoption.network_speed.format',
                                true
                            )
                        ]
                    ]);
                    return false;
                }
            }

            if (isset($config_options['cpuThrottle']) && $config_options['cpuThrottle'] !== ''
                && ((int) $config_options['cpuThrottle'] < 0 || (int) $config_options['cpuThrottle'] > 99)) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'cpuThrottle' => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.cpu_throttle.range',
                            true
                        )
                    ]
                ]);
                return false;
            }

            $os_template = $config_options['operatingSystemId'] ?? null;
            if ($this->shouldAutoBuild($config_options)
                && (!is_numeric($os_template) || (int) $os_template < 1)) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'operatingSystemId' => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.os_template.required',
                            true
                        )
                    ]
                ]);
                return false;
            }
            return true;
        }

        $pricing = $this->getPackagePricing($package, $vars['pricing_id'] ?? null);
        if (!$pricing || ($pricing->period ?? null) !== 'onetime') {
            $this->Input->setErrors([
                'traffic_block' => [
                    'pricing' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.onetime', true)
                ]
            ]);
            return false;
        }

        $amount = $this->getTrafficBlockAmount($vars['configoptions'] ?? []);
        if (!$amount || $amount < 1) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'amount' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.amount', true)
                ]
            ]);
            return false;
        }

        return true;
    }

    /**
     * Attempts to validate an existing service against a set of service info updates. Sets Input errors on failure.
     *
     * @param stdClass $service A stdClass object representing the service to validate for editing
     * @param array $vars An array of user-supplied info to satisfy the request
     * @return bool True if the service update validates or false otherwise. Sets Input errors when false.
     */
    public function validateServiceEdit($service, ?array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars, true, null));
        if (!$this->Input->validates($vars)) {
            return false;
        }

        if (($vars['use_module'] ?? 'true') !== 'true') {
            return true;
        }

        $config_options = $vars['configoptions'] ?? [];
        foreach ([
            'autoBuild',
            'operatingSystemId',
            'sshKeys',
            'ipv6',
            'email',
            'swap',
            'hypervisorId',
            'networkSpeed',
            'networkSpeedInbound',
            'networkSpeedOutbound',
            'storageProfile',
            'networkProfile',
            'firewallRulesets',
            'hypervisorAssetGroups',
            'additionalStorage1Enable',
            'additionalStorage2Enable',
            'additionalStorage1Profile',
            'additionalStorage2Profile',
            'additionalStorage1Capacity',
            'additionalStorage2Capacity'
        ] as $option_name) {
            if (array_key_exists($option_name, $config_options)
                && !$this->configOptionValuesEqual(
                    $config_options[$option_name],
                    $this->getServiceConfigOptionValue($service, $option_name)
                )) {
                $this->Input->setErrors([
                    'configoptions' => [
                        $option_name => Language::_(
                            'VirtfusionDirectProvisioningMod.!error.configoption.create_only',
                            true,
                            $option_name
                        )
                    ]
                ]);
                return false;
            }
        }

        foreach (['memory', 'cpuCores', 'traffic', self::ADDITIONAL_TRAFFIC_OPTION, 'storage', 'ipv4', self::ADDITIONAL_IPV4_OPTION] as $option_name) {
            if (($option_name === self::ADDITIONAL_IPV4_OPTION
                    && isset($config_options['ipv4']) && $config_options['ipv4'] !== '')
                || ($option_name === self::ADDITIONAL_TRAFFIC_OPTION
                    && isset($config_options['traffic']) && $config_options['traffic'] !== '')) {
                continue;
            }
            if (isset($config_options[$option_name])
                && $config_options[$option_name] !== ''
                && !is_numeric($config_options[$option_name])) {
                $this->Input->setErrors([
                    'configoptions' => [
                        $option_name => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.numeric', true, $option_name)
                    ]
                ]);
                return false;
            }
        }

        if (isset($config_options['memory']) && $config_options['memory'] !== ''
            && (int) $config_options['memory'] < 256) {
            $this->Input->setErrors([
                'configoptions' => [
                    'memory' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.memory.minimum', true)
                ]
            ]);
            return false;
        }

        if (isset($config_options['cpuCores']) && $config_options['cpuCores'] !== ''
            && (int) $config_options['cpuCores'] < 1) {
            $this->Input->setErrors([
                'configoptions' => [
                    'cpuCores' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.cpu.minimum', true)
                ]
            ]);
            return false;
        }
        if (isset($config_options['cpuCores']) && $config_options['cpuCores'] !== ''
            && (int) $config_options['cpuCores'] > 600) {
            $this->Input->setErrors([
                'configoptions' => [
                    'cpuCores' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.cpu.maximum', true)
                ]
            ]);
            return false;
        }

        if (isset($config_options['traffic']) && $config_options['traffic'] !== ''
            && (int) $config_options['traffic'] < 0) {
            $this->Input->setErrors([
                'configoptions' => [
                    'traffic' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum', true)
                ]
            ]);
            return false;
        }
        if (isset($config_options['traffic']) && $config_options['traffic'] !== ''
            && (int) $config_options['traffic'] > 999999999) {
            $this->Input->setErrors([
                'configoptions' => [
                    'traffic' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.traffic.maximum', true)
                ]
            ]);
            return false;
        }
        if (isset($config_options[self::ADDITIONAL_TRAFFIC_OPTION])
            && $config_options[self::ADDITIONAL_TRAFFIC_OPTION] !== ''
            && (!isset($config_options['traffic']) || $config_options['traffic'] === '')
            && (int) $config_options[self::ADDITIONAL_TRAFFIC_OPTION] < 0) {
            $this->Input->setErrors([
                'configoptions' => [
                    self::ADDITIONAL_TRAFFIC_OPTION => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum', true)
                ]
            ]);
            return false;
        }

        if (isset($config_options['ipv4']) && $config_options['ipv4'] !== ''
            && (int) $config_options['ipv4'] < 1) {
            $this->Input->setErrors([
                'configoptions' => [
                    'ipv4' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.ipv4.minimum', true)
                ]
            ]);
            return false;
        }
        if (isset($config_options[self::ADDITIONAL_IPV4_OPTION])
            && $config_options[self::ADDITIONAL_IPV4_OPTION] !== ''
            && (!isset($config_options['ipv4']) || $config_options['ipv4'] === '')
            && (int) $config_options[self::ADDITIONAL_IPV4_OPTION] < 0) {
            $this->Input->setErrors([
                'configoptions' => [
                    self::ADDITIONAL_IPV4_OPTION => Language::_(
                        'VirtfusionDirectProvisioningMod.!error.configoption.ipv4.additional_minimum',
                        true
                    )
                ]
            ]);
            return false;
        }

        $requested_network_speed = $this->requestedNetworkSpeed($config_options);
        if ($requested_network_speed !== null
            && (string) $requested_network_speed !== (string) $this->currentNetworkSpeed($service)) {
            $this->Input->setErrors([
                'configoptions' => [
                    self::NETWORK_SPEED_OPTION => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.network_speed.edit', true)
                ]
            ]);
            return false;
        }
        foreach (['networkSpeedInbound', 'networkSpeedOutbound'] as $option_name) {
            if (array_key_exists($option_name, $config_options)
                && (string) $config_options[$option_name]
                    !== (string) $this->getServiceConfigOptionValue($service, $option_name)) {
                $this->Input->setErrors([
                    'configoptions' => [
                        $option_name => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.network_speed.edit', true)
                    ]
                ]);
                return false;
            }
        }

        $package_changed = isset($vars['pricing_id']) && $vars['pricing_id'] != $service->pricing_id;
        if (!array_key_exists('storage', $config_options) && !$package_changed) {
            return true;
        }

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        if (!isset($service_fields->virtfusion_server_id)) {
            return true;
        }

        $row = $this->getModuleRow($service->module_row_id ?? null);
        if (!$row) {
            $this->Input->setErrors([
                'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.module_row.missing', true)]
            ]);
            return false;
        }

        $api = $this->getApiFromRow($row);
        $server_request = $api->get_query('servers/' . $service_fields->virtfusion_server_id);
        if (!$this->apiRequestSucceeded($server_request, [200])) {
            $this->Input->setErrors([
                'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)]
            ]);
            return false;
        }

        $server_data = json_decode($server_request['response']);
        $current_storage = $this->getPrimaryStorage($server_data);
        $package_storage = null;
        if ($package_changed) {
            Loader::loadModels($this, ['Packages']);
            $target_package = $this->Packages->getByPricingId($vars['pricing_id']);
            $target_package_id = $target_package
                ? ($target_package->meta->package_id ?? null)
                : null;

            if (!$target_package_id) {
                $this->Input->setErrors([
                    'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.package.target', true)]
                ]);
                return false;
            }

            $package_request = $api->get_query('packages/' . $target_package_id);
            if (!$this->apiRequestSucceeded($package_request, [200])) {
                $this->Input->setErrors([
                    'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.package.target', true)]
                ]);
                return false;
            }

            $package_data = json_decode($package_request['response']);
            $package_storage = isset($package_data->data->primaryStorage)
                ? (int) $package_data->data->primaryStorage
                : null;
        }

        if (isset($config_options['storage']) && $config_options['storage'] !== '' && $current_storage !== null) {
            $requested_storage = (int) $config_options['storage'];
            if ($requested_storage < $current_storage) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'storage' => Language::_('VirtfusionDirectProvisioningMod.!error.storage.downgrade', true)
                    ]
                ]);
                return false;
            }

            if ($requested_storage > $current_storage) {
                $this->Input->setErrors([
                    'configoptions' => [
                        'storage' => Language::_('VirtfusionDirectProvisioningMod.!error.storage.package_required', true)
                    ]
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the rule set for adding/editing a service
     *
     * @param array $vars A list of input vars
     * @param bool $edit True to get the edit rules, false for the add rules
     * @return array Service rules
     */
    private function getServiceRules(?array $vars = null, $edit = false, $package = null)
    {
        // Validate the service fields
        $rules = [
            'virtfusion_server_id' => [
                'valid' => [
                    'if_set' => $edit,
                    'rule' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.server_id.valid', true)
                ]
            ],
            'label' => [
                'valid' => [
                    'if_set' => $edit,
                    'rule' => true,
                    'message' => Language::_('VirtfusionDirectProvisioningMod.!error.label.valid', true)
                ]
            ]
        ];

        if (!$edit
            && ($package === null || !$this->isTrafficBlockPackage($package))
            && ($package === null || $this->shouldAutoBuild($vars['configoptions'] ?? []))) {
            $rules['virtfusion_hostname'] = [
                'valid' => [
                    'rule' => [[$this, 'validateHostname']],
                    'message' => Language::_('VirtfusionDirectProvisioningMod.client.!error.host.valid', true)
                ]
            ];
        }

        return $rules;
    }

    /**
     * Updates the package for the service on the remote server. Sets Input
     * errors on failure, preventing the service's package from being changed.
     *
     * @param stdClass $package_from A stdClass object representing the current package
     * @param stdClass $package_to A stdClass object representing the new package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being changed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function changeServicePackage(
        $package_from,
        $package_to,
        $service,
        $parent_package = null,
        $parent_service = null
    ) {
        if ($this->isTrafficBlockPackage($package_from)
            || $this->isTrafficBlockPackage($package_to)) {
            $this->Input->setErrors([
                'traffic_block' => [
                    'immutable' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.immutable', true)
                ]
            ]);
            return;
        }

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

        if (($row = $this->getModuleRow()) && isset($service_fields->virtfusion_server_id)) {
            $api = $this->getApiFromRow($row);
            $server_id = $service_fields->virtfusion_server_id;

            try {
                $api->loadCommand('virtfusion_server');
                $server_api = new VirtfusionServer($api);

                $server_info = $api->get_query("servers/$server_id");
                $this->log(
                    $row->meta->hostname . '| client get server',
                    serialize($server_info),
                    'output',
                    $this->apiRequestSucceeded($server_info, [200])
                );

                if ($this->apiRequestSucceeded($server_info, [200])) {
                    $server_data = json_decode($server_info['response']);
                    $primary_storage = $this->getPrimaryStorage($server_data);

                    $new_pkg_id = $package_to->meta->package_id;
                    $pkg_response = $api->get_query("packages/$new_pkg_id");
                    $this->log(
                        $row->meta->hostname . '| client get pkg',
                        serialize($pkg_response),
                        'output',
                        $this->apiRequestSucceeded($pkg_response, [200])
                    );

                    if (!$this->apiRequestSucceeded($pkg_response, [200])) {
                        $this->Input->setErrors([
                            'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.package.target', true)]
                        ]);
                        return null;
                    }

                    $pkg_data = json_decode($pkg_response['response']);
                    $new_primary_storage = isset($pkg_data->data->primaryStorage)
                        ? (int) $pkg_data->data->primaryStorage
                        : null;

                    if ($primary_storage === null || $new_primary_storage === null) {
                        $this->Input->setErrors([
                            'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)]
                        ]);
                        return null;
                    }

                    $api->loadCommand('virtfusion_server');
                    $server_api = new VirtfusionServer($api);

                    $package_changes = [
                        'backupPlan' => $this->getServiceConfigOptionValue(
                            $service,
                            self::BACKUP_PLAN_OPTION
                        ) === null,
                        'cpu' => $this->getServiceConfigOptionValue($service, 'cpuCores') === null,
                        'memory' => $this->getServiceConfigOptionValue($service, 'memory') === null,
                        'primaryDiskReadIOPS' => true,
                        'primaryDiskReadThroughput' => true,
                        'primaryDiskSize' => $this->getServiceConfigOptionValue($service, 'storage') === null
                            && $new_primary_storage >= $primary_storage,
                        'primaryDiskWriteIOPS' => true,
                        'primaryDiskWriteThroughput' => true,
                        'primaryNetworkInboundSpeed' => $this->currentNetworkSpeed($service) === null
                            && $this->getServiceConfigOptionValue($service, 'networkSpeedInbound') === null,
                        'primaryNetworkOutboundSpeed' => $this->currentNetworkSpeed($service) === null
                            && $this->getServiceConfigOptionValue($service, 'networkSpeedOutbound') === null,
                        'primaryNetworkTraffic' => $this->getServiceConfigOptionValue($service, 'traffic') === null
                            && $this->getServiceConfigOptionValue($service, self::ADDITIONAL_TRAFFIC_OPTION) === null
                    ];
                    $server_pkg_data = $server_api->changePkg($server_id, $new_pkg_id, $package_changes);
                    $server_response = json_decode($server_pkg_data['response'] ?? '');
                    $this->log(
                        $row->meta->hostname . '| client upgrade server',
                        serialize($server_pkg_data),
                        'output',
                        $this->apiRequestSucceeded($server_pkg_data, [200])
                    );

                    if (!$this->apiRequestSucceeded($server_pkg_data, [200])) {
                        $msg = isset($server_response->errors)
                            ? implode('<br />', $server_response->errors)
                            : Language::_('VirtfusionDirectProvisioningMod.!error.package.change', true);

                        $this->Input->setErrors(['api' => ['response' => $msg]]);
                        return null;
                    }

                    $vf_pkg_request = $server_api->getPkg( $package_from->meta->package_id );
                    $vf_pkg_data_from = json_decode((string) ($vf_pkg_request['response'] ?? ''));

                    $package_traffic_from = (int)($vf_pkg_data_from->data->traffic);
                    $current_traffic = (int)($server_data->data->settings->resources->traffic ?? 0);

                    if ($current_traffic > $package_traffic_from) {
                        $additional_traffic = $current_traffic - $package_traffic_from;
                        $traffic_result = $this->updateTraffic(
                            $row,
                            $service_fields,
                            $package_to,
                            $additional_traffic
                        );
                        if (!empty($traffic_result['errors']['err_msg'])) {
                            $this->Input->setErrors([
                                'api' => ['response' => $traffic_result['errors']['err_msg']]
                            ]);
                            return null;
                        }
                    }

                    return $this->mergedServiceMeta(
                        $service,
                        ['virtfusion_restart_required' => 'true'],
                        [],
                        [self::RESOURCE_CHANGE_OPERATION_FIELD]
                    );
                } else {
                    $this->Input->setErrors([
                        'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)]
                    ]);
                    return null;
                }
            } catch (\Throwable $e) {
                $this->Input->setErrors(['api' => ['response' => print_r($e->getMessage(), true)]]);
                return null;
            }
        }

        return null;
    }


    /**
     * Fetches the HTML content to display when viewing the service info in the
     * admin interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getAdminServiceInfo($service, $package)
    {
        $row = $this->getModuleRow();

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View(
            $this->isTrafficBlockPackage($package) ? 'traffic_block_service_info' : 'admin_service_info',
            'default'
        );
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Date', 'Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('is_admin', true);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $this->view->set('service_fields', $service_fields);
        if (!$this->isTrafficBlockPackage($package)) {
            $this->view->set('ip_data', $this->getClientIpAddresses(
                $package,
                $service,
                null,
                null,
                false,
                $service_fields
            ));
        }

        return $this->view->fetch();
    }

    /**
     * Returns all tabs to display to a client when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title.
     *  Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getClientTabs($package)
    {
        if ($this->isTrafficBlockPackage($package)) {
            return [];
        }

        return [
            'tabManage' => Language::_('VirtfusionDirectProvisioningMod.tabManage', true)
        ];
    }

    public function getAdminTabs($package)
    {
        if ($this->isTrafficBlockPackage($package)) {
            return [
                'tabAdminTrafficBlock' => Language::_(
                    'VirtfusionDirectProvisioningMod.traffic_block.reconcile',
                    true
                )
            ];
        }

        return [
            'tabAdminManage' => Language::_('VirtfusionDirectProvisioningMod.tabManage', true)
        ];
    }

    public function tabAdminTrafficBlock(
        $package,
        $service,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null
    ) {
        $this->view = new View('tab_admin_traffic_block', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(
            'components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS
        );
        Loader::loadHelpers($this, ['Date', 'Form', 'Html']);

        $message = null;
        $post = $post ?? [];
        if (!empty($post['confirm_absent'])) {
            $operation = $this->serviceOperationState(
                $service->id,
                self::TRAFFIC_BLOCK_OPERATION_FIELD
            );
            $row = $this->getModuleRow($service->module_row_id ?? null);
            if (!$operation || !$row || ($operation['status'] ?? null) !== 'provisioning_unknown') {
                $this->Input->setErrors([
                    'traffic_block' => [
                        'reconcile' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.reconcile', true)
                    ]
                ]);
            } else {
                try {
                    $lock = $this->acquireOperationLock(
                        'traffic',
                        $row->id,
                        $operation['server_id']
                    );
                } catch (\Throwable $e) {
                    $lock = null;
                }

                if (!$lock) {
                    $this->Input->setErrors([
                        'traffic_block' => [
                            'locked' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.locked', true)
                        ]
                    ]);
                } else {
                    try {
                        $server_api = $this->getServerApiFromRow($row);
                        $request = $server_api->getTrafficBlocks($operation['server_id']);
                        if (!$this->apiRequestSucceeded($request, [200])) {
                            $this->Input->setErrors([
                                'api' => ['response' => $this->apiErrorMessage($request)]
                            ]);
                        } else {
                            $data = json_decode((string) $request['response']);
                            $assigned = (array) ($data->data->assigned ?? []);
                            $matches = $this->findNewTrafficBlocks(
                                $operation['before_ids'] ?? [],
                                $assigned,
                                $operation['month'],
                                $operation['amount']
                            );
                            if (count($matches) === 1) {
                                $operation['status'] = 'completed';
                                $operation['block_id'] = $matches[0]->id;
                                $operation['updated_at'] = date('c');
                                $this->persistServiceOperationState(
                                    $service->id,
                                    self::TRAFFIC_BLOCK_OPERATION_FIELD,
                                    $operation
                                );
                                $message = Language::_(
                                    'VirtfusionDirectProvisioningMod.traffic_block.reconcile_found',
                                    true
                                );
                            } elseif (count($matches) > 1) {
                                $this->Input->setErrors([
                                    'traffic_block' => [
                                        'ambiguous' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.ambiguous', true)
                                    ]
                                ]);
                            } elseif ((int) ($data->data->available->current->month ?? 0)
                                !== (int) $operation['month']) {
                                $this->Input->setErrors([
                                    'traffic_block' => [
                                        'expired' => Language::_('VirtfusionDirectProvisioningMod.!error.traffic_block.period_changed', true)
                                    ]
                                ]);
                            } else {
                                $operation['status'] = 'retry_confirmed';
                                $operation['confirmed_at'] = date('c');
                                $operation['updated_at'] = date('c');
                                if ($this->persistServiceOperationState(
                                    $service->id,
                                    self::TRAFFIC_BLOCK_OPERATION_FIELD,
                                    $operation
                                )) {
                                    $message = Language::_(
                                        'VirtfusionDirectProvisioningMod.traffic_block.retry_confirmed',
                                        true
                                    );
                                }
                            }
                        }
                    } finally {
                        $this->releaseOperationLock($lock);
                    }
                }
            }
        }

        $operation = $this->serviceOperationState(
            $service->id,
            self::TRAFFIC_BLOCK_OPERATION_FIELD
        );
        $this->view->set('service', $service);
        $this->view->set('operation', $operation);
        $this->view->set('message', $message);
        return $this->view->fetch();
    }

    private function getRemoteServerInfo($module_row, $server_id)
    {
        $server_api = $this->getServerApiFromRow($module_row);
        $request = $server_api->get($server_id, true);

        if (!isset($request['info']) || $request['info']['http_code'] !== 200) {
            return null;
        }

        $data = json_decode($request['response']);
        $server_info = new stdClass();
        $server_info->built = isset($data->data->built) && $data->data->built !== null ? 1 : 0;
        $server_info->name = $data->data->name ?? null;
        $server_info->cpu = $data->data->settings->resources->cpuCores ?? null;
        $server_info->disk = $data->data->settings->resources->storage ?? null;
        $server_info->memory = $data->data->settings->resources->memory ?? null;
        $server_info->traffic = $data->data->traffic->public->currentPeriod->limit ?? ($data->data->settings->resources->traffic ?? null);
        $server_info->traffic_reset = $data->data->traffic->public->currentPeriod->end ?? null;
        $server_info->ipv4 = $data->data->network->interfaces[0]->ipv4[0]->address ?? null;
        $server_info->ipv6 = $data->data->network->interfaces[0]->ipv6[0]->subnet ?? null;
        $remote_state = isset($data->data->remoteState) && is_object($data->data->remoteState)
            ? $data->data->remoteState
            : null;
        $server_info->power = $remote_state->running ?? null;
        $server_info->status = $remote_state->state ?? ($data->data->state ?? null);
        $server_info->network_in = $this->formatNetworkSpeed(
            $data->data->network->interfaces[0]->inAverage ?? null
        );
        $server_info->network_out = $this->formatNetworkSpeed(
            $data->data->network->interfaces[0]->outAverage ?? null
        );
        $backup_plan = $data->data->backupPlan ?? null;
        $server_info->backup_plan = is_object($backup_plan)
            ? ($backup_plan->name ?? $backup_plan->id ?? null)
            : ($data->data->settings->backupPlan ?? null);

        $active_tasks = $data->data->tasks->active ?? [];
        $pending_tasks = $data->data->tasks->actions->pending ?? [];
        $server_info->has_active_tasks = !empty($active_tasks);
        $server_info->has_pending_tasks = !empty($pending_tasks);
        $server_info->tasks_active = $server_info->has_active_tasks || $server_info->has_pending_tasks;
        $server_info->pending_tasks = [];
        foreach ($pending_tasks as $task) {
            $server_info->pending_tasks[] = $task->action ?? ('Task #' . ($task->id ?? '?'));
        }

        $traffic_request = $server_api->getTraffic($server_id);
        if ($this->apiRequestSucceeded($traffic_request, [200])) {
            $traffic_data = json_decode($traffic_request['response']);
            $current_month = $this->selectCurrentTrafficMonth(
                (array) ($traffic_data->data->monthly ?? []),
                $server_info->traffic_reset
            );
            if ($current_month) {
                $server_info->traffic = $current_month->limit ?? $server_info->traffic;
                $server_info->traffic_reset = $current_month->end ?? $server_info->traffic_reset;
                $server_info->traffic_used = round(((float) ($current_month->total ?? 0)) / 1000000000, 2);
                $server_info->traffic_blocks = 0;
                foreach (($current_month->blocks ?? []) as $block) {
                    $server_info->traffic_blocks += (int) ($block->traffic ?? 0);
                }
                $server_info->traffic_percent = (int) $server_info->traffic > 0
                    ? min(100, round(($server_info->traffic_used / (int) $server_info->traffic) * 100, 1))
                    : null;
            }
        }

        $backup_request = $server_api->getBackups($server_id);
        $server_info->backup_count = 0;
        $server_info->latest_backup = null;
        if ($this->apiRequestSucceeded($backup_request, [200])) {
            $backup_data = json_decode($backup_request['response']);
            $backups = (array) ($backup_data->data ?? []);
            usort($backups, function ($left, $right) {
                return strtotime($right->created ?? '') <=> strtotime($left->created ?? '');
            });
            $server_info->backup_count = count($backups);
            $server_info->latest_backup = $backups[0] ?? null;
        }

        return $server_info;
    }

    private function refreshServiceNetworkFields($service, $package, $service_fields)
    {
        $service_fields = $this->normalizeLegacyServiceFields($service_fields);

        if (!isset($service_fields->virtfusion_server_id) || !is_numeric($service_fields->virtfusion_server_id)) {
            return $service_fields;
        }

        $module_row = $this->getModuleRow($package->module_row) ?: $this->getModuleRow();
        if (!$module_row) {
            return $service_fields;
        }

        $api = $this->getApiFromRow($module_row);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);
        $request = $server_api->get($service_fields->virtfusion_server_id);

        if (!isset($request['info']) || $request['info']['http_code'] !== 200) {
            return $service_fields;
        }

        $server_data = json_decode($request['response']);
        $ip_addresses = [];
        foreach (($server_data->data->network->interfaces[0]->ipv4 ?? []) as $ip) {
            if (!empty($ip->address)) {
                $ip_addresses[] = $ip->address;
            }
        }

        if (empty($ip_addresses) && !isset($server_data->data->network->interfaces[0]->ipv6[0])) {
            return $service_fields;
        }

        $network_fields = [
            self::PRIMARY_IPV4_FIELD => $ip_addresses[0] ?? '',
            self::SECONDARY_IPV4_FIELD => implode(',', array_slice($ip_addresses, 1))
        ];

        if (isset($server_data->data->network->interfaces[0]->ipv6[0])) {
            $ipv6 = $server_data->data->network->interfaces[0]->ipv6[0];
            $network_fields['virtfusion_ipv6_cidr'] = $ipv6->subnet . '/' . $ipv6->cidr;
        }

        Loader::loadModels($this, ['Services']);
        foreach ($network_fields as $key => $value) {
            $service_fields->{$key} = $value;
            $this->Services->editField($service->id, [
                'key' => $key,
                'value' => $value,
                'encrypted' => false
            ]);
        }

        if (!$this->Services->errors()) {
            $this->Record->from('service_fields')
                ->where('service_id', '=', $service->id)
                ->where('key', 'in', self::LEGACY_NETWORK_FIELDS)
                ->delete();
        }

        return $service_fields;
    }

    /**
     * Determines whether VirtFusion task state conflicts with a server action.
     *
     * Pending resource changes often require a power transition before
     * VirtFusion can apply them, so they must not prevent power actions. An
     * actively executing task still blocks every action to avoid submitting
     * competing operations. Non-power actions remain blocked by either state.
     *
     * @param string $action The requested server action
     * @param mixed $active_tasks VirtFusion's active task state
     * @param mixed $pending_tasks VirtFusion's pending task state
     * @return bool True when the action must be blocked
     */
    private function tasksBlockServerAction($action, $active_tasks, $pending_tasks)
    {
        if ($action === 'vnc_disable') {
            return false;
        }

        if (!empty($active_tasks)) {
            return true;
        }

        if (empty($pending_tasks)) {
            return false;
        }

        return !in_array($action, ['boot', 'restart', 'shutdown', 'poweroff'], true);
    }

    private function handleServerAction($module_row, $server_api, $service, $service_fields, array $post)
    {
        $server_id = $service_fields->virtfusion_server_id;
        $action = $post['action'] ?? 'manage';
        $server_uuid = null;

        if (in_array($action, ['boot', 'restart', 'shutdown', 'poweroff', 'resetpass', 'vnc', 'vnc_disable'], true)) {
            $state_request = $server_api->get($server_id, true);
            if ($this->apiRequestSucceeded($state_request, [200])) {
                $state_data = json_decode($state_request['response']);
                $candidate_uuid = (string) ($state_data->data->uuid ?? '');
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $candidate_uuid)) {
                    $server_uuid = $candidate_uuid;
                }
                $active = $state_data->data->tasks->active ?? [];
                $pending = $state_data->data->tasks->actions->pending ?? [];
                if ($this->tasksBlockServerAction($action, $active, $pending)) {
                    $this->Input->setErrors([
                        'api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.tasks.pending', true)]
                    ]);
                    return null;
                }
            }
        }

        switch ($action) {
            case 'manage':
                $request = $server_api->fetchToken($server_id, $service->client_id, []);

                if (isset($request['info'])) {
                    $this->log(
                        $module_row->meta->hostname . '| api token',
                        'HTTP ' . (int) $request['info']['http_code'],
                        'output',
                        $request['info']['http_code'] == 200
                    );
                    if ($request['info']['http_code'] === 200) {
                        $data = json_decode($request['response']);

                        header('Location: https://' . $module_row->meta->hostname . $data->data->authentication->endpoint_complete);
                        die();
                    }
                }

                $this->Input->setErrors([['We couldn\'t log you in. Something went wrong.']]);
                return null;

            case 'boot':
            case 'restart':
            case 'shutdown':
            case 'poweroff':
                $request = $server_api->powerAction($server_id, $action);
                $success = isset($request['info']) && in_array($request['info']['http_code'], [200, 204]);
                $this->log($module_row->meta->hostname . '| power ' . $action, serialize($request), 'output', $success);

                if (!$success) {
                    $this->Input->setErrors(['api' => ['response' => 'The power action was unsuccessful.']]);
                    return null;
                }

                if ($action === 'restart') {
                    $this->setRestartRecommended($service->id, false);
                }

                return Language::_('VirtfusionDirectProvisioningMod.tabManage.action_success', true);

            case 'resetpass':
                $request = $server_api->resetPassword($server_id, [
                    'user' => $post['user'] ?? 'root',
                    'sendMail' => isset($post['sendMail']) ? $this->boolValue($post['sendMail']) : true
                ]);
                $success = isset($request['info']) && $request['info']['http_code'] == 200;
                $this->log(
                    $module_row->meta->hostname . '| reset password',
                    'HTTP ' . (int) ($request['info']['http_code'] ?? 0),
                    'output',
                    $success
                );

                if (!$success) {
                    $this->Input->setErrors(['api' => ['response' => 'The password reset was unsuccessful.']]);
                    return null;
                }

                $data = json_decode($request['response']);
                $password = (string) ($data->data->expectedPassword ?? '');
                if ($password === '') {
                    $this->Input->setErrors(['api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.password.missing', true)]]);
                    return null;
                }

                return [
                    'type' => 'password',
                    'password' => $password
                ];

            case 'vnc':
                $request = $server_api->setVnc($server_id, 'enable');
                $success = isset($request['info']) && $request['info']['http_code'] == 200;
                $this->log(
                    $module_row->meta->hostname . '| vnc action',
                    'HTTP ' . (int) ($request['info']['http_code'] ?? 0),
                    'output',
                    $success
                );

                if (!$success) {
                    $this->Input->setErrors(['api' => ['response' => 'The VNC action was unsuccessful.']]);
                    return null;
                }

                $data = json_decode($request['response']);
                $websocket_url = $this->vncWebSocketUrl(
                    $module_row,
                    $data->data->vnc->wss->url ?? null
                );
                $password = (string) ($data->data->vnc->password ?? '');
                if ($websocket_url && $password !== '') {
                    return [
                        'type' => 'vnc',
                        'websocket_url' => $websocket_url,
                        'password' => $password,
                        'console_url' => $server_uuid
                            ? 'https://' . $module_row->meta->hostname . '/server/' . rawurlencode($server_uuid) . '/vnc'
                            : null
                    ];
                }

                $this->Input->setErrors(['api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.vnc.details', true)]]);
                return null;

            case 'vnc_disable':
                $request = $server_api->setVnc($server_id, 'disable');
                $success = isset($request['info']) && $request['info']['http_code'] == 200;
                $this->log(
                    $module_row->meta->hostname . '| vnc disable',
                    'HTTP ' . (int) ($request['info']['http_code'] ?? 0),
                    'output',
                    $success
                );

                if (!$success) {
                    $this->Input->setErrors(['api' => ['response' => Language::_('VirtfusionDirectProvisioningMod.!error.vnc.disable', true)]]);
                    return null;
                }

                return Language::_('VirtfusionDirectProvisioningMod.tabManage.vnc_closed', true);
        }

        return null;
    }

    /**
     * tabManage
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabManage(
        $package,
        $service,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null
    ) {
        $this->view = new View('tabManage', 'default');
        $this->view->base_uri = $this->base_uri;

        Loader::loadHelpers($this, ['Date', 'Form', 'Html']);

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $message = null;
        $action_result = null;
        $server_info = null;
        $row = $this->getModuleRow();
        $post = !empty($post) ? $post : $_POST;

        if (!empty($post) && $row) {
            if (($post['action'] ?? null) === 'refresh_ips') {
                $service_fields = $this->refreshServiceNetworkFields($service, $package, $service_fields);
                $message = Language::_('VirtfusionDirectProvisioningMod.ipAddresses.refreshed', true);
            } elseif (($post['action'] ?? null) === 'refresh_state') {
                $message = Language::_('VirtfusionDirectProvisioningMod.tabManage.state_refreshed', true);
            } elseif (($post['action'] ?? null) === 'remove_ip') {
                $error = $this->removeIPAddress($package, $service, $post, true);
                if ($error) {
                    $this->Input->setErrors(['api' => ['response' => $error]]);
                } else {
                    $service_fields = $this->refreshServiceNetworkFields($service, $package, $service_fields);
                    $message = Language::_('VirtfusionDirectProvisioningMod.ipAddresses.removed', true);
                }
            } elseif (property_exists($service_fields, 'virtfusion_server_id')) {
                if (is_numeric($service_fields->virtfusion_server_id)) {
                    $api = $this->getApiFromRow($row);

                    $api->loadCommand('virtfusion_server');

                    $server_api = new VirtfusionServer($api);
                    $result = $this->handleServerAction($row, $server_api, $service, $service_fields, $post ?? []);
                    if (is_array($result)) {
                        $action_result = $result;
                        if (($result['type'] ?? null) === 'vnc') {
                            $this->setMessage(
                                'success',
                                Language::_('VirtfusionDirectProvisioningMod.vnc.ready', true)
                            );
                        }
                    } else {
                        $message = $result;
                    }
                }
            }
        }

        if ($row && property_exists($service_fields, 'virtfusion_server_id') && is_numeric($service_fields->virtfusion_server_id)) {
            $server_info = $this->getRemoteServerInfo($row, $service_fields->virtfusion_server_id);
            if ($server_info) {
                $this->view->set('server_info', $server_info);
            }
        }

        $this->view->set('message', $message);
        $this->view->set('action_result', $action_result);
        $this->view->set('ip_data', $this->getClientIpAddresses($package, $service, null, null, true, $service_fields));
        $this->view->set('service_fields', $service_fields);
        $this->view->set('package', $package);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('vars', new stdClass());

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);
        return $this->view->fetch();
    }

    public function tabAdminManage(
        $package,
        $service,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null
    ) {
        $this->view = new View('tabAdminManage', 'default');

        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Date', 'Form', 'Html']);

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $message = null;
        $action_result = null;
        $server_info = null;
        $row = $this->getModuleRow();
        $post = !empty($post) ? $post : $_POST;

        if (!empty($post) && $row) {
            if (($post['action'] ?? null) === 'refresh_ips') {
                $service_fields = $this->refreshServiceNetworkFields($service, $package, $service_fields);
                $message = Language::_('VirtfusionDirectProvisioningMod.ipAddresses.refreshed', true);
            } elseif (($post['action'] ?? null) === 'refresh_state') {
                $message = Language::_('VirtfusionDirectProvisioningMod.tabManage.state_refreshed', true);
            } elseif (($post['action'] ?? null) === 'remove_ip') {
                $error = $this->removeIPAddress($package, $service, $post, false);
                if ($error) {
                    $this->Input->setErrors(['api' => ['response' => $error]]);
                } else {
                    $service_fields = $this->refreshServiceNetworkFields($service, $package, $service_fields);
                    $message = Language::_('VirtfusionDirectProvisioningMod.ipAddresses.removed', true);
                }
            } elseif (property_exists($service_fields, 'virtfusion_server_id')) {
                if (is_numeric($service_fields->virtfusion_server_id)) {
                    $api = $this->getApiFromRow($row);

                    $api->loadCommand('virtfusion_server');

                    $server_api = new VirtfusionServer($api);
                    $result = $this->handleServerAction($row, $server_api, $service, $service_fields, $post ?? []);
                    if (is_array($result)) {
                        $action_result = $result;
                        if (($result['type'] ?? null) === 'vnc') {
                            $this->setMessage(
                                'success',
                                Language::_('VirtfusionDirectProvisioningMod.vnc.ready', true)
                            );
                        }
                    } else {
                        $message = $result;
                    }
                }
            }
        }

        if ($row && property_exists($service_fields, 'virtfusion_server_id') && is_numeric($service_fields->virtfusion_server_id)) {
            $server_info = $this->getRemoteServerInfo($row, $service_fields->virtfusion_server_id);
            if ($server_info) {
                $this->view->set('server_info', $server_info);
            }
        }

        $this->view->set('message', $message);
        $this->view->set('action_result', $action_result);
        $this->view->set('ip_data', $this->getClientIpAddresses($package, $service, null, null, false, $service_fields));
        $this->view->set('service_fields', $service_fields);
        $this->view->set('package', $package);
        $this->view->set(
            'admin_server_url',
            $row && isset($service_fields->virtfusion_server_id)
                ? $this->getAdminServerUrl($row, $service_fields->virtfusion_server_id)
                : null
        );
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('vars', new stdClass());

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);
        return $this->view->fetch();
    }

    /**
     * tabClientIPAddresses
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientIPAddresses(
        $package,
        $service,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null
    ) {
        header('Location: ' . $this->base_uri . 'services/manage/' . (int) $service->id . '/tabManage/');
        die();
    }

    /**
     * tabAdminIPAddresses
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabAdminIPAddresses(
        $package,
        $service,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null
    ) {
        header(
            'Location: /admin/clients/servicetab/'
            . (int) $service->client_id . '/' . (int) $service->id . '/tabAdminManage/'
        );
        die();
    }

    private function removeIPAddress($package, $service, $post, $client = false)
    {
        Loader::loadModels($this, ['Invoices', 'Services', 'ServiceChanges']);

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $module_row = $this->getModuleRow($service->module_row_id ?? null) ?: $this->getModuleRow();
        $ip_to_remove = trim((string) ($post['ip_address'] ?? ''));
        $address_groups = $this->ipv4AddressGroups($service_fields, $package);
        $extra_ips = $address_groups['extra'];

        if (!$module_row || empty($service_fields->virtfusion_server_id)
            || $ip_to_remove === '' || !in_array($ip_to_remove, $extra_ips, true)) {
            return Language::_('VirtfusionDirectProvisioningMod.ipAddresses.remove_invalid', true);
        }

        $target_option = null;
        foreach (($service->options ?? []) as $option) {
            if (!in_array($option->option_name ?? null, ['ipv4', self::ADDITIONAL_IPV4_OPTION], true)) {
                continue;
            }
            if ($client && (int) ($option->option_editable ?? 0) !== 1) {
                continue;
            }
            if (!$target_option || $option->option_name === self::ADDITIONAL_IPV4_OPTION) {
                $target_option = $option;
            }
        }
        if ($client && !$target_option) {
            return Language::_('VirtfusionDirectProvisioningMod.ipAddresses.remove_forbidden', true);
        }

        $server_api = $this->getServerApiFromRow($module_row);
        $request = $server_api->removeIpv4($service_fields->virtfusion_server_id, [$ip_to_remove]);
        $success = $this->apiRequestSucceeded($request, [204]);
        $this->log($module_row->meta->hostname . '| remove ipv4', serialize($request), 'output', $success);
        if (!$success) {
            return Language::_('VirtfusionDirectProvisioningMod.ipAddresses.remove_failed', true);
        }

        $new_extra_ips = array_values(array_diff($extra_ips, [$ip_to_remove]));
        $new_addresses = array_values(array_diff($address_groups['all'], [$ip_to_remove]));
        $new_total = count($new_addresses);
        foreach ([
            self::PRIMARY_IPV4_FIELD => $new_addresses[0] ?? '',
            self::SECONDARY_IPV4_FIELD => implode(',', array_slice($new_addresses, 1))
        ] as $key => $value) {
            $this->Services->editField($service->id, [
                'key' => $key,
                'value' => $value,
                'encrypted' => false
            ]);
        }
        if (!$this->Services->errors()) {
            $this->Record->from('service_fields')
                ->where('service_id', '=', $service->id)
                ->where('key', 'in', self::LEGACY_NETWORK_FIELDS)
                ->delete();
        }

        if ($target_option) {
            $options = [];
            foreach (($service->options ?? []) as $option) {
                $value = ($option->option_type ?? null) === 'quantity'
                    ? ($option->qty ?? 0)
                    : ($option->value ?? $option->option_value ?? '');
                if ((int) $option->option_id === (int) $target_option->option_id) {
                    $value = $target_option->option_name === self::ADDITIONAL_IPV4_OPTION
                        ? max(0, count($new_extra_ips))
                        : $new_total;
                }
                $options[$option->option_id] = $value;
            }

            $invoice_vars = $this->getIpChangeInvoiceVars($service, $options);
            if (!empty($invoice_vars)) {
                $this->Invoices->add($invoice_vars);
            }

            $edit_vars = [
                'virtfusion_server_id' => $service_fields->virtfusion_server_id,
                'configoptions' => $options,
                'use_module' => 'false'
            ];
            if (!empty($service_fields->virtfusion_hostname)) {
                $edit_vars['virtfusion_hostname'] = $service_fields->virtfusion_hostname;
            }
            $this->Services->edit($service->id, $edit_vars);
        }

        if ($this->Services->errors()) {
            return Language::_('VirtfusionDirectProvisioningMod.ipAddresses.update_failed', true);
        }

        return '';
    }

    /**
     * Gets the invoice lines for an ip change
     *
     * @param stdClass $service An object representing a service
     * @param array $options A list of configurable options including extra IPs
     * @return array A list of invoice vars
     */
    private function getIpChangeInvoiceVars($service, array $options)
    {
        $items = [];
        $serviceChange = $this->ServiceChanges->getPresenter(
            $service->id,
            ['configoptions' => $options, 'pricing_id' => $service->pricing_id, 'qty' => $service->qty]
        );
        if (!$serviceChange) {
            return [];
        }

        // Setup line items from each of the presenter's items
        foreach ($serviceChange->items() as $item) {
            // Tax has to be deconstructed since the presenter's tax amounts
            // cannot be passed along
            $items[] = [
                'qty' => $item->qty,
                'amount' => $item->price,
                'description' => $item->description,
                'tax' => !empty($item->taxes)
            ];
        }

        // Add a line item for each discount amount
        foreach ($serviceChange->discounts() as $discount) {
            // The total discount is the negated total
            $items[] = [
                'qty' => 1,
                'amount' => (-1 * $discount->total),
                'description' => $discount->description,
                'tax' => false
            ];
        }

        $invoice_vars = [];
        $total = $serviceChange->totals()->total;
        if ($total > 0) {
            // Invoice the service change
            $invoice_vars = [
                'client_id' => $service->client_id,
                'date_billed' => date('c'),
                'date_due' => date('c'),
                'currency' => $service->package_pricing->currency,
                'lines' => $items
            ];
        }

        return $invoice_vars;
    }

    private function updateTraffic($module_row, $service_fields, $package, $additional_traffic)
    {
        $api = $this->getApiFromRow($module_row);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);

        $vf_server_request = $server_api->get($service_fields->virtfusion_server_id);
        $vf_pkg_request = $server_api->getPkg($package->meta->package_id);
        if (!$this->apiRequestSucceeded($vf_server_request, [200])
            || !$this->apiRequestSucceeded($vf_pkg_request, [200])) {
            return [
                'errors' => [
                    'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)
                ]
            ];
        }

        $vf_server_data = json_decode($vf_server_request['response']);
        $vf_pkg_data = json_decode($vf_pkg_request['response']);
        $package_traffic = (int) ($vf_pkg_data->data->traffic ?? 0);
        $additional_traffic = (int)($additional_traffic ?? 0);

        return $this->updatePrimaryTraffic(
            $module_row,
            $service_fields,
            $package_traffic + $additional_traffic,
            $vf_server_data
        );
    }

    private function updatePrimaryTraffic($module_row, $service_fields, $traffic, $server_data = null)
    {
        if (!is_numeric($traffic) || (int) $traffic < 0) {
            return [
                'errors' => [
                    'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum', true)
                ]
            ];
        }

        $api = $this->getApiFromRow($module_row);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);
        $server_id = $service_fields->virtfusion_server_id;

        if ($server_data === null) {
            $server_request = $server_api->get($server_id);
            if (!$this->apiRequestSucceeded($server_request, [200])) {
                return [
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)
                    ]
                ];
            }
            $server_data = json_decode($server_request['response']);
        }

        $traffic = (int) $traffic;
        $current_traffic = (int) ($server_data->data->settings->resources->traffic ?? 0);
        if ($current_traffic === $traffic) {
            return ['errors' => ['err_msg' => '']];
        }

        $response = $server_api->modifyPrimaryTraffic($server_id, ['traffic' => $traffic]);
        $success = $this->apiRequestSucceeded($response, [200, 201, 204]);
        $this->log(
            $module_row->meta->hostname . '| modify traffic',
            serialize($response),
            'output',
            $success
        );

        return [
            'errors' => [
                'err_msg' => $success
                    ? ''
                    : Language::_('VirtfusionDirectProvisioningMod.!error.resource.traffic', true)
            ]
        ];
    }

    private function applyJournaledResourceOptions(
        $module_row,
        $service,
        $service_fields,
        $package,
        array $config_options
    ) {
        $requested = array_intersect_key(
            $config_options,
            array_flip(['traffic', self::ADDITIONAL_TRAFFIC_OPTION, 'memory', 'cpuCores'])
        );
        if (empty($requested) || empty($service_fields->virtfusion_server_id)) {
            return ['service_fields' => [], 'errors' => ['err_msg' => '']];
        }

        $server_id = (int) $service_fields->virtfusion_server_id;
        try {
            $lock = $this->acquireOperationLock('resources', $module_row->id, $server_id);
        } catch (\Throwable $e) {
            $lock = null;
        }
        if (!$lock) {
            return [
                'service_fields' => [],
                'errors' => [
                    'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.locked', true)
                ]
            ];
        }

        try {
            $server_api = $this->getServerApiFromRow($module_row);
            $server_request = $server_api->get($server_id);
            if (!$this->apiRequestSucceeded($server_request, [200])) {
                return [
                    'service_fields' => [],
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)
                    ]
                ];
            }

            $server_data = json_decode((string) $server_request['response']);
            $current = $server_data->data->settings->resources ?? new stdClass();
            $targets = [];
            if (array_key_exists('traffic', $requested)) {
                $targets['traffic'] = (int) $requested['traffic'];
            } elseif (array_key_exists(self::ADDITIONAL_TRAFFIC_OPTION, $requested)) {
                $package_request = $server_api->getPkg($package->meta->package_id);
                if (!$this->apiRequestSucceeded($package_request, [200])) {
                    return [
                        'service_fields' => [],
                        'errors' => [
                            'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)
                        ]
                    ];
                }
                $package_data = json_decode((string) $package_request['response']);
                $targets['traffic'] = (int) ($package_data->data->traffic ?? 0)
                    + (int) $requested[self::ADDITIONAL_TRAFFIC_OPTION];
            }
            if (isset($targets['traffic']) && $targets['traffic'] > 999999999) {
                return [
                    'service_fields' => [],
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.traffic.maximum', true)
                    ]
                ];
            }
            if (array_key_exists('memory', $requested) && $requested['memory'] !== '') {
                $targets['memory'] = (int) $requested['memory'];
            }
            if (array_key_exists('cpuCores', $requested) && $requested['cpuCores'] !== '') {
                $targets['cpuCores'] = (int) $requested['cpuCores'];
            }
            ksort($targets);

            $fingerprint = hash('sha256', json_encode([
                'service_id' => (int) $service->id,
                'module_row_id' => (int) $module_row->id,
                'server_id' => $server_id,
                'targets' => $targets
            ]));
            $operation = $this->serviceOperationState(
                $service->id,
                self::RESOURCE_CHANGE_OPERATION_FIELD
            );
            if ($operation
                && ($operation['fingerprint'] ?? null) !== $fingerprint
                && ($operation['status'] ?? null) !== 'completed') {
                return [
                    'service_fields' => [],
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.conflict', true)
                    ]
                ];
            }

            $operation = [
                'fingerprint' => $fingerprint,
                'service_id' => (int) $service->id,
                'module_row_id' => (int) $module_row->id,
                'server_id' => $server_id,
                'targets' => $targets,
                'applied' => $operation['applied'] ?? [],
                'status' => 'validated',
                'created_at' => $operation['created_at'] ?? date('c'),
                'updated_at' => date('c')
            ];
            if (!$this->persistServiceOperationState(
                $service->id,
                self::RESOURCE_CHANGE_OPERATION_FIELD,
                $operation
            )) {
                return [
                    'service_fields' => [],
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.operation.persist', true)
                    ]
                ];
            }

            $updated_fields = [];
            foreach (['traffic', 'memory', 'cpuCores'] as $resource) {
                if (!array_key_exists($resource, $targets)) {
                    continue;
                }

                $current_value = (int) ($current->{$resource} ?? 0);
                $target_value = (int) $targets[$resource];
                if ($current_value === $target_value) {
                    $operation['applied'][$resource] = true;
                    continue;
                }

                $operation['status'] = $resource . '_applying';
                $operation['updated_at'] = date('c');
                if (!$this->persistServiceOperationState(
                    $service->id,
                    self::RESOURCE_CHANGE_OPERATION_FIELD,
                    $operation
                )) {
                    return [
                        'service_fields' => $updated_fields,
                        'errors' => [
                            'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.operation.persist', true)
                        ]
                    ];
                }

                if ($resource === 'traffic') {
                    $request = $server_api->modifyPrimaryTraffic($server_id, ['traffic' => $target_value]);
                    $success = $this->apiRequestSucceeded($request, [200, 201, 204]);
                } elseif ($resource === 'memory') {
                    $request = $server_api->modifyMemory($server_id, $target_value);
                    $success = $this->apiRequestSucceeded($request, [201]);
                } else {
                    $request = $server_api->modifyCpuCores($server_id, $target_value);
                    $success = $this->apiRequestSucceeded($request, [201]);
                }
                $this->log(
                    $module_row->meta->hostname . '| modify ' . $resource,
                    serialize($request),
                    'output',
                    $success
                );

                if (!$success) {
                    $actual_request = $server_api->get($server_id);
                    $actual = null;
                    if ($this->apiRequestSucceeded($actual_request, [200])) {
                        $actual_data = json_decode((string) $actual_request['response']);
                        $actual = (array) ($actual_data->data->settings->resources ?? []);
                    }
                    $operation['status'] = 'partial_failure';
                    $operation['failed_step'] = $resource;
                    $operation['actual'] = $actual;
                    $operation['updated_at'] = date('c');
                    $this->persistServiceOperationState(
                        $service->id,
                        self::RESOURCE_CHANGE_OPERATION_FIELD,
                        $operation
                    );
                    return [
                        'service_fields' => $updated_fields,
                        'errors' => [
                            'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.partial', true)
                        ]
                    ];
                }

                $current->{$resource} = $target_value;
                $operation['applied'][$resource] = true;
                $operation['status'] = $resource . '_applied';
                $operation['updated_at'] = date('c');
                if (!$this->persistServiceOperationState(
                    $service->id,
                    self::RESOURCE_CHANGE_OPERATION_FIELD,
                    $operation
                )) {
                    return [
                        'service_fields' => $updated_fields,
                        'errors' => [
                            'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.operation.persist', true)
                        ]
                    ];
                }

                if (in_array($resource, ['memory', 'cpuCores'], true)) {
                    $updated_fields['virtfusion_restart_required'] = 'true';
                }
            }

            $operation['status'] = 'completed';
            $operation['updated_at'] = date('c');
            if (!$this->persistServiceOperationState(
                $service->id,
                self::RESOURCE_CHANGE_OPERATION_FIELD,
                $operation
            )) {
                return [
                    'service_fields' => $updated_fields,
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.operation.persist', true)
                    ]
                ];
            }

            return ['service_fields' => $updated_fields, 'errors' => ['err_msg' => '']];
        } finally {
            $this->releaseOperationLock($lock);
        }
    }

    private function applyConfigurableServerOptions($module_row, $service_fields, array $vars)
    {
        $updated_fields = [];
        $err_msg = '';

        if (empty($vars['configoptions']) || !isset($service_fields->virtfusion_server_id)) {
            return [
                'service_fields' => $updated_fields,
                'errors' => ['err_msg' => $err_msg]
            ];
        }

        $server_api = $this->getServerApiFromRow($module_row);
        $server_id = $service_fields->virtfusion_server_id;

        if (!$err_msg && isset($vars['configoptions'][self::BACKUP_PLAN_OPTION])) {
            $backup_plan_id = trim((string) $vars['configoptions'][self::BACKUP_PLAN_OPTION]);

            if ($backup_plan_id !== '' && is_numeric($backup_plan_id)) {
                $request = $server_api->setBackupPlan($server_id, $backup_plan_id);
                $this->log($module_row->meta->hostname . '| backup plan', serialize($request), 'output', in_array($request['info']['http_code'], [200, 201, 204]));

                if (!in_array($request['info']['http_code'], [200, 201, 204])) {
                    $err_msg = 'There was an error while updating the backup plan.';
                } else {
                    $updated_fields['virtfusion_backup_plan_id'] = $backup_plan_id;
                }
            }
        }

        if (!$err_msg && isset($vars['configoptions'][self::CPU_THROTTLE_OPTION])) {
            $percent = trim((string) $vars['configoptions'][self::CPU_THROTTLE_OPTION]);

            if ($percent !== '' && is_numeric($percent)) {
                $request = $server_api->modifyCpuThrottle($server_id, ['percent' => (int) $percent]);
                $this->log($module_row->meta->hostname . '| cpu throttle', serialize($request), 'output', in_array($request['info']['http_code'], [200, 201, 204]));

                if (!in_array($request['info']['http_code'], [200, 201, 204])) {
                    $err_msg = 'There was an error while updating CPU throttle.';
                } else {
                    $updated_fields['virtfusion_cpu_throttle'] = (int) $percent;
                }
            }
        }

        return [
            'service_fields' => $updated_fields,
            'errors' => ['err_msg' => $err_msg]
        ];
    }

    /**
     *
     *
     * @param stdClass $service_fields A stdClass object representing the current service_fields
     * @param array $vars An array of user supplied info to satisfy the request
     * @return null
     */
    private function adjustIpAddresses($module_row, $service_fields, $vars, $package)
    {
        $edit_qty = 0;
        $ips_to_remove = [];
        $new_extra_ips = [];
        $err_msg = '';

        $has_ipv4_change = (isset($vars['configoptions']['ipv4'])
                && $vars['configoptions']['ipv4'] !== '')
            || (isset($vars['configoptions'][self::ADDITIONAL_IPV4_OPTION])
                && $vars['configoptions'][self::ADDITIONAL_IPV4_OPTION] !== '');
        if (!$has_ipv4_change) {
            return [
                'service_fields' => $service_fields,
                'errors' => ['err_msg' => '']
            ];
        }

        // Get and load api
        $api = $this->getApiFromRow($module_row);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);

        // Prefer the current remote assignment so a stale Blesta snapshot does
        // not cause duplicate additions or incorrect removals.
        $remote_request = $server_api->get($service_fields->virtfusion_server_id);
        if ($this->apiRequestSucceeded($remote_request, [200])) {
            $remote_data = json_decode($remote_request['response']);
            $remote_addresses = [];
            foreach (($remote_data->data->network->interfaces[0]->ipv4 ?? []) as $ip) {
                if (!empty($ip->address)) {
                    $remote_addresses[] = $ip->address;
                }
            }
            if (!empty($remote_addresses)) {
                $service_fields->{self::PRIMARY_IPV4_FIELD} = $remote_addresses[0];
                $service_fields->{self::SECONDARY_IPV4_FIELD} = implode(',', array_slice($remote_addresses, 1));
            }
        }

        $address_groups = $this->ipv4AddressGroups($service_fields, $package);
        $extra_ips = $address_groups['extra'];
        $current_qty = count($extra_ips);

        $base_qty = max(1, (int) ($package->meta->default_ipv4 ?? 1));
        $current_total = max($base_qty, count($address_groups['all']));

        // The ipv4 configurable option is the absolute API quantity. Convert
        // it to the additional-address count used by the IP management UI.
        $edit_total = $current_total;
        if (isset($vars['configoptions']['ipv4']) && $vars['configoptions']['ipv4'] !== '') {
            $edit_total = (int) $vars['configoptions']['ipv4'];
        } elseif (isset($vars['configoptions'][self::ADDITIONAL_IPV4_OPTION])
            && $vars['configoptions'][self::ADDITIONAL_IPV4_OPTION] !== '') {
            $edit_total = $base_qty + (int) $vars['configoptions'][self::ADDITIONAL_IPV4_OPTION];
        }
        if ($edit_total < $base_qty) {
            return [
                'service_fields' => $service_fields,
                'errors' => [
                    'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.ipv4.package_minimum', true)
                ]
            ];
        }
        $edit_qty = max(0, $edit_total - $base_qty);

        if (isset($vars['virtfusion_extra_ip_to_remove'])) {
            $ips_to_remove = $vars['virtfusion_extra_ip_to_remove'];
        }

        if ($current_qty < $edit_qty) {
            $diff_qty = $edit_qty - $current_qty;

            $request = $server_api->addIpv4Qty($service_fields->virtfusion_server_id, $diff_qty);
            $this->log($module_row->meta->hostname, serialize($request), 'output', $request['info']['http_code'] == '200');
            $response = json_decode($request['response'], true);

            if ($request['info']['http_code'] != '200') {
                $err_msg = 'There was an error while adding IP Addresses';
            } else {
                $new_extra_ips = array_merge($extra_ips, $response['data']);
            }
        } elseif ($current_qty > $edit_qty) {
            // REMOVE
            $diff_qty = $current_qty - $edit_qty;

            // check to make sure we removing same ammount
            // as ips we have to remove
            if ($diff_qty == count($ips_to_remove)) {
                $new_extra_ips = array_diff($extra_ips, $ips_to_remove);

                $request = $server_api->removeIpv4($service_fields->virtfusion_server_id, $ips_to_remove);
                $this->log($module_row->meta->hostname, serialize($request), 'output', $request['info']['http_code'] == '204');
                if ($request['info']['http_code'] != '204') {
                    $err_msg = 'There was an error while removing IP Addresses';
                }
            } else {
                $err_msg = 'Extra IP addresses to be removed did not match number of IPs being removed!';
            }
        }

        if (empty($err_msg) && $current_qty != $edit_qty) {
            $new_addresses = array_merge(
                array_slice($address_groups['all'], 0, $base_qty),
                array_values($new_extra_ips)
            );
            $service_fields->{self::PRIMARY_IPV4_FIELD} = $new_addresses[0] ?? '';
            $service_fields->{self::SECONDARY_IPV4_FIELD} = implode(',', array_slice($new_addresses, 1));
        }

        return [
            'service_fields' => $service_fields,
            'errors' => [
                'err_msg' => $err_msg
            ]
        ];
    }

    /**
     * Builds the IP-address card data for the client and admin Manage pages.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param bool $client True if the action is being performed by the client, false otherwise
     * @return array An array of vars for the template
     */
    private function getClientIpAddresses(
        $package,
        $service,
        ?array $get = null,
        ?array $post = null,
        $client = false,
        $service_fields = null
    )
    {
        Loader::loadModels($this, ['Services']);

        // Get the service fields
        $service_fields = $service_fields ?: $this->normalizeLegacyServiceFields(
            $this->serviceFieldsToObject($service->fields)
        );
        $address_groups = $this->ipv4AddressGroups($service_fields, $package);
        $option_addable = false;

        // determine if we can add more IPs
        foreach (($service->options ?? []) as $option) {
            if (in_array($option->option_name, ['ipv4', self::ADDITIONAL_IPV4_OPTION], true)) {
                $option_addable = $option->option_addable;
            }
        }

        $ipv6 = $this->csvValues($service_fields->virtfusion_ipv6_cidr ?? null);

        // Determine whether the service option for custom IPs is editable by the client
        $option_editable = !$client;
        if ($client) {
            foreach (($service->options ?? []) as $option) {
                if (in_array($option->option_name, ['ipv4', self::ADDITIONAL_IPV4_OPTION], true)) {
                    $option_editable = ($option->option_editable == 1);
                    break;
                }
            }
        }

        // for consistency make it an opject
        return (object) [
            'ip_addresses' => [
                'main' => $address_groups['main'],
                'base' => $address_groups['base'],
                'extra' => $address_groups['extra'],
                'ipv6' => $ipv6,
            ],
            'editable_options' => [
                'main' => false,
                'base' => false,
                'extra' => $option_editable,
                'ipv6' => false
            ],
            'addable' => $option_addable
        ];
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the
     * client interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getClientServiceInfo($service, $package)
    {
        $row = $this->getModuleRow();

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View(
            $this->isTrafficBlockPackage($package) ? 'traffic_block_service_info' : 'client_service_info',
            'default'
        );
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Date', 'Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('is_admin', false);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $this->view->set('service_fields', $service_fields);
        if (!$this->isTrafficBlockPackage($package)) {
            $this->view->set('ip_data', $this->getClientIpAddresses(
                $package,
                $service,
                null,
                null,
                true,
                $service_fields
            ));
        }

        return $this->view->fetch();
    }


    /** Simple function to get user add/edit avail actions */
    private function getServiceOption($package_id, $service_name)
    {
        $options = [];

        Loader::loadModels($this, ['PackageOptions']);
        $package_options = $this->PackageOptions->getByPackageId($package_id);

        foreach ($package_options as $option) {
            if ($option->name == $service_name) {
                $options['addable'] = $option->addable;
                $options['editable'] = $option->editable;
            }
        }

        return $options;
    }

    private function provisioningOptionVisibilityHtml($package_id, $fallback_auto_build, $editing = false)
    {
        Loader::loadModels($this, ['PackageOptions']);
        $always_hidden_ids = [];
        $build_option_ids = [];
        $auto_build_ids = [];
        $build_option_names = [
            'operatingSystemId',
            'sshKeys',
            'ipv6',
            'email',
            'swap'
        ];
        $create_only_names = array_merge($build_option_names, [
            self::AUTO_BUILD_OPTION,
            'hypervisorId',
            self::NETWORK_SPEED_OPTION,
            'networkSpeedInbound',
            'networkSpeedOutbound',
            'storage',
            'storageProfile',
            'networkProfile',
            'firewallRulesets',
            'hypervisorAssetGroups',
            'additionalStorage1Enable',
            'additionalStorage2Enable',
            'additionalStorage1Profile',
            'additionalStorage2Profile',
            'additionalStorage1Capacity',
            'additionalStorage2Capacity'
        ]);
        foreach ($this->PackageOptions->getByPackageId($package_id) as $option) {
            $name = $option->name ?? null;
            if ($name === 'vnc' || ($editing && in_array($name, $create_only_names, true))) {
                $always_hidden_ids[] = (int) $option->id;
            } elseif (in_array($name, $build_option_names, true)) {
                $build_option_ids[] = (int) $option->id;
            } elseif ($name === self::AUTO_BUILD_OPTION) {
                $auto_build_ids[] = (int) $option->id;
            }
        }

        return '<script type="text/javascript">(function () {'
            . 'var alwaysHidden=' . json_encode($always_hidden_ids) . ';'
            . 'var buildOptions=' . json_encode($build_option_ids) . ';'
            . 'var autoBuildOptions=' . json_encode($auto_build_ids) . ';'
            . 'var fallbackAutoBuild=' . ($fallback_auto_build ? 'true' : 'false') . ';'
            . 'function selector(id){return "[name=\\"configoptions["+id+"]\\"],"'
            . '+"[name=\\"configoptions["+id+"][]\\"]";}'
            . 'function boolValue(value){return ["1","true","yes","on"].indexOf(String(value).toLowerCase())!==-1;}'
            . 'function autoBuildEnabled(){var enabled=fallbackAutoBuild;var found=false;'
            . 'autoBuildOptions.some(function(id){var fields=document.querySelectorAll(selector(id));'
            . 'if(!fields.length){return false;}found=true;var first=fields[0];'
            . 'if(first.type==="radio"){fields.forEach(function(field){if(field.checked){enabled=boolValue(field.value);}});}'
            . 'else if(first.type==="checkbox"){enabled=first.checked&&boolValue(first.value);}'
            . 'else{enabled=boolValue(first.value);}return true;});return found?enabled:fallbackAutoBuild;}'
            . 'function setVisible(ids,visible,marker){ids.forEach(function(id){'
            . 'document.querySelectorAll(selector(id)).forEach(function(field){'
            . 'var container=field.closest(".mb-3, .form-group");'
            . 'if(!visible){field.disabled=true;field.dataset[marker]="1";'
            . 'if(container){container.style.display="none";container.dataset[marker]="1";}}'
            . 'else{if(field.dataset[marker]==="1"){field.disabled=false;delete field.dataset[marker];}'
            . 'if(container&&container.dataset[marker]==="1"){container.style.display="";delete container.dataset[marker];}}'
            . '});});}'
            . 'function update(){var autoBuild=autoBuildEnabled();setVisible(alwaysHidden,false,"vfAlwaysHidden");'
            . 'setVisible(buildOptions,autoBuild,"vfAutoHidden");'
            . 'var hostname=document.getElementById("virtfusion_hostname");if(hostname){'
            . 'var container=hostname.closest(".mb-3, .form-group");hostname.disabled=!autoBuild;hostname.required=autoBuild;'
            . 'if(container){container.style.display=autoBuild?"":"none";}}}'
            . 'document.addEventListener("change",function(event){if(event.target&&event.target.name'
            . '&&event.target.name.indexOf("configoptions[")===0){update();}});'
            . 'update();'
            . 'new MutationObserver(update).observe(document.documentElement,{childList:true,subtree:true});'
            . '}());</script>';
    }

    /**
     * Returns all fields to display to an admin attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getAdminAddFields($package, $vars = null)
    {

        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        if ($this->isTrafficBlockPackage($package)) {
            return $fields;
        }

        $hostname_field = $fields->label(Language::_('VirtfusionDirectProvisioningMod.option_fields.hostname.label', true), 'hostname');
        $hostname_field->attach(
            $fields->fieldText(
                'virtfusion_hostname',
                $this->Html->ifSet($vars->virtfusion_hostname),
                ['id' => 'virtfusion_hostname', 'required' => 'required']
            )
        );
        // Set the field
        $fields->setField($hostname_field);
        unset($hostname_field);

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>" . $this->getHostnameValidationJS() . '</script>
        ' . $this->provisioningOptionVisibilityHtml(
            $package->id,
            false
        ));

        return $fields;
    }

    /**
     * Returns all fields to display to an admin attempting to edit a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        if ($this->isTrafficBlockPackage($package)) {
            return $fields;
        }

        $normalized_vars = $this->normalizeLegacyServiceFields($vars ?: new stdClass());
        // Existing services keep their edit layout unless their saved build
        // state explicitly identifies a no-build service.
        $form_auto_build = (($normalized_vars->{self::BUILD_STATE_FIELD} ?? null) !== 'skipped');
        $hidden_options = $this->provisioningOptionVisibilityHtml($package->id, $form_auto_build, true);
        $hostname_field = $fields->label(Language::_('VirtfusionDirectProvisioningMod.option_fields.hostname.label', true), 'hostname');
        $hostname_field->attach(
            $fields->fieldText(
                'virtfusion_hostname',
                $this->Html->ifSet($vars->virtfusion_hostname),
                ['id' => 'virtfusion_hostname', 'required' => 'required']
            )
        );
        $fields->setField($hostname_field);
        unset($hostname_field);

        // Set the Server ID field
        $server_id = $fields->label(Language::_('VirtfusionDirectProvisioningMod.service_fields.server_id', true), 'virtfusion_direct_provisioning_mod_server_id');
        $server_id->attach(
            $fields->fieldText(
                'virtfusion_server_id',
                ($vars->virtfusion_server_id ?? null),
                ['id' => 'virtfusion_direct_provisioning_mod_server_id']
            )
        );
        $fields->setField($server_id);

        $extra_ips = [];
        $ip_options = $this->ipv4AddressGroups($normalized_vars, $package)['extra'];
        if (!empty($ip_options)) {
            // set ips as keys and values;
            $extra_ips = array_combine($ip_options, $ip_options);
        }

        $service_options = $this->getServiceOption($package->id, 'ipv4');
        if (empty($service_options)) {
            $service_options = $this->getServiceOption($package->id, self::ADDITIONAL_IPV4_OPTION);
        }
        if (!empty($service_options)) {
            $extra_ip_addresses = $fields->label(Language::_('VirtfusionDirectProvisioningMod.option_fields.extra_ip_addresses', true), 'virtfusion_direct_provisioning_mod_extra_ip_addresses');
            $extra_ip_addresses->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioningMod.option_fields.extra_ip_addresses.tooltip', true)));
            $extra_ip_addresses->attach(
                $fields->fieldMultiSelect(
                    'virtfusion_extra_ip_to_remove[]',
                    $extra_ips,
                    ['id' => 'virtfusion_extra_ip_to_remove']
                )
            );
            $fields->setField($extra_ip_addresses);
        }

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>" . $this->getHostnameValidationJS() . '</script>
        ' . $hidden_options);

        return $fields;
    }

    /**
     * Returns all fields to display to a client attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getClientAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        if ($this->isTrafficBlockPackage($package)) {
            return $fields;
        }

        // Create field label
        $hostname_field = $fields->label(Language::_('VirtfusionDirectProvisioningMod.option_fields.hostname.label', true), 'hostname');
        // Create field and attach to label
        // Add a tooltip next to this field
        $tooltip = $fields->tooltip(Language::_('VirtfusionDirectProvisioningMod.option_fields.hostname.tooltip', true));
        $hostname_field->attach($tooltip);

        $hostname_field->attach(
            $fields->fieldText(
                'virtfusion_hostname',
                $this->Html->ifSet($vars->virtfusion_hostname),
                ['id' => 'virtfusion_hostname', 'required' => 'required']
            )
        );
        // Set the field
        $fields->setField($hostname_field);

        $service_options = $this->getServiceOption($package->id, 'ipv4');
        if (empty($service_options)) {
            $service_options = $this->getServiceOption($package->id, self::ADDITIONAL_IPV4_OPTION);
        }
        if (!empty($service_options) && $service_options['addable'] == '1') {
        }

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>" . $this->getHostnameValidationJS() . '</script>
        ' . $this->provisioningOptionVisibilityHtml(
            $package->id,
            false
        ));

        return $fields;
    }

    public function getClientEditFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        if (!$this->isTrafficBlockPackage($package)) {
            $normalized_vars = $this->normalizeLegacyServiceFields($vars ?: new stdClass());
            $form_auto_build = (($normalized_vars->{self::BUILD_STATE_FIELD} ?? null) !== 'skipped');
            $fields->setHtml($this->provisioningOptionVisibilityHtml(
                $package->id,
                $form_auto_build,
                true
            ));
        }
        return $fields;
    }

    /**
     * Returns an array of key values for fields stored for a module, package,
     * and service under this module, used to substitute those keys with their
     * actual module, package, or service meta values in related emails.
     *
     * @return array A multi-dimensional array of key/value pairs where each key
     *  is one of 'module', 'package', or 'service' and each value is a numerically
     *  indexed array of key values that match meta fields under that category.
     * @see Modules::addModuleRow()
     * @see Modules::editModuleRow()
     * @see Modules::addPackage()
     * @see Modules::editPackage()
     * @see Modules::addService()
     * @see Modules::editService()
     */
    public function getEmailTags()
    {
        return [
            'module' => [],
            'package' => [],
            'service' => [
                'virtfusion_public_label',
                'virtfusion_server_id',
                'virtfusion_hostname',
                'virtfusion_password',
                self::PRIMARY_IPV4_FIELD,
                self::SECONDARY_IPV4_FIELD,
                'virtfusion_ipv6_cidr',
                'virtfusion_parent_server_id',
                'virtfusion_traffic_block_gb',
                'virtfusion_traffic_block_month',
                'virtfusion_traffic_block_start',
                'virtfusion_traffic_block_end',
                'virtfusion_traffic_block_id'
            ]
        ];
    }

    /**
     * Validates that the given hostname is valid
     *
     * @param string $host_name The host name to validate
     * @return bool True if the hostname is valid, false otherwise
     */
    public function validateHostname($host_name)
    {
        if (strlen($host_name) > 255) {
            return false;
        }

        $octet = '([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])';
        $nested_octet = '(\.' . $octet . ')';
        $hostname_regex = '/^' . $octet . $nested_octet . $nested_octet . '+$/i';

        $valid = $this->Input->matches($host_name, $hostname_regex);

        return $valid;
    }

    /**
     * Similar to @VirtfusionDirectProvisioningMod:validateHostname
     * but for validating on the front end
     */
    private function getHostnameValidationJS()
    {
        $str = "
            $(document).ready(function() {
                $('#virtfusion_hostname').focusout(function() {
                    const hostname = $(this).val()
                    const regex_str = /^([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])(\.([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]))(\.([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]))+$/i
                    if (!regex_str.test(hostname)) {
                        alert('" . Language::_('VirtfusionDirectProvisioningMod.client.!error.host.valid', true) . "')
                        $(this).addClass('cst_error')
                    }
                }).focusin(function() {
                    $(this).removeClass('cst_error');
                });
            })";

        return $str;
    }
}
