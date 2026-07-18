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
    private const PORT_SPEED_OPTIONS = [
        'virtfusion-port_speed',
        'virtfusion_port_speed',
        'port_speed'
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

    private function getApi($api_token, $hostname)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'virtfusion_api.php');

        return new VirtfusionApi($api_token, $hostname);
    }

    private function shouldAutoBuild($package)
    {
        return !isset($package->meta->{'virtfusion-auto_build'})
            || $package->meta->{'virtfusion-auto_build'} !== 'false';
    }

    private function boolValue($value)
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
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
            'storage',
            'traffic',
            'memory',
            'cpuCores',
            'networkSpeedInbound',
            'networkSpeedOutbound',
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

        foreach (self::PORT_SPEED_OPTIONS as $option_name) {
            if (isset($config_options[$option_name]) && $config_options[$option_name] !== ''
                && is_numeric($config_options[$option_name])) {
                $port_speed = (int) $config_options[$option_name];
                if ($port_speed >= 0) {
                    $request_params['networkSpeedInbound'] = $port_speed;
                    $request_params['networkSpeedOutbound'] = $port_speed;
                }
                break;
            }
        }

        return $request_params;
    }

    private function normalizeLegacyServiceFields($service_fields)
    {
        if (!isset($service_fields->virtfusion_server_id) && isset($service_fields->server_id)) {
            $service_fields->virtfusion_server_id = $service_fields->server_id;
        }

        return $service_fields;
    }

    private function apiRequestSucceeded($request, array $status_codes)
    {
        return isset($request['info']['http_code'])
            && in_array((int) $request['info']['http_code'], $status_codes, true);
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

    private function requestedPortSpeed(array $config_options)
    {
        foreach (self::PORT_SPEED_OPTIONS as $option_name) {
            if (array_key_exists($option_name, $config_options)) {
                return $config_options[$option_name];
            }
        }

        return null;
    }

    private function currentPortSpeed($service)
    {
        foreach (self::PORT_SPEED_OPTIONS as $option_name) {
            $value = $this->getServiceConfigOptionValue($service, $option_name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
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

    private function getAdminServerUrl($module_row, $server_id)
    {
        $template = trim((string) ($module_row->meta->admin_server_url ?? ''));
        if ($template === '') {
            $template = 'https://{hostname}/admin/servers/{server_id}';
        }

        return str_replace(
            ['{hostname}', '{server_id}'],
            [$module_row->meta->hostname, (int) $server_id],
            $template
        );
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

        return [
            'success' => true,
            'module_id' => $destination_module->id,
            'message' => Language::_(
                $sync_from_official
                    ? 'VirtfusionDirectProvisioningMod.sync.success.from_official'
                    : 'VirtfusionDirectProvisioningMod.sync.success.to_official',
                true
            )
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
    }

    private function upgrade1_0_1($row)
    {
        $api_token;
        $hostname;
        $module_row_id;

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
        $server_id = null;

        foreach ($service->fields as $field) {
            if ($field->key == 'virtfusion_hostname') {
                return $field->value;
            }

            if (in_array($field->key, ['virtfusion_server_id', 'server_id'], true)) {
                $server_id = $field->value;
            }
        }

        return $server_id !== null ? 'VirtFusion #' . $server_id : parent::getServiceName($service);
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
        $meta_fields = ['name', 'hostname', 'api_token', 'admin_server_url', 'traffic_blocks_enabled'];
        $encrypted_fields = ['api_token'];

        // Set unset checkboxes
        $checkbox_fields = ['traffic_blocks_enabled'];

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
        $meta_fields = ['name', 'hostname', 'api_token', 'admin_server_url', 'traffic_blocks_enabled'];
        $encrypted_fields = ['api_token'];

        // Set unset checkboxes
        $checkbox_fields = ['traffic_blocks_enabled'];

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
            ]
        ];

        return $rules;
    }

    // ping server to make sure we have valid host and api token
    public function validateApiCredentials($api_token, $vars)
    {
        try {
            $api = $this->getApi($vars['api_token'], $vars['hostname']);
            $request = $api->get_query('packages');

            if ($request['info']['http_code'] != 200) {
                $msg =  ($request['response']) ? json_decode($request['response']) : 'Invalid API Token';
                $this->log($vars['hostname'], serialize($msg), 'output', false);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
            // Trap any errors encountered, could not validate connection
        }
        return false;
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
    public function addPackage(array $vars = null)
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
    public function editPackage($package, array $vars = null)
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
        // Validate the package fields
        $rules = [
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

        $backup_plan_id = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.backup_plan_id', true), 'virtfusion_direct_provisioning_mod_backup_plan_id');
        $backup_plan_id->attach(
            $fields->fieldText(
                'meta[virtfusion-backup_plan_id]',
                ($vars->meta['virtfusion-backup_plan_id'] ?? null),
                ['id' => 'virtfusion_direct_provisioning_mod_backup_plan_id']
            )
        );
        $backup_plan_id->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioningMod.package_fields.backup_plan_id.help_text', true), 'virtfusion_direct_provisioning_mod_backup_plan_id'));
        $fields->setField($backup_plan_id);

        $auto_build = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.auto_build', true), 'virtfusion_direct_provisioning_mod_auto_build');
        $auto_build->attach(
            $fields->fieldSelect(
                'meta[virtfusion-auto_build]',
                [
                    'true' => Language::_('VirtfusionDirectProvisioningMod.package_fields.auto_build.yes', true),
                    'false' => Language::_('VirtfusionDirectProvisioningMod.package_fields.auto_build.no', true)
                ],
                ($vars->meta['virtfusion-auto_build'] ?? 'true'),
                ['id' => 'virtfusion_direct_provisioning_mod_auto_build']
            )
        );
        $auto_build->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioningMod.package_fields.auto_build.help_text', true), 'virtfusion_direct_provisioning_mod_auto_build'));
        $fields->setField($auto_build);

        $port_speed = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.port_speed', true), 'virtfusion_direct_provisioning_mod_port_speed');
        $port_speed->attach(
            $fields->fieldText(
                'meta[virtfusion-port_speed]',
                ($vars->meta['virtfusion-port_speed'] ?? null),
                ['id' => 'virtfusion_direct_provisioning_mod_port_speed']
            )
        );
        $port_speed->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioningMod.package_fields.port_speed.help_text', true), 'virtfusion_direct_provisioning_mod_port_speed'));
        $fields->setField($port_speed);

        // Set the default OS template field
        $os_id = $fields->label(Language::_('VirtfusionDirectProvisioningMod.package_fields.os_id', true), 'virtfusion_direct_provisioning_mod_os_id');
        $os_id->attach(
            $fields->fieldText(
                'meta[virtfusion-default_os_template]',
                ($vars->meta['virtfusion-default_os_template'] ?? null),
                [
                    'id' => 'virtfusion_direct_provisioning_mod_os_id',
                    'requred' => 'required'
                ]
            )
        );
        $os_id->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioningMod.package_fields.os_id.help_text', true), 'virtfusion_direct_provisioning_mod_os_id'));

        $fields->setField($os_id);

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
        array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        // $this->Input->setErrors(['api' => ['response' => print_r($client, true)]]);
        // return;
        // Set unset checkboxes
        $checkbox_fields = [];

        // default OS version
        $virtfusion_os_id = $package->meta->{'virtfusion-default_os_template'} ?? null;
        $auto_build = $this->shouldAutoBuild($package);
        $domain = isset($vars['virtfusion_hostname']) ? trim($vars['virtfusion_hostname']) : '';
        $server_id = 0;
        $virtfusion_password = '';
        $virtfusion_ip = '';
        $virtfusion_base_ips = '';
        $virtfusion_additional_ips = '';
        $virtfusion_ipv6_cidr = '';
        $virtfusion_backup_plan_id = $package->meta->{'virtfusion-backup_plan_id'} ?? '';
        $virtfusion_vnc = '';
        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        // Load the API
        $row = $this->getModuleRow();

        $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

        // Get the fields for the service
        //$params = $this->getFieldsFromInput($vars, $package);

        // Validate the service-specific fields
        $this->validateService($package, $vars);

        if ($this->Input->errors()) {
            return;
        }

        // Only provision the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
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
                    $this->log($row->meta->hostname . '| client check result', serialize($request), 'output', $request['info']['http_code'] == 200);
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

                            $this->log($row->meta->hostname . '| client info', serialize($request), 'output', $request['info']['http_code'] == 201);

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

                    // override default hypervisor group ID if we have config option
                    $hypervisor_group_id = $package->meta->hypervisor_group_id;
                    if (isset($vars['configoptions']['dynamic_hypervisor_group_id'])) {
                        $hypervisor_group_id = $vars['configoptions']['dynamic_hypervisor_group_id'];
                    }

                    $virtfusion_extra_ips = 0;
                    if (isset($vars['configoptions']['additional_num_ips'])) {
                        $virtfusion_extra_ips = $vars['configoptions']['additional_num_ips'];
                        $this->log($row->meta->hostname . '| number of extra IPs', $virtfusion_extra_ips, 'input', true);
                    }

                    $ipv4_count = (int)$package->meta->default_ipv4 + (int)$virtfusion_extra_ips;

                    $request_params = [
                        'packageId' => $package->meta->package_id,
                        'userId' => $data->data->id,
                        'hypervisorId' => $hypervisor_group_id,
                        'ipv4' => $ipv4_count,
                    ];

                    $create_config_options = $vars['configoptions'] ?? [];
                    $has_port_speed_option = false;
                    foreach (self::PORT_SPEED_OPTIONS as $port_speed_option) {
                        if (isset($create_config_options[$port_speed_option])
                            && $create_config_options[$port_speed_option] !== '') {
                            $has_port_speed_option = true;
                            break;
                        }
                    }

                    if (!$has_port_speed_option && isset($package->meta->{'virtfusion-port_speed'})
                        && $package->meta->{'virtfusion-port_speed'} !== '') {
                        $create_config_options['virtfusion-port_speed'] = $package->meta->{'virtfusion-port_speed'};
                    }

                    $request_params = $this->applyCreateConfigOptions($request_params, $create_config_options);

                    $request = $server_api->create($request_params);

                    $this->log($row->meta->hostname . '| create server', serialize($request), 'input', $request['info']['http_code'] !== 201);

                    if ($request['info']['http_code'] !== 201) {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                        return;
                    }

                    $data = json_decode($request['response']);

                    $server_id = $data->data->id;

                    /**
                     *
                     * Build server
                     *
                     */

                    if (isset($vars['configoptions']['virtfusion-os_template'])) {
                        $virtfusion_os_id = $vars['configoptions']['virtfusion-os_template'];
                    }
                    $this->log($row->meta->hostname . '| build os id', $virtfusion_os_id, 'input', true);

                    $hasError = $auto_build;

                    // check that is int no hiccups in extraction
                    if ($auto_build && is_numeric($virtfusion_os_id) && !empty($domain)) {
                        $server_name = substr($domain, 0, strrpos($domain, '.'));
                        $build_params = [
                            'operatingSystemId' => $virtfusion_os_id,
                            'name' => $server_name,
                            'hostname' => $domain,
                            'ipv6' => true
                        ];

                        if (!empty($vars['configoptions']['virtfusion-ssh_keys'])) {
                            $build_params['sshKeys'] = $this->csvInts($vars['configoptions']['virtfusion-ssh_keys']);
                        }

                        if (isset($vars['configoptions']['virtfusion-vnc'])) {
                            $build_params['vnc'] = $this->boolValue($vars['configoptions']['virtfusion-vnc']);
                            $virtfusion_vnc = $build_params['vnc'] ? 'true' : 'false';
                        }

                        if (isset($vars['configoptions']['virtfusion-email'])) {
                            $build_params['email'] = $this->boolValue($vars['configoptions']['virtfusion-email']);
                        }

                        if (isset($vars['configoptions']['virtfusion-swap']) && $vars['configoptions']['virtfusion-swap'] !== '') {
                            $build_params['swap'] = (float) $vars['configoptions']['virtfusion-swap'];
                        }

                        $build_request = $server_api->build(
                            $server_id,
                            $build_params
                        );

                        $build_data = json_decode($build_request['response']);

                        if ($build_request['info']['http_code'] == 200) {
                            $hasError = false;

                            $virtfusion_password = $build_data->data->settings->decryptedPassword;

                            // if 200 we should have this
                            $ip_addresses = [];
                            foreach ($build_data->data->network->interfaces[0]->ipv4 as $ip) {
                                $ip_addresses[] = $ip->address;
                            }
                            $base_num = $package->meta->default_ipv4 - 1;

                            if (isset($ip_addresses[0])) {
                                $virtfusion_ip = $ip_addresses[0];
                            }

                            // get #2 - base number of ips
                            $virtfusion_base_ips_arr = array_slice($ip_addresses, 1, $base_num);
                            $virtfusion_base_ips = implode(',', $virtfusion_base_ips_arr);

                            if ($virtfusion_extra_ips > 0) {
                                // get #3 - end
                                $virtfusion_additional_ips_arr = array_slice($ip_addresses, $base_num + 1, count($ip_addresses) - 1);
                                $virtfusion_additional_ips = implode(',', $virtfusion_additional_ips_arr);
                            }

                            for ($i = 0; $i <= 10; $i++) {
                                sleep(5);
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

                        $this->log($row->meta->hostname . '| build server', serialize($build_request), 'output', $build_request['info']['http_code'] == 200);
                    } elseif (!$auto_build) {
                        $this->log($row->meta->hostname . '| build server', 'Skipped automatic build by package setting.', 'output', true);
                    }

                    if ($hasError) {
                        $cleanup_request = $server_api->cancel($server_id, []);

                        // log clean up
                        // should we email admin?
                        $this->log($row->meta->hostname . '| error services cleanup', serialize($cleanup_request), 'output', $cleanup_request['info']['http_code'] == 200);

                        // generic error, will be improved
                        $this->Input->setErrors(['api' => ['response' => 'Could not build the server.']]);
                        // do server cleanup
                        return;
                    }

                    if (isset($vars['configoptions']['virtfusion-backup_plan_id'])) {
                        $virtfusion_backup_plan_id = $vars['configoptions']['virtfusion-backup_plan_id'];
                    }

                    if ($virtfusion_backup_plan_id !== '' && is_numeric($virtfusion_backup_plan_id)) {
                        $backup_request = $server_api->setBackupPlan($server_id, $virtfusion_backup_plan_id);
                        $this->log($row->meta->hostname . '| backup plan', serialize($backup_request), 'output', in_array($backup_request['info']['http_code'], [200, 201, 204]));
                    }

                    if (!$auto_build && isset($vars['configoptions']['virtfusion-vnc']) && $this->boolValue($vars['configoptions']['virtfusion-vnc'])) {
                        $vnc_request = $server_api->setVnc($server_id, 'enable');
                        $this->log($row->meta->hostname . '| vnc', serialize($vnc_request), 'output', $vnc_request['info']['http_code'] == 200);
                        if ($vnc_request['info']['http_code'] == 200) {
                            $virtfusion_vnc = 'true';
                        }
                    }
                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                    return;
                }
            } catch (\Throwable $e) {
                $this->Input->setErrors(['api' => ['response' => print_r($e->getMessage(), true)]]);
                return;
            }
        }

        $service_meta = [
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
                'key' => 'virtfusion_vnc',
                'value' => $virtfusion_vnc,
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
                'key' => 'virtfusion_ip',
                'value' => $virtfusion_ip,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_ipv6_cidr',
                'value' => $virtfusion_ipv6_cidr,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion-base_ips',
                'value' => $virtfusion_base_ips,
                'encrypted' => 0
            ],
            [
                'key' => 'additional_num_ips',
                'value' => $virtfusion_additional_ips,
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
    public function editService($package, $service, array $vars = null, $parent_package = null, $parent_service = null)
    {
        // Set unset checkboxes
        $checkbox_fields = [];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

        $this->validateService($package, $vars, true);

        if ($this->Input->errors()) {
            return;
        }

        // Only update the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            // we need the api
            if ($module_row = $this->getModuleRow()) {
                $config_options = $vars['configoptions'] ?? [];
                if (array_key_exists('traffic', $config_options)) {
                    $data = $this->updatePrimaryTraffic(
                        $module_row,
                        $service_fields,
                        $config_options['traffic']
                    );
                    if (!empty($data['errors']['err_msg'])) {
                        $this->Input->setErrors(['api' => ['response' => $data['errors']['err_msg']]]);
                        return;
                    }
                } elseif (array_key_exists('additional_bandwidth', $config_options)) {
                    $data = $this->updateTraffic(
                        $module_row,
                        $service_fields,
                        $package,
                        $config_options['additional_bandwidth']
                    );
                    if (!empty($data['errors']['err_msg'])) {
                        $this->Input->setErrors(['api' => ['response' => $data['errors']['err_msg']]]);
                        return;
                    }
                }
                
                $data = $this->adjustIpAddresses($module_row, $service_fields, $vars);

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

                // reset vars with new information
                $vars['additional_num_ips'] = $data['service_fields']->{'additional_num_ips'};

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

        // Return all the service fields
        $fields = ['virtfusion_server_id', 'virtfusion_hostname', 'virtfusion-os_template', 'virtfusion_password', 'virtfusion-base_ips', 'additional_num_ips', 'virtfusion_ip', 'virtfusion_backup_plan_id', 'virtfusion_vnc', 'virtfusion_cpu_throttle', 'virtfusion_restart_required'];
        $encrypted_fields = ['virtfusion_password'];
        $return = [];
        foreach ($fields as $field) {
            if (isset($vars[$field]) || isset($service_fields->{$field})) {
                $return[] = [
                    'key' => $field,
                    'value' => $vars[$field] ?? $service_fields->{$field},
                    'encrypted' => (in_array($field, $encrypted_fields) ? 1 : 0)
                ];
            }
        }

        return $return;
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
        if (($row = $this->getModuleRow())) {
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
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
                // Nothing to do
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
        if (($row = $this->getModuleRow())) {
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
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
                // Nothing to do
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
        if (($row = $this->getModuleRow())) {
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
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
                // Nothing to do
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
    public function validateService($package, array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars, false, $package));
        return $this->Input->validates($vars);
    }

    /**
     * Attempts to validate an existing service against a set of service info updates. Sets Input errors on failure.
     *
     * @param stdClass $service A stdClass object representing the service to validate for editing
     * @param array $vars An array of user-supplied info to satisfy the request
     * @return bool True if the service update validates or false otherwise. Sets Input errors when false.
     */
    public function validateServiceEdit($service, array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars, true, null));
        if (!$this->Input->validates($vars)) {
            return false;
        }

        if (($vars['use_module'] ?? 'true') !== 'true') {
            return true;
        }

        $config_options = $vars['configoptions'] ?? [];
        foreach (['memory', 'cpuCores', 'traffic', 'storage'] as $option_name) {
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

        if (isset($config_options['traffic']) && $config_options['traffic'] !== ''
            && (int) $config_options['traffic'] < 0) {
            $this->Input->setErrors([
                'configoptions' => [
                    'traffic' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum', true)
                ]
            ]);
            return false;
        }

        $requested_port_speed = $this->requestedPortSpeed($config_options);
        if ($requested_port_speed !== null
            && (string) $requested_port_speed !== (string) $this->currentPortSpeed($service)) {
            $this->Input->setErrors([
                'configoptions' => [
                    'port_speed' => Language::_('VirtfusionDirectProvisioningMod.!error.configoption.port_speed.edit', true)
                ]
            ]);
            return false;
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

        $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
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
    private function getServiceRules(array $vars = null, $edit = false, $package = null)
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

        if ($package === null || $this->shouldAutoBuild($package)) {
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
        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));

        if (($row = $this->getModuleRow()) && isset($service_fields->virtfusion_server_id)) {
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
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
                            'virtfusion-backup_plan_id'
                        ) === null,
                        'cpu' => $this->getServiceConfigOptionValue($service, 'cpuCores') === null,
                        'memory' => $this->getServiceConfigOptionValue($service, 'memory') === null,
                        'primaryDiskReadIOPS' => true,
                        'primaryDiskReadThroughput' => true,
                        'primaryDiskSize' => $this->getServiceConfigOptionValue($service, 'storage') === null
                            && $new_primary_storage >= $primary_storage,
                        'primaryDiskWriteIOPS' => true,
                        'primaryDiskWriteThroughput' => true,
                        'primaryNetworkInboundSpeed' => $this->currentPortSpeed($service) === null,
                        'primaryNetworkOutboundSpeed' => $this->currentPortSpeed($service) === null,
                        'primaryNetworkTraffic' => $this->getServiceConfigOptionValue($service, 'traffic') === null
                            && $this->getServiceConfigOptionValue($service, 'additional_bandwidth') === null
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
                    $vf_pkg_data_from = json_decode($vf_pkg_request['response'] ?? []);

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

                    return [[
                        'key' => 'virtfusion_restart_required',
                        'value' => 'true',
                        'encrypted' => 0
                    ]];
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
        $this->view = new View('admin_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields)));

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
        return [
            'tabManage' => Language::_('VirtfusionDirectProvisioningMod.tabManage', true),
            'tabClientIPAddresses' => Language::_('VirtfusionDirectProvisioningMod.ipAddresses', true)
        ];
    }

    public function getAdminTabs($package)
    {
        return [
            'tabAdminManage' => Language::_('VirtfusionDirectProvisioningMod.tabManage', true),
            'tabAdminIPAddresses' => Language::_('VirtfusionDirectProvisioningMod.ipAddresses', true)
        ];
    }

    private function getRemoteServerInfo($module_row, $server_id)
    {
        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);
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
        $server_info->network_in = $data->data->network->interfaces[0]->inAverage ?? null;
        $server_info->network_out = $data->data->network->interfaces[0]->outAverage ?? null;
        $backup_plan = $data->data->backupPlan ?? null;
        $server_info->backup_plan = is_object($backup_plan)
            ? ($backup_plan->name ?? $backup_plan->id ?? null)
            : ($data->data->settings->backupPlan ?? null);

        $pending_tasks = $data->data->tasks->actions->pending ?? [];
        $server_info->tasks_active = !empty($data->data->tasks->active) || !empty($pending_tasks);
        $server_info->pending_tasks = [];
        foreach ($pending_tasks as $task) {
            $server_info->pending_tasks[] = $task->action ?? ('Task #' . ($task->id ?? '?'));
        }

        $traffic_request = $server_api->getTraffic($server_id);
        if ($this->apiRequestSucceeded($traffic_request, [200])) {
            $traffic_data = json_decode($traffic_request['response']);
            $current_month = $traffic_data->data->monthly[0] ?? null;
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

        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
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

        $base_num = max(0, (int)($package->meta->default_ipv4 ?? 1) - 1);
        $network_fields = [
            'virtfusion_ip' => $ip_addresses[0] ?? '',
            'virtfusion-base_ips' => implode(',', array_slice($ip_addresses, 1, $base_num)),
            'additional_num_ips' => implode(',', array_slice($ip_addresses, $base_num + 1))
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

        return $service_fields;
    }

    private function handleServerAction($module_row, $server_api, $service, $service_fields, array $post)
    {
        $server_id = $service_fields->virtfusion_server_id;
        $action = $post['action'] ?? 'manage';

        if (in_array($action, ['boot', 'restart', 'shutdown', 'poweroff', 'resetpass', 'vnc'], true)) {
            $state_request = $server_api->get($server_id, true);
            if ($this->apiRequestSucceeded($state_request, [200])) {
                $state_data = json_decode($state_request['response']);
                $pending = $state_data->data->tasks->actions->pending ?? [];
                if (!empty($state_data->data->tasks->active) || !empty($pending)) {
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
                return Language::_('VirtfusionDirectProvisioningMod.tabManage.reset_password_success', true)
                    . ' ' . ($data->data->expectedPassword ?? '');

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
                if (!empty($data->data->vnc->wss->url)) {
                    header('Location: https://' . $module_row->meta->hostname . $data->data->vnc->wss->url);
                    die();
                }

                $this->Input->setErrors(['api' => ['response' => 'The VNC console URL was not returned by the API.']]);
                return null;
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
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tabManage', 'default');
        $this->view->base_uri = $this->base_uri;

        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $message = null;
        $server_info = null;
        $row = $this->getModuleRow();
        $post = !empty($post) ? $post : $_POST;

        if (!empty($post) && $row) {
            if (property_exists($service_fields, 'virtfusion_server_id')) {
                if (is_numeric($service_fields->virtfusion_server_id)) {
                    $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

                    $api->loadCommand('virtfusion_server');

                    $server_api = new VirtfusionServer($api);
                    $message = $this->handleServerAction($row, $server_api, $service, $service_fields, $post ?? []);
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
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tabAdminManage', 'default');

        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $message = null;
        $server_info = null;
        $row = $this->getModuleRow();
        $post = !empty($post) ? $post : $_POST;

        if (!empty($post) && $row) {
            if (property_exists($service_fields, 'virtfusion_server_id')) {
                if (is_numeric($service_fields->virtfusion_server_id)) {
                    $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

                    $api->loadCommand('virtfusion_server');

                    $server_api = new VirtfusionServer($api);
                    $message = $this->handleServerAction($row, $server_api, $service, $service_fields, $post ?? []);
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
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_ips', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (!empty($post)) {
            $error_msg = $this->removeIPAddress($package, $service, $post);

            if (!empty($error_msg)) {
                // this does not redirect to the right place
                $this->Input->setErrors(['api' => ['response' => $error_msg]]);
            }

            // redirect to clear post
            // if not refresh could cause issues
            $host = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
            header("Location: $host" . $_SERVER['HTTP_HOST'] . $post['submit_uri']);
            die();
        }

        $ip_address_data = $this->getClientIpAddresses($package, $service, $get, $post, $client = true);
        $formated_ips = $this->formatIPToView($ip_address_data);

        $submit_uri = $this->base_uri . 'services/manage/' . $service->id . '/tabClientIPAddresses/';
        $this->view->set('submit_uri', $submit_uri);
        $this->view->set('ip_addresses', $formated_ips);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->set('ip_addable', $ip_address_data->addable);
        $this->view->set('view_type', 'tabClientIPAddresses');

        return $this->view->fetch();
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
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_ips', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (isset($post['refresh_ips']) || isset($post['refresh_ipv6'])) {
            $this->refreshServiceNetworkFields(
                $service,
                $package,
                $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields))
            );

            // redirect to clear post
            // if not refresh could cause issues
            $host = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
            header("Location: $host" . $_SERVER['HTTP_HOST'] . "/admin/clients/servicetab/$service->client_id/$service->id/tabAdminIPAddresses/");
            die();
        }

        $ip_address_data = $this->getClientIpAddresses($package, $service, $get, $post, $client = false);

        $formated_ips = $this->formatIPToView($ip_address_data);


        $this->view->set('ip_addresses', $formated_ips);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->set('ip_addable', $ip_address_data->addable);
        $this->view->set('is_admin', true);
        $this->view->set('view_type', 'tabAdminIPAddresses');

        return $this->view->fetch();
    }

    private function removeIPAddress($package, $service, $post)
    {
        Loader::loadHelpers($this, [
            'Invoices',
            'Services',
            'ServiceChanges',
            'ModuleManager'
        ]);

        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $module_row = $this->getModuleRow();

        // Get current extra IP stuff
        $extra_ips_arr = [];
        $extra_ips = $service_fields->{'virtfusion-extra_ips'};
        if (!empty($extra_ips) && $module_row) {
            $extra_ips_arr = explode(',', $extra_ips);
        }

        $ip_to_remove = $post['ip_address'];

        if (in_array($ip_to_remove, $extra_ips_arr)) {
            // Get and load api
            $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
            $api->loadCommand('virtfusion_server');
            $server_api = new VirtfusionServer($api);

            $request = $server_api->removeIpv4($service_fields->virtfusion_server_id, [ $ip_to_remove ]);
            $this->log($module_row->meta->hostname, serialize($request), 'output', $request['info']['http_code'] == '204');
            $new_extra_ips = implode(',', array_diff($extra_ips_arr, [ $ip_to_remove ]));

            if ($request['info']['http_code'] == '204') {
                // prorate here

                $new_extra_ips = array_diff($extra_ips_arr, [ $ip_to_remove ]);

                $this->Services->editField(
                    $service->id,
                    [
                        'key' => 'virtfusion-extra_ips',
                        'value' => implode(',', $new_extra_ips),
                        'encrypted' => false
                    ]
                );

                // Fetch and re-set all current service config options
                $options = [];
                foreach ($service->options as $option) {
                    // Quantity options use the qty field as the value
                    if ($option->option_type == 'quantity') {
                        $option->option_value = $option->qty;
                    }

                    // Set the extra IPs to the count of them
                    if ($option->option_name == 'virtfusion-extra_ips') {
                        $option->option_value = max(0, count($new_extra_ips));
                    }

                    // Set the value of each option
                    $options[$option->option_id] = $option->option_value;
                }

                // Get the invoice lines for an ip change
                $invoice_vars = $this->getIpChangeInvoiceVars($service, $options);
                if (!empty($invoice_vars)) {
                    // Create the invoice
                    $this->Invoices->add($invoice_vars);
                }

                // Update the config options
                $this->Services->edit(
                    $service->id,
                    [
                        'virtfusion_server_id' => $service_fields->{'virtfusion_server_id'},
                        'virtfusion_hostname' => $service_fields->virtfusion_hostname,
                        'configoptions' => $options,
                        'use_module' => 'false'
                    ]
                );

                if ($this->Services->errors()) {
                    return $this->Services->errors();
                }
            } else {
                return 'Could not remove IP address.';
            }
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

        $serviceChange = $this->ServiceChanges->getPresenter(
            $service->id,
            ['configoptions' => $options, 'pricing_id' => $service->pricing_id, 'qty' => $service->qty]
        );

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

    /**
     * Format ip array in to array used in views to display tabled ip data
     *
     * @param object $ip_address_data An object representing all ips
     * @return array An array of ip for the template
     */
    private function formatIPToView($ip_address_data)
    {
        $view_ready = [];

        if (isset($ip_address_data->ip_addresses) && isset($ip_address_data->editable_options)) {
            $ip_addresses = $ip_address_data->ip_addresses;
            $editable_options = $ip_address_data->editable_options;

            foreach ($ip_addresses as $title => $address) {
                $view_ready[] = (object) [
                    'header' => Language::_('VirtfusionDirectProvisioningMod.ipAddresses.' . $title, true),
                    'editable' => $editable_options[$title], // 1/0 for yes no
                    'ip_addresses' => $address // needs to be array
                ];
            }
        }

        return $view_ready;
    }

    private function updateTraffic($module_row, $service_fields, $package, $additional_traffic)
    {
        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
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

        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
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

        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);
        $server_id = $service_fields->virtfusion_server_id;

        $resource_options = array_intersect_key(
            $vars['configoptions'],
            array_flip(['memory', 'cpuCores'])
        );
        if (!empty($resource_options)) {
            $server_request = $server_api->get($server_id);
            if (!$this->apiRequestSucceeded($server_request, [200])) {
                return [
                    'service_fields' => $updated_fields,
                    'errors' => [
                        'err_msg' => Language::_('VirtfusionDirectProvisioningMod.!error.resource.current', true)
                    ]
                ];
            }

            $server_data = json_decode($server_request['response']);
            $current_resources = $server_data->data->settings->resources ?? new stdClass();

            if (isset($resource_options['memory']) && $resource_options['memory'] !== '') {
                $memory = (int) $resource_options['memory'];
                if ((int) ($current_resources->memory ?? 0) !== $memory) {
                    $request = $server_api->modifyMemory($server_id, $memory);
                    $success = $this->apiRequestSucceeded($request, [201]);
                    $this->log(
                        $module_row->meta->hostname . '| modify memory',
                        serialize($request),
                        'output',
                        $success
                    );

                    if (!$success) {
                        $err_msg = Language::_('VirtfusionDirectProvisioningMod.!error.resource.memory', true);
                    } else {
                        $updated_fields['virtfusion_restart_required'] = 'true';
                    }
                }
            }

            if (!$err_msg && isset($resource_options['cpuCores']) && $resource_options['cpuCores'] !== '') {
                $cpu_cores = (int) $resource_options['cpuCores'];
                if ((int) ($current_resources->cpuCores ?? 0) !== $cpu_cores) {
                    $request = $server_api->modifyCpuCores($server_id, $cpu_cores);
                    $success = $this->apiRequestSucceeded($request, [201]);
                    $this->log(
                        $module_row->meta->hostname . '| modify CPU cores',
                        serialize($request),
                        'output',
                        $success
                    );

                    if (!$success) {
                        $err_msg = Language::_('VirtfusionDirectProvisioningMod.!error.resource.cpu', true);
                    } else {
                        $updated_fields['virtfusion_restart_required'] = 'true';
                    }
                }
            }
        }

        if (!$err_msg && isset($vars['configoptions']['virtfusion-backup_plan_id'])) {
            $backup_plan_id = trim((string) $vars['configoptions']['virtfusion-backup_plan_id']);

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

        if (!$err_msg && isset($vars['configoptions']['virtfusion-vnc'])) {
            $new_vnc = $this->boolValue($vars['configoptions']['virtfusion-vnc']) ? 'true' : 'false';
            $current_vnc = isset($service_fields->virtfusion_vnc) ? (string) $service_fields->virtfusion_vnc : '';

            if ($current_vnc !== $new_vnc) {
                $request = $server_api->setVnc($server_id, $new_vnc === 'true' ? 'enable' : 'disable');
                $this->log($module_row->meta->hostname . '| vnc', serialize($request), 'output', $request['info']['http_code'] == 200);

                if ($request['info']['http_code'] != 200) {
                    $err_msg = 'There was an error while updating VNC.';
                } else {
                    $updated_fields['virtfusion_vnc'] = $new_vnc;
                }
            }
        }

        if (!$err_msg && isset($vars['configoptions']['virtfusion-cpu_throttle'])) {
            $percent = trim((string) $vars['configoptions']['virtfusion-cpu_throttle']);

            if ($percent !== '' && is_numeric($percent)) {
                $request = $server_api->modifyCpuThrottle($server_id, ['percent' => (int) $percent]);
                $this->log($module_row->meta->hostname . '| cpu throttle', serialize($request), 'output', in_array($request['info']['http_code'], [200, 204]));

                if (!in_array($request['info']['http_code'], [200, 204])) {
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
    private function adjustIpAddresses($module_row, $service_fields, $vars)
    {
        $edit_qty = 0;
        $current_qty = 0;
        $extra_ips = [];
        $ips_to_remove = [];
        $new_extra_ips = [];
        $err_msg = '';


        // Get and load api
        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);

        // Explode will add empty element if empty
        // lets make sure its not
        if (isset($service_fields->{'additional_num_ips'}) && !empty($service_fields->{'additional_num_ips'})) {
            $extra_ips = explode(',', $service_fields->{'additional_num_ips'});
            $current_qty = count($extra_ips);
        }

        // Get the new updated count
        if (isset($vars['configoptions']['additional_num_ips'])) {
            $edit_qty = (int) $vars['configoptions']['additional_num_ips'];
        }

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
            // should we do this here?
            $service_fields->{'additional_num_ips'} = implode(',', $new_extra_ips);
        }

        return [
            'service_fields' => $service_fields,
            'errors' => [
                'err_msg' => $err_msg
            ]
        ];
    }

    /**
     * Handles data for the IPs tab in the client and admin interfaces
     * @see VirtfusionDirectProvisioningMod::tabIPs() and VirtfusionDirectProvisioningMod::tabClientIPs()
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param bool $client True if the action is being performed by the client, false otherwise
     * @return array An array of vars for the template
     */
    private function getClientIpAddresses($package, $service, array $get = null, array $post = null, $client = false)
    {
        Loader::loadModels($this, ['Services']);

        // Get the service fields
        $service_fields = $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields));
        $module_row = $this->getModuleRow($package->module_row);

        // define items we will return
        $main_ip = [];
        $base_ips = [];
        $additional_ips = [];
        $option_addable = false;

        // determine if we can add more IPs
        foreach ($service->options as $option) {
            if ($option->option_name == 'additional_num_ips') {
                $option_addable = $option->option_addable;
            }
        }

        // set main ip
        if (isset($service_fields->virtfusion_ip)) {
            $main_ip = explode(',', $service_fields->virtfusion_ip);
        }

        if (isset($service_fields->{'virtfusion-base_ips'}) && !empty($service_fields->{'virtfusion-base_ips'})) {
            $base_ips = explode(',', $service_fields->{'virtfusion-base_ips'});
        }

        if (isset($service_fields->{'additional_num_ips'}) && !empty($service_fields->{'additional_num_ips'})) {
            $additional_ips = explode(',', $service_fields->{'additional_num_ips'});
        }

        if (isset($service_fields->virtfusion_ipv6_cidr)) {
            $ipv6 = explode(',', $service_fields->virtfusion_ipv6_cidr);
        }

        // Determine whether the service option for custom IPs is editable by the client
        $option_editable = !$client;
        if ($client) {
            foreach ($service->options as $option) {
                if ($option->option_name == 'additional_num_ips') {
                    $option_editable = ($option->option_editable == 1);
                    break;
                }
            }
        }

        // for consistency make it an opject
        return (object) [
            'ip_addresses' => [
                'main' => $main_ip,
                'base' => $base_ips,
                'extra' => array_values($additional_ips ?? []),
                'ipv6' => array_values($ipv6 ?? []),
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
        $this->view = new View('client_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning_mod' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $this->normalizeLegacyServiceFields($this->serviceFieldsToObject($service->fields)));

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

        if (!$this->shouldAutoBuild($package)) {
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
        ');

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

        if ($this->shouldAutoBuild($package)) {
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
        }

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
        // explode will add blank item to array if its empty
        if (isset($vars->{'additional_num_ips'}) && !empty($vars->{'additional_num_ips'})) {
            $ip_options = explode(',', $vars->{'additional_num_ips'});
            // set ips as keys and values;
            $extra_ips = array_combine($ip_options, $ip_options);
        }

        $service_options = $this->getServiceOption($package->id, 'additional_num_ips');
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
        ');

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

        if (!$this->shouldAutoBuild($package)) {
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

        $service_options = $this->getServiceOption($package->id, 'additional_num_ips');
        if (!empty($service_options) && $service_options['addable'] == '1') {
        }

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>" . $this->getHostnameValidationJS() . '</script>
        ');

        return $fields;
    }

    public function getClientEditFields($package, $vars = null)
    {
        return new ModuleFields();
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
                'virtfusion_hostname',
                'virtfusion_password',
                'virtfusion_ip',
                'virtfusion-base_ips',
                'virtfusion_ipv6_cidr'
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
