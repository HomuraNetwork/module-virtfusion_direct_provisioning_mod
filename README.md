# VirtFusion Direct Provisioning Mod

This module tracks the official VirtFusion Blesta module while keeping a separate module identity so Blesta's official updater cannot overwrite local extensions. It targets Blesta 6.0.0-b4 and VirtFusion's current v1 API as documented at `https://docs.virtfusion.com/api/openapi.yaml`.

## Install the Module

1. You can install the module via composer:

    ```
    composer require homuranetwork/virtfusion_direct_provisioning_mod
    ```

2. OR upload the source code to a /components/modules/virtfusion_direct_provisioning_mod/ directory within
   your Blesta installation path.

   For example:

    ```
    /var/www/html/blesta/components/modules/virtfusion_direct_provisioning_mod/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Modules

4. Find **VirtFusion Direct Provisioning Mod** and click the **Install** button.

5. If replacing the official module, open the mod's management page and run **Sync from official**.

## Migrating existing services

The sync action moves local Blesta ownership for module rows, module groups, packages, and module metadata from the official module ID to the mod module ID in one transaction. Existing services continue to use the same `module_row_id`, and no VirtFusion API create, update, or delete call is made.

After a successful **Sync from official**, the official module no longer owns the migrated packages or rows and can be disabled or uninstalled. **Sync to official** reverses the ownership move. The destination must be empty to prevent an accidental merge.

Both current `virtfusion_server_id` service fields and legacy `server_id` fields are supported.

# Setting up VirtFusion Package Option
This module supports usage of default OS that you can set per package
When creating a new package, after selecting `Server Group` you will have an option for `Default Operating System ID`.
Follow the help text to find that Tempalate ID.

***This option will be overriden by `virtfusion-os_template` config option***

### Automatic build

Set **Auto build** to **No** in the Blesta package's module options to create the VirtFusion server without calling `/servers/{serverId}/build`. In this mode a hostname and OS template are not required. The returned server ID is still stored so the service can be managed and built later.

## Configuring package options
### Operating System

If you want to allow your users to have an option of selecting multiple operating systems, you will need to create a package option (Config option -> Create option in blesta). The package option name must be `virtfusion-os_template` to work correctly with this module. Type should be set to `Drop-down`. If you only need one option, consider using `Default Operating System ID` described above. 

Per each option you choose, the value **must** be the ID of the template. 
The easiest way to find this value is to go to `media/templates` in Virtfution dashboard and choose a template. 
he last value in url will be the template OS ID.


In example below **12** is the template OS ID.

This is the same number used if only setting default operating system ID
```
/admin/server/media/templates/12
```

### Extra IP Addresses
If you want to add an option to allow customers to buy extra IP Address, you will need to create another package option (Config option -> Create option in blesta).

The name for this option **must** be `additional_num_ips` and type should be set to `Quantity`.

### Hypervisor Group ID Config Option
You can set up **dynamic Hypervisor Group** ID values by using blesta package options

You can set **Label** of package option to whatever makes sense for your organization
Set **Name** to 
```
dynamic_hypervisor_group_id
```

The **names** of the options will not matter and can be set to whatever makes sense for your organization, but the **value** must match an **ID** in Virtfution hypervisor groups dashboard.
```
Computer Resources -> Hypervisor Groups -> ID
```

If dynamic hypervisor group is not set,
it will use defualt from that package module option

***Package option **Type** has only been tested with dropwdown!***

### Port speed

Create a Blesta configurable option named:

```
virtfusion-port_speed
```

Its numeric value is sent to both `networkSpeedInbound` and `networkSpeedOutbound` when the server is created. VirtFusion defines the unit as kB/s. The aliases `virtfusion_port_speed` and `port_speed` are accepted for compatibility. If the combined option is present, it overrides separately supplied `networkSpeedInbound` and `networkSpeedOutbound` values. A fixed default can also be set with the package's **Default Port Speed** module field.

### Other create-time resource options

The following configurable option names map directly to the current VirtFusion create-server API:

- `storage`, `traffic`, `memory`, `cpuCores`
- `networkSpeedInbound`, `networkSpeedOutbound`
- `storageProfile`, `networkProfile`
- `firewallRulesets`, `hypervisorAssetGroups` (array or comma-separated IDs)
- `additionalStorage1Enable`, `additionalStorage2Enable`
- `additionalStorage1Profile`, `additionalStorage2Profile`
- `additionalStorage1Capacity`, `additionalStorage2Capacity`

Build and post-create options retained from the previous customized module include `virtfusion-ssh_keys`, `virtfusion-vnc`, `virtfusion-email`, `virtfusion-swap`, `virtfusion-backup_plan_id`, and `virtfusion-cpu_throttle`.

### Service upgrades and downgrades

Blesta's normal Service Changes flow calculates proration, validates the requested change before invoicing, and applies the module change after payment when queued service changes are enabled.

- `memory` is updated through `PUT /servers/{serverId}/modify/memory`.
- `cpuCores` is updated through `PUT /servers/{serverId}/modify/cpuCores`.
- `traffic` is updated through `PUT /servers/{serverId}/modify/traffic`.
- `additional_bandwidth` remains an additive allowance on top of the selected VirtFusion package traffic.
- Blesta/VirtFusion package changes can upgrade or downgrade CPU, memory, traffic, and other package resources.
- A package downgrade never shrinks the primary disk. The current disk size is retained while the other package resources are changed.
- Primary disk growth is supported through a VirtFusion package change. The current API does not expose an arbitrary disk-size modification endpoint, so changing a `storage` configurable option after creation is rejected before an invoice is generated.
- Custom `virtfusion-port_speed` values are preserved across package changes. Editing a custom port speed after creation is rejected because the current API does not expose a documented network-speed modification endpoint.

Package, memory, and CPU changes set a persistent restart recommendation instead of forcing an immediate reboot. The recommendation is cleared after a successful restart from the module's Manage tab.

### Reselling bandwidth
The traffic (bandwidth) management feature requires VirtFusion version 6 or later.
