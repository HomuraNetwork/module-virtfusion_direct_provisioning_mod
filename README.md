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

The sync moves the existing VirtFusion module rows, groups, and packages to this module. Existing services keep the same `module_row_id` and VirtFusion server ID, so they remain manageable without being recreated. Service IP fields are converted to the destination module's expected layout in either direction.

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
| Allow insecure TLS certificates | Disables certificate verification for this server. Use only for a trusted self-signed installation. |

Saving the server checks the API connection.

## Create a server package

Create a Blesta package and select this module. Blesta displays its normal **Module**, **Server Group**, and **Server** selectors.

The module adds these Module Options:

| Module Option | Applies to | Value |
| --- | --- | --- |
| Product Type | All packages | Select **VirtFusion Server** or **Traffic Block**. |
| Hypervisor Group ID | Server | Default VirtFusion hypervisor group ID. |
| Default IPv4 | Server | Number of IPv4 addresses included by default. |
| Package ID | Server | VirtFusion package ID used to create the server. |
| Block Size (GB) | Traffic Block | Default positive whole-number capacity purchased by this package. |

Auto Build, operating system, backup plan, port speed, and server resource choices belong in Blesta **Configurable Options**, not Module Options.

## Configurable Options

The configurable-option **Name** must match the value shown below. The customer-facing label may be changed or translated.

Configurable Option names use the VirtFusion API field names. The module does not accept legacy aliases.

### Module controls

| Name | Suggested type | Use |
| --- | --- | --- |
| `autoBuild` | Dropdown | Create only. `true` calls the Build API; `false` creates the server without building. If omitted, the default is `false`. |
| `networkSpeed` | Quantity or Dropdown | Create only. Module-provided combined speed applied to both `networkSpeedInbound` and `networkSpeedOutbound`. Prefer values such as `100 Mbps` or `1 Gbps`; bare numbers are treated as raw API values. |
| `additionalIpv4` | Quantity | Adds IPv4 addresses to the Module Option **Default IPv4** quantity. Ignored when an absolute `ipv4` value is supplied. |
| `additionalTraffic` | Quantity or Dropdown | Adds GB to the selected VirtFusion package's base traffic. Ignored when an absolute `traffic` value is supplied. |
| `backupPlanId` | Dropdown | Backup-plan ID applied after creation and changeable later. Use `0` to remove the plan. |
| `cpuThrottle` | Quantity or Dropdown | CPU throttle percentage from 0 to 99, applied after creation and changeable later. |
| `addon_traffic` | Quantity or Dropdown | Traffic Block capacity in GB. For Traffic Block products this overrides `traffic` and the package **Block Size (GB)**. |

`autoBuild` and all Build API options are hidden after service creation. To make a package always build, attach an `autoBuild` option whose only value is `true`. Omit it for a no-build package.

### Create Server API options

| Name | Use |
| --- | --- |
| `hypervisorId` | Overrides the Module Option **Hypervisor Group ID**. |
| `ipv4` | Absolute total IPv4 quantity. Overrides **Default IPv4** and cannot be lower than it. |
| `storage` | Primary disk capacity in GB. |
| `traffic` | Absolute traffic allowance in GB; `0` means unlimited. |
| `memory` | Memory in MB. |
| `cpuCores` | CPU core count. |
| `networkSpeedInbound` | Inbound speed. Accepts Mbps/Gbps or a bare raw API value. Overridden by `networkSpeed`. |
| `networkSpeedOutbound` | Outbound speed. Accepts Mbps/Gbps or a bare raw API value. Overridden by `networkSpeed`. |
| `storageProfile` | Storage-profile ID. |
| `networkProfile` | Network-profile ID. |
| `firewallRulesets` | Comma-separated firewall-ruleset IDs. |
| `hypervisorAssetGroups` | Comma-separated hypervisor asset-group IDs. |
| `additionalStorage1Enable` | Enable or disable the first additional disk. |
| `additionalStorage1Profile` | First additional-disk profile ID. |
| `additionalStorage1Capacity` | First additional-disk capacity in GB. |
| `additionalStorage2Enable` | Enable or disable the second additional disk. |
| `additionalStorage2Profile` | Second additional-disk profile ID. |
| `additionalStorage2Capacity` | Second additional-disk capacity in GB. |

Use `additionalIpv4` for a normally priced Extra IP quantity. Use `ipv4` only when the Configurable Option value should represent the complete API quantity. `ipv4` takes priority if both are attached.

`networkSpeed`, `networkSpeedInbound`, `networkSpeedOutbound`, placement, profiles, and storage layout are create-only. They are hidden on service-edit forms because the current VirtFusion API does not provide matching edit operations.

### Build Server API options

These names are used only when `autoBuild=true`:

| Name | Use |
| --- | --- |
| `operatingSystemId` | Required VirtFusion operating-system template ID. |
| `sshKeys` | Comma-separated VirtFusion SSH-key IDs. |
| `email` | Enable or disable the VirtFusion build email. |
| `swap` | Swap value passed to VirtFusion. |

When `autoBuild` is absent or `false`, the order form hides hostname, `operatingSystemId`, `sshKeys`, `email`, and `swap`. Blesta still stores the VirtFusion server ID so the unbuilt server can be managed later. The package module's **Has IPv6** field is copied into each new service as `virtfusion_ipv6_available`; legacy `ipv6` Configurable Options are ignored and hidden.

Staff can change this capability for an individual service under Advanced Options. When the service has IPv6, clients may choose to enable it during installation or reinstallation. The client cannot change whether the service has the capability, and IPv6 already enabled in VirtFusion is never disabled by a reinstall.

VNC is not a Configurable Option. It is enabled only when the client or administrator opens the console from the service Manage page. The noVNC popup supports reconnect, full screen, fit-to-window, view-only mode, remote display resizing, Ctrl+Alt+Del, and clipboard exchange. Closing it through the provided button also disables VNC.

## Service upgrades and downgrades

Use Blesta's normal service-change and configurable-option upgrade process. Blesta handles pricing, invoicing, and proration before the module applies the paid change to VirtFusion.

For a downgrade, Blesta calculates the unused-time difference as a negative prorated total. To automatically place that amount in the customer's Blesta account balance, enable **Settings > Company > Billing/Payment > Invoice and Charge Options > Allow Prorated Credits to be Issued for Service Downgrades**. A client-group setting can override the company default. This creates in-house account credit; it is not an automatic refund to the original payment method. If the option is disabled, Blesta applies the downgrade without issuing the negative difference as credit.

The module can change:

- memory;
- CPU cores;
- traffic allowance;
- additional traffic;
- IPv4 quantity;
- backup plan;
- CPU throttle;
- the selected VirtFusion package.

Disk restrictions:

- a `storage` Configurable Option cannot be reduced;
- an existing primary disk cannot be resized directly through the `storage` option;
- when changing to a smaller VirtFusion package, the current primary disk is preserved and is not shrunk.

Memory, CPU, and VirtFusion package changes show **Resource changed; restart recommended**. The module does not restart the server automatically.

A pending VirtFusion resource task does not lock Boot, Restart, Shutdown, or Power Off because a power transition may be required to apply the change. While VirtFusion reports a task as actively executing, power actions are temporarily disabled to avoid submitting a competing operation.

## One-time Traffic Block products

Traffic Blocks are separate one-time child services. They are not server upgrades and do not change the server package.

To create a Traffic Block product:

1. Create another Blesta package using this module.
2. Set **Product Type** to **Traffic Block**.
3. Enter the package's default **Block Size (GB)**. Displayed TB values use 1024 GB per TB; for example, `1024` displays and provisions as `1 TB`.
4. Add one-time pricing only.
5. Optionally add a Quantity or Dropdown Configurable Option named `traffic` or `addon_traffic`. The submitted positive whole-number GB value overrides the fixed Block Size. If both are present, `addon_traffic` takes priority.

Install the separate [Service Extras](https://github.com/HomuraNetwork/plugin-service_extras) plugin to offer the Traffic Block after a server has been created. Create a rule, select the allowed parent server packages, select the product group containing the Traffic Block package, and explicitly add the Traffic Block package to the rule's offered products. The module identifies the action from the package's **Product Type**; no capability name is configured in the plugin.

Service Extras displays the selected Traffic Block products before calling this module. When the customer requests a purchase preview, the module confirms that the parent package is a VirtFusion Server product, validates the selected capacity and billing period, and reads the current VirtFusion traffic period for the review.

The purchase confirmation appends a module-provided notice containing the estimated VirtFusion traffic reset date and explains that the Traffic Block remains valid until that date. Service Extras writes that time to the child service's scheduled cancellation date. If the VirtFusion period changes before payment, the module updates the service to the actual activation-period end before submitting the Traffic Block. Blesta then closes the child service through its normal cancellation automation. The module does not attempt to remove the remote Traffic Block because VirtFusion already controls its lifetime through the traffic period.

Pricing is defined by the Blesta package and optional Configurable Option. The module does not calculate the price. The created service name includes the final capacity, such as **100 GB Traffic Block** or **1 TB Traffic Block**.

## Service Manage page

The client and administrator Manage pages show available VirtFusion information, including:

- power and build state;
- pending tasks;
- CPU, memory, disk, package, and port speed;
- traffic usage, Traffic Blocks, and traffic reset date;
- IPv4 and IPv6 addresses;
- backup plan and recent backup information;
- restart recommendation and build-status warnings.

Available actions include boot, shutdown, power off, restart, password reset, VNC access, IP management, status refresh, and opening the server in VirtFusion. Boot is shown only while the server is stopped; restart, shutdown, and power off are shown only while it is running.

IP addresses, refresh, and eligible additional-IP removal are contained in the Manage page; there is no separate IP Addresses tab. Port speed is displayed automatically in Mbps or Gbps. Services without a hostname use a random public UUID label rather than exposing the sequential VirtFusion server ID.

The administrator link opens the configured VirtFusion admin server URL and does not create a client login session. The client link may use VirtFusion's client login bridge.

The traffic reset date shown by the module comes from VirtFusion. Changing the Blesta renewal date or granting extra service days does not change that VirtFusion date.

The Manage page uses PDT templates only for its initial structure and action results. Timed status checks and password-job checks use dedicated, authenticated JSON responses; the browser updates existing text, values, progress bars, address lists, visibility, and disabled states in place. Active tasks, password results, and build progress appear as scrollable overlays inside the Manage panel, while VNC remains a dismissible popup. Action requests return filtered state plus only their own optional result instead of re-rendering the complete service tab.

### Blesta Advanced Options

Blesta 5.11 and later add an **Advanced Options** tab to every non-canceled service. This is a Blesta maintenance feature, not a module configuration page. It edits service dates and raw Service Fields without the normal module workflow, so the values should normally be left unchanged. Access can be limited under **Settings > System > Staff > Staff Groups** by disabling **Advanced Edit Service** for the relevant staff group.

This module stores the primary IPv4 address in `virtfusion_primary_ipv4`, all remaining IPv4 addresses in `virtfusion_secondary_ipv4`, and provisioning state in `virtfusion_build_state`. IPv4 quantities are calculated from the address list. Updating to version `2026.07.18.6` migrates the older IP and build-status fields automatically.

If a package welcome email uses the official module's old IP tags, replace `{service.virtfusion_ip}` with `{service.virtfusion_primary_ipv4}` and `{service.virtfusion-base_ips}` with `{service.virtfusion_secondary_ipv4}`.

## Links

- [VirtFusion API documentation](https://docs.virtfusion.com/api/openapi.yaml)
- [Official VirtFusion Blesta module](https://github.com/blesta/module-virtfusion_direct_provisioning)
- [VirtFusion Direct Provisioning Mod repository](https://github.com/HomuraNetwork/module-virtfusion_direct_provisioning_mod)
