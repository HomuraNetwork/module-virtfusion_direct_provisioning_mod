# VirtFusion Direct Provisioning Mod for Blesta

VirtFusion provisioning for Blesta with a separate module identity, configurable server resources, controlled service upgrades, one-time Traffic Block products, and expanded service management.

This module is named `virtfusion_direct_provisioning_mod`, so it can be installed beside the official VirtFusion module without being replaced by an official-module update.

## Requirements

- Blesta 6.0.0-b4 or later
- A VirtFusion installation with API access
- A VirtFusion API token

## Installation

Copy the module to:

```text
/path/to/blesta/components/modules/virtfusion_direct_provisioning_mod/
```

Then open **Settings > Modules > Available** in Blesta and install **VirtFusion Direct Provisioning Mod**.

Do not rename the module directory.

## Moving existing services from the official module

Install this module without uninstalling the official module, then open its management page and select **Sync from official**.

The sync moves the existing VirtFusion module rows, groups, and packages to this module. Existing services keep the same `module_row_id` and VirtFusion server ID, so they remain manageable without being recreated.

After checking an existing service's Manage page, the official module may be disabled. **Sync to official** reverses the change if you need to move the packages and module configuration back.

Back up the Blesta database before running either sync action.

## Add a VirtFusion server

Open the installed module and add a server with the following settings:

| Setting | Description |
| --- | --- |
| Name | An internal name for this VirtFusion installation. |
| Hostname | VirtFusion hostname without `https://`, for example `vf.example.com`. |
| API Token | VirtFusion API token. |
| Admin Server URL Template | Optional admin-only server link. The default is `https://{hostname}/admin/servers/{server_id}`. |
| Enable Traffic Block Purchases | Enables optional one-time Traffic Block products for services on this server. |
| Allow insecure TLS certificates | Disables certificate verification for this server. Use only for a trusted self-signed installation. |

Saving the server checks the API connection.

## Create a server package

Create a Blesta package and select this module. Blesta displays its normal **Module**, **Server Group**, and **Server** selectors.

The module adds these Module Options:

| Module Option | Value |
| --- | --- |
| Product Type | Select **Server** for a normal VirtFusion server. |
| Hypervisor Group ID | Default VirtFusion hypervisor group ID. |
| Default IPv4 | Number of IPv4 addresses included by default. |
| Package ID | VirtFusion package ID used to create the server. |

`Product Type` is the only new package-mode field. Auto Build, operating system, backup plan, port speed, and resource choices belong in Blesta **Configurable Options**, not Module Options.

## Configurable Options

The configurable-option **Name** must match the value shown below. The customer-facing label may be changed or translated.

### Basic provisioning options

| Name | Suggested type | Use |
| --- | --- | --- |
| `virtfusion-auto_build` | Dropdown | `true` builds the selected OS; `false` creates the server without building it. |
| `virtfusion-os_template` | Dropdown | VirtFusion operating-system template ID. Required when Auto Build is enabled. |
| `dynamic_hypervisor_group_id` | Dropdown | Overrides the package's Hypervisor Group ID. |
| `additional_num_ips` | Quantity | Additional IPv4 addresses beyond Default IPv4. |
| `virtfusion-backup_plan_id` | Dropdown | VirtFusion backup-plan ID. May be changed later. |
| `virtfusion-cpu_throttle` | Quantity or Dropdown | CPU throttle percentage. |

For Auto Build, the aliases `virtfusion_auto_build` and `auto_build` are also accepted.

### Port speed

Use one configurable option named:

```text
virtfusion-port_speed
```

Its numeric value is applied to both inbound and outbound port speed. The aliases `virtfusion_port_speed` and `port_speed` are also accepted.

Port speed is applied only when the server is created. It cannot be changed later through the current VirtFusion API.

If needed, separate create-time options named `networkSpeedInbound` and `networkSpeedOutbound` are also supported. The combined port-speed option takes priority when both are present.

### Server resources

| Name | Use |
| --- | --- |
| `storage` | Primary disk capacity at creation. |
| `memory` | Memory in MB. May be changed later. |
| `cpuCores` | CPU core count. May be changed later. |
| `traffic` | Total traffic allowance in GB. May be changed later. |
| `additional_bandwidth` | Additional GB added to the selected VirtFusion package's traffic allowance. |

If both `traffic` and `additional_bandwidth` are present, `traffic` takes priority.

### Build options

These options are used only when Auto Build is enabled:

| Name | Use |
| --- | --- |
| `virtfusion-ssh_keys` | VirtFusion SSH-key IDs. Multiple IDs may be comma-separated. |
| `virtfusion-email` | Whether VirtFusion sends its build email. |
| `virtfusion-swap` | Swap value passed to the build request. |

When `virtfusion-auto_build` is `false`, the order form hides hostname, OS template, SSH keys, build email, and swap. Blesta still stores the VirtFusion server ID so the unbuilt server can be managed later.

VNC is not a Configurable Option. It is enabled only when the client or administrator requests it from the service Manage page.

### Advanced create-time options

The following options are available when the related VirtFusion configuration is used:

| Name | Use |
| --- | --- |
| `storageProfile` | Storage profile ID. |
| `networkProfile` | Network profile ID. |
| `firewallRulesets` | Comma-separated firewall ruleset IDs. |
| `hypervisorAssetGroups` | Comma-separated hypervisor asset-group IDs. |
| `additionalStorage1Enable` | Enable the first additional disk. |
| `additionalStorage1Profile` | First additional-disk profile ID. |
| `additionalStorage1Capacity` | First additional-disk capacity. |
| `additionalStorage2Enable` | Enable the second additional disk. |
| `additionalStorage2Profile` | Second additional-disk profile ID. |
| `additionalStorage2Capacity` | Second additional-disk capacity. |

## Service upgrades and downgrades

Use Blesta's normal service-change and configurable-option upgrade process. Blesta handles pricing, invoicing, and proration before the module applies the paid change to VirtFusion.

The module can change:

- memory;
- CPU cores;
- traffic allowance;
- additional bandwidth;
- backup plan;
- CPU throttle;
- the selected VirtFusion package.

Disk restrictions:

- a `storage` Configurable Option cannot be reduced;
- an existing primary disk cannot be resized directly through the `storage` option;
- when changing to a smaller VirtFusion package, the current primary disk is preserved and is not shrunk.

Memory, CPU, and VirtFusion package changes show **Resource changed; restart recommended**. The module does not restart the server automatically.

## One-time Traffic Block products

Traffic Blocks are separate one-time child services. They are not server upgrades and do not change the server package.

To create a Traffic Block product:

1. Create another Blesta package using this module.
2. Set **Product Type** to **Traffic Block (one-shot addon)**.
3. Add one-time pricing only.
4. Add a Configurable Option named `virtfusion-traffic_block_gb` whose value is the number of GB to purchase.
5. Enable **Traffic Block Purchases** on the parent server's module row.

The aliases `virtfusion_traffic_block_gb` and `traffic_block_gb` are also accepted.

Install the separate **Product Addons** plugin to offer the Traffic Block after a server has been created. In the plugin, create a `traffic_block` rule and choose the allowed parent server packages and Traffic Block packages.

The purchase confirmation shows the current VirtFusion traffic period end date. VirtFusion controls when the Traffic Block expires; Blesta does not remove it at the service renewal date.

Pricing is defined by the Blesta package and Configurable Option. The module does not calculate the price.

## Service Manage page

The client and administrator Manage pages show available VirtFusion information, including:

- power and build state;
- pending tasks;
- CPU, memory, disk, package, and port speed;
- traffic usage, Traffic Blocks, and traffic reset date;
- IPv4 and IPv6 addresses;
- backup plan and recent backup information;
- restart recommendation and build-status warnings.

Available actions include boot, shutdown, power off, restart, password reset, VNC access, IP management, and opening the server in VirtFusion.

The administrator link opens the configured VirtFusion admin server URL and does not create a client login session. The client link may use VirtFusion's client login bridge.

The traffic reset date shown by the module comes from VirtFusion. Changing the Blesta renewal date or granting extra service days does not change that VirtFusion date.

## Links

- [VirtFusion API documentation](https://docs.virtfusion.com/api/openapi.yaml)
- [Official VirtFusion Blesta module](https://github.com/blesta/module-virtfusion_direct_provisioning)
- [VirtFusion Direct Provisioning Mod repository](https://github.com/HomuraNetwork/module-virtfusion_direct_provisioning_mod)
