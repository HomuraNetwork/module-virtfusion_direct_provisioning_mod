<?php
/**
 * en_us language for the VirtFusion Direct Provisioning module.
 */
// Basics
$lang['VirtfusionDirectProvisioningMod.name'] = 'VirtFusion Direct Provisioning Mod';
$lang['VirtfusionDirectProvisioningMod.description'] = 'A compatibility-focused VirtFusion provisioning module with pre-build, configurable resources, migration, and server management extensions.';
$lang['VirtfusionDirectProvisioningMod.module_row'] = 'Server';
$lang['VirtfusionDirectProvisioningMod.module_row_plural'] = 'Servers';
$lang['VirtfusionDirectProvisioningMod.module_group'] = 'Server Group';

$lang['Virtfusion.back_to_manage'] = 'Back';

// Module management
$lang['VirtfusionDirectProvisioningMod.add_module_row'] = 'Add Server';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows_title'] = 'Servers';

$lang['VirtfusionDirectProvisioningMod.manage.module_rows_heading.name'] = 'Name';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows_heading.hostname'] = 'Hostname';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows_heading.api_token'] = 'API Token';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows_heading.options'] = 'Options';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows.edit'] = 'Edit';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows.delete'] = 'Delete';
$lang['VirtfusionDirectProvisioningMod.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this Server';

$lang['VirtfusionDirectProvisioningMod.manage.module_rows_no_results'] = 'There are no Servers.';

$lang['VirtfusionDirectProvisioningMod.sync.heading'] = 'Official Module Data Sync';
$lang['VirtfusionDirectProvisioningMod.sync.description'] = 'Move module rows, groups, packages, and module metadata between the official module and this mod. Existing services keep the same module row IDs and remote servers are not changed.';
$lang['VirtfusionDirectProvisioningMod.sync.from_official'] = 'Sync from official';
$lang['VirtfusionDirectProvisioningMod.sync.to_official'] = 'Sync to official';
$lang['VirtfusionDirectProvisioningMod.sync.open_destination'] = 'Open destination module';
$lang['VirtfusionDirectProvisioningMod.sync.confirm.from_official'] = 'Move all official VirtFusion module ownership to this mod?';
$lang['VirtfusionDirectProvisioningMod.sync.confirm.to_official'] = 'Move all mod ownership back to the official VirtFusion module?';
$lang['VirtfusionDirectProvisioningMod.sync.success.from_official'] = 'Official module data now belongs to the mod. Existing services continue using their original module row IDs.';
$lang['VirtfusionDirectProvisioningMod.sync.success.to_official'] = 'Mod data now belongs to the official module. Existing services continue using their original module row IDs.';
$lang['VirtfusionDirectProvisioningMod.sync.error.modules_missing'] = 'Both the official module and the mod must be installed for synchronization.';
$lang['VirtfusionDirectProvisioningMod.sync.error.destination_not_empty'] = 'The destination module already owns rows, groups, packages, or metadata. Synchronization was stopped to avoid merging data.';
$lang['VirtfusionDirectProvisioningMod.sync.error.database'] = 'The database synchronization failed and was rolled back.';
$lang['VirtfusionDirectProvisioningMod.sync.error.service_fields'] = 'Service #%1$s could not be converted to the field layout required by the official module. Synchronization was not started.';
$lang['VirtfusionDirectProvisioningMod.sync.warning.service_fields'] = 'Ownership was synchronized, but service #%1$s kept its official field layout because field cleanup failed. The mod can continue to manage it.';

$lang['VirtfusionDirectProvisioningMod.order_options.first'] = 'First';

// Add row
$lang['VirtfusionDirectProvisioningMod.add_row.box_title'] = 'VirtFusion Direct Provisioning Mod - Add Server';
$lang['VirtfusionDirectProvisioningMod.add_row.add_btn'] = 'Add Server';


// Edit row
$lang['VirtfusionDirectProvisioningMod.edit_row.box_title'] = 'VirtFusion Direct Provisioning Mod - Edit Server';
$lang['VirtfusionDirectProvisioningMod.edit_row.edit_btn'] = 'Update Server';


// Row meta
$lang['VirtfusionDirectProvisioningMod.row_meta.name'] = 'Name';
$lang['VirtfusionDirectProvisioningMod.row_meta.hostname'] = 'Hostname';
$lang['VirtfusionDirectProvisioningMod.row_meta.api_token'] = 'API Token';
$lang['VirtfusionDirectProvisioningMod.row_meta.admin_server_url'] = 'Admin Server URL Template';
$lang['VirtfusionDirectProvisioningMod.row_meta.admin_server_url.help_text'] = 'Used for the admin-only direct server link without creating a client login token. HTTPS and the configured VirtFusion hostname are required. Available placeholders: {hostname}, {server_id}.';
$lang['VirtfusionDirectProvisioningMod.row_meta.allow_insecure_tls'] = 'Allow insecure TLS certificates';
$lang['VirtfusionDirectProvisioningMod.row_meta.allow_insecure_tls.help_text'] = 'Not recommended. Disables certificate verification only for this module row and should be used only with a trusted self-signed VirtFusion endpoint.';




// Errors
$lang['VirtfusionDirectProvisioningMod.!error.name.empty'] = 'Please enter a valid name';
$lang['VirtfusionDirectProvisioningMod.!error.hostname.empty'] = 'Please enter a valid Hostname';
$lang['VirtfusionDirectProvisioningMod.!error.api_token.empty'] = 'Please enter API Token';
$lang['VirtfusionDirectProvisioningMod.!error.hostname.valid'] = 'Invalid Hostname';
$lang['VirtfusionDirectProvisioningMod.!error.api_token.valid'] = 'Invalid API Token';
$lang['VirtfusionDirectProvisioningMod.!error.admin_server_url.valid'] = 'The admin server URL must use HTTPS and the configured VirtFusion hostname.';
$lang['VirtfusionDirectProvisioningMod.!error.operation.persist'] = 'The operation state could not be saved safely. No further remote changes were attempted.';
$lang['VirtfusionDirectProvisioningMod.!error.module_row.missing'] = 'An internal error occurred. The module row is unavailable.';
$lang['VirtfusionDirectProvisioningMod.!error.meta[hypervisor_group_id].valid'] = 'Invalid Hypervisor Group ID.';
$lang['VirtfusionDirectProvisioningMod.!error.meta[default_ipv4].valid'] = 'Invalid number of IPv4 addresses.';
$lang['VirtfusionDirectProvisioningMod.!error.meta[package_id].valid'] = 'Invalid package id.';
$lang['VirtfusionDirectProvisioningMod.!error.server_id.valid'] = 'Invalid server ID.';
$lang['VirtfusionDirectProvisioningMod.!error.label.valid'] = 'Invalid service label.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.numeric'] = 'The %1$s configurable option must be numeric.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.os_template.required'] = 'Select a valid operatingSystemId when Auto Build is enabled.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.ipv4.minimum'] = 'The ipv4 quantity must be at least 1.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.ipv4.package_minimum'] = 'The ipv4 quantity cannot be lower than the package Default IPv4 value.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.ipv4.additional_minimum'] = 'additionalIpv4 must be zero or greater.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.cpu_throttle.range'] = 'cpuThrottle must be between 0 and 99.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.create_only'] = '%1$s can only be selected when the service is created.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.network_speed.format'] = 'Network speed must be a non-negative raw API value or use Mbps/Gbps, for example 100 Mbps or 1 Gbps.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.hypervisor.minimum'] = 'hypervisorId must be at least 1.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.memory.minimum'] = 'Memory must be at least 256 MB.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.cpu.minimum'] = 'CPU cores must be at least 1.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.cpu.maximum'] = 'VirtFusion supports at most 600 CPU cores.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.traffic.minimum'] = 'Traffic must be zero or greater.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.traffic.maximum'] = 'VirtFusion traffic must not exceed 999999999 GB.';
$lang['VirtfusionDirectProvisioningMod.!error.configoption.network_speed.edit'] = 'VirtFusion does not provide a documented API for changing a custom network speed after creation. Change to a VirtFusion package with the required speed instead.';
$lang['VirtfusionDirectProvisioningMod.!error.storage.downgrade'] = 'Primary disk storage cannot be reduced.';
$lang['VirtfusionDirectProvisioningMod.storage.ticket_required'] = 'The storage included with this service does not match the disk currently provisioned in VirtFusion. Open a support ticket to complete the required disk adjustment.';
$lang['VirtfusionDirectProvisioningMod.storage.expected'] = 'Service storage';
$lang['VirtfusionDirectProvisioningMod.storage.actual'] = 'VirtFusion disk';
$lang['VirtfusionDirectProvisioningMod.storage.open_ticket'] = 'Open Support Ticket';
$lang['VirtfusionDirectProvisioningMod.!error.resource.current'] = 'The current VirtFusion server resources could not be retrieved.';
$lang['VirtfusionDirectProvisioningMod.!error.resource.memory'] = 'VirtFusion did not accept the memory change.';
$lang['VirtfusionDirectProvisioningMod.!error.resource.cpu'] = 'VirtFusion did not accept the CPU core change.';
$lang['VirtfusionDirectProvisioningMod.!error.resource.traffic'] = 'VirtFusion did not accept the traffic allowance change.';
$lang['VirtfusionDirectProvisioningMod.!error.package.target'] = 'The target VirtFusion package could not be retrieved.';
$lang['VirtfusionDirectProvisioningMod.!error.package.change'] = 'VirtFusion did not accept the package change.';
$lang['VirtfusionDirectProvisioningMod.!error.tasks.pending'] = 'VirtFusion has a task that conflicts with this action. Wait for it to finish before trying again.';
$lang['VirtfusionDirectProvisioningMod.!error.tasks.active'] = 'VirtFusion is currently building or updating this server. Only Manage Server is available until it finishes.';
$lang['VirtfusionDirectProvisioningMod.!error.password.missing'] = 'VirtFusion accepted the password reset but did not return the new password.';
$lang['VirtfusionDirectProvisioningMod.!error.vnc.details'] = 'VirtFusion did not return the VNC WebSocket URL and password.';
$lang['VirtfusionDirectProvisioningMod.!error.vnc.disable'] = 'VirtFusion could not disable the VNC console.';
$lang['VirtfusionDirectProvisioningMod.!error.server.not_built'] = 'This server has not been built yet. Only Manage Server is available.';
$lang['VirtfusionDirectProvisioningMod.!error.service_fields.migration'] = 'The Service Fields for service #%1$s could not be migrated to the current schema.';
$lang['VirtfusionDirectProvisioningMod.!error.api.action'] = 'The VirtFusion action failed unexpectedly. Review the module log for the non-sensitive exception details.';
$lang['VirtfusionDirectProvisioningMod.!error.meta.service_type.valid'] = 'Select a valid VirtFusion product type.';
$lang['VirtfusionDirectProvisioningMod.!error.meta.traffic_block_gb.valid'] = 'Enter a positive whole number for the Traffic Block size in GB.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.parent'] = 'A Traffic Block requires an active, provisioned parent server service.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.amount'] = 'The Traffic Block size must be a positive whole number of GB.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.server_id'] = 'The parent service does not contain a valid VirtFusion server ID.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.module_row'] = 'The Traffic Block must use the same VirtFusion module row as its parent server.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.pending_required'] = 'For safe one-time provisioning, create and invoice the Traffic Block as a pending service before activation.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.locked'] = 'Another Traffic Block operation is running for this server. Try again after it finishes.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.conflict'] = 'The saved Traffic Block operation does not match this activation request. Manual review is required.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.unknown'] = 'VirtFusion may have accepted this Traffic Block, but the result cannot be identified uniquely. Automatic repurchase is disabled; an administrator must reconcile the saved operation with VirtFusion.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.reconcile'] = 'Only an unknown Traffic Block operation can be reconciled.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.ambiguous'] = 'Multiple matching Traffic Blocks were found. Automatic reconciliation remains disabled.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.period_changed'] = 'The original VirtFusion traffic period has ended. This operation cannot be retried into a different period.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.period'] = 'VirtFusion did not return a current traffic billing period for this server.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.expiry_save'] = 'The Traffic Block service end date could not be updated safely. No remote Traffic Block was submitted.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.onetime'] = 'Traffic Block package pricing must use the one-time period.';
$lang['VirtfusionDirectProvisioningMod.!error.traffic_block.immutable'] = 'A purchased Traffic Block is immutable and cannot be upgraded or downgraded.';
$lang['VirtfusionDirectProvisioningMod.!error.resource.locked'] = 'Another resource change is running for this server. Try again after it finishes.';
$lang['VirtfusionDirectProvisioningMod.!error.resource.conflict'] = 'A different incomplete resource change is already recorded for this service. Reconcile or retry that change first.';
$lang['VirtfusionDirectProvisioningMod.!error.resource.partial'] = 'VirtFusion did not complete every resource change. The actual remote resources and completed steps were recorded; retry the same change to continue safely.';
$lang['VirtfusionDirectProvisioningMod.!error.service_extra.package'] = 'The selected package is not an available service extra for this parent service.';

// Client Errors
$lang['VirtfusionDirectProvisioningMod.client.!error.host.valid'] = 'The hostname appears to be invalid.';

// Service info
$lang['VirtfusionDirectProvisioningMod.service_info.server_id'] = 'Server ID';
$lang['VirtfusionDirectProvisioningMod.service_info.main_ip'] = 'Main IP Address';
$lang['VirtfusionDirectProvisioningMod.service_info.base_ips'] = 'Base IP Addresses';
$lang['VirtfusionDirectProvisioningMod.service_info.extra_ips'] = 'Extra IP Addresses';
$lang['VirtfusionDirectProvisioningMod.service_info.secondary_ipv4'] = 'Other IPv4 Addresses';
$lang['VirtfusionDirectProvisioningMod.service_info.label'] = 'Label';
$lang['VirtfusionDirectProvisioningMod.service_info.overview'] = 'Overview';
$lang['VirtfusionDirectProvisioningMod.service_info.resources'] = 'Resources';
$lang['VirtfusionDirectProvisioningMod.service_info.traffic'] = 'Traffic';
$lang['VirtfusionDirectProvisioningMod.service_info.status'] = 'Status';
$lang['VirtfusionDirectProvisioningMod.service_info.cpu'] = 'CPU';
$lang['VirtfusionDirectProvisioningMod.service_info.memory'] = 'Memory';
$lang['VirtfusionDirectProvisioningMod.service_info.disk'] = 'Disk';
$lang['VirtfusionDirectProvisioningMod.service_info.latest_backup'] = 'Latest Backup';
$lang['VirtfusionDirectProvisioningMod.service_info.traffic_resets'] = 'Traffic Resets';
$lang['VirtfusionDirectProvisioningMod.service_info.traffic_used'] = 'Traffic Used';
$lang['VirtfusionDirectProvisioningMod.service_info.traffic_total'] = 'Traffic Total';
$lang['VirtfusionDirectProvisioningMod.service_info.traffic_server'] = 'Server Traffic';
$lang['VirtfusionDirectProvisioningMod.service_info.traffic_blocks'] = 'Traffic Blocks';
$lang['VirtfusionDirectProvisioningMod.service_info.package'] = 'Blesta Package';
$lang['VirtfusionDirectProvisioningMod.service_info.port_speed'] = 'Port Speed (Inbound / Outbound)';
$lang['VirtfusionDirectProvisioningMod.service_info.port_speed_inbound'] = 'Port Speed Inbound';
$lang['VirtfusionDirectProvisioningMod.service_info.port_speed_outbound'] = 'Port Speed Outbound';
$lang['VirtfusionDirectProvisioningMod.service_info.backups'] = 'Backups / Latest';
$lang['VirtfusionDirectProvisioningMod.service_info.backup_plan'] = 'Backup Plan';
$lang['VirtfusionDirectProvisioningMod.service_name.traffic_block'] = '%1$s Traffic Block';
$lang['VirtfusionDirectProvisioningMod.service_name.server'] = 'VirtFusion Server';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.amount'] = 'Traffic Block';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.server_id'] = 'Parent VirtFusion Server ID';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.period'] = 'Valid Until';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.block_id'] = 'VirtFusion Block ID';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.operation_status'] = 'Provisioning Operation';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.operation_key'] = 'Operation Key';
$lang['VirtfusionDirectProvisioningMod.traffic_block_info.pending'] = 'Pending payment / activation';
$lang['VirtfusionDirectProvisioningMod.traffic_block.reconcile'] = 'Reconcile Traffic Block';
$lang['VirtfusionDirectProvisioningMod.traffic_block.reconcile_help'] = 'First verify the operation in VirtFusion. This button performs one more read-only check; it only allows the next provisioning retry when no matching block exists and the original traffic period is still current.';
$lang['VirtfusionDirectProvisioningMod.traffic_block.confirm_absent'] = 'Confirm no block exists';
$lang['VirtfusionDirectProvisioningMod.traffic_block.reconcile_found'] = 'A unique matching Traffic Block was found and linked. The next provisioning attempt can activate the Blesta service without another purchase.';
$lang['VirtfusionDirectProvisioningMod.traffic_block.retry_confirmed'] = 'No matching block exists. The next provisioning attempt is authorized to retry once for the original traffic period.';

// Service Fields
$lang['VirtfusionDirectProvisioningMod.service_fields.server_id'] = 'Server ID';
$lang['VirtfusionDirectProvisioningMod.service_fields.label'] = 'Label';


// Manage
$lang['VirtfusionDirectProvisioningMod.tabManage'] = 'Manage';
$lang['VirtfusionDirectProvisioningMod.tabManage.header'] = 'Manage';
$lang['VirtfusionDirectProvisioningMod.tabManage.submit'] = 'Submit';
$lang['VirtfusionDirectProvisioningMod.tabManage.actions'] = 'Actions';
$lang['VirtfusionDirectProvisioningMod.tabManage.manage'] = 'Manage Server';
$lang['VirtfusionDirectProvisioningMod.tabManage.manage_as_client'] = 'Manage Server as Client';
$lang['VirtfusionDirectProvisioningMod.tabManage.manage_admin'] = 'Open VirtFusion Admin';
$lang['VirtfusionDirectProvisioningMod.tabManage.boot'] = 'Boot';
$lang['VirtfusionDirectProvisioningMod.tabManage.restart'] = 'Restart';
$lang['VirtfusionDirectProvisioningMod.tabManage.shutdown'] = 'Shutdown';
$lang['VirtfusionDirectProvisioningMod.tabManage.poweroff'] = 'Power Off';
$lang['VirtfusionDirectProvisioningMod.tabManage.reset_password'] = 'Reset Password';
$lang['VirtfusionDirectProvisioningMod.tabManage.vnc_console'] = 'VNC Console';
$lang['VirtfusionDirectProvisioningMod.tabManage.action_success'] = 'The action was successful.';
$lang['VirtfusionDirectProvisioningMod.tabManage.refresh_state'] = 'Refresh Status';
$lang['VirtfusionDirectProvisioningMod.tabManage.state_refreshed'] = 'Server status refreshed.';
$lang['VirtfusionDirectProvisioningMod.tabManage.vnc_closed'] = 'The VNC console was closed and disabled.';
$lang['VirtfusionDirectProvisioningMod.password_result.heading'] = 'Password Reset Complete';
$lang['VirtfusionDirectProvisioningMod.password_result.notice'] = 'The new password is shown below. Store it now; it may not be displayed again.';
$lang['VirtfusionDirectProvisioningMod.password_result.copy'] = 'Copy Password';
$lang['VirtfusionDirectProvisioningMod.password_result.copied'] = 'Copied';
$lang['VirtfusionDirectProvisioningMod.vnc.heading'] = 'VNC Console';
$lang['VirtfusionDirectProvisioningMod.vnc.connecting'] = 'Connecting...';
$lang['VirtfusionDirectProvisioningMod.vnc.connected'] = 'Connected';
$lang['VirtfusionDirectProvisioningMod.vnc.disconnected'] = 'Disconnected';
$lang['VirtfusionDirectProvisioningMod.vnc.failed'] = 'The VNC connection failed.';
$lang['VirtfusionDirectProvisioningMod.vnc.ready'] = 'The VNC console is ready.';
$lang['VirtfusionDirectProvisioningMod.vnc.reconnect'] = 'Reconnect';
$lang['VirtfusionDirectProvisioningMod.vnc.ctrl_alt_del'] = 'Send Ctrl+Alt+Del';
$lang['VirtfusionDirectProvisioningMod.vnc.fullscreen'] = 'Full Screen';
$lang['VirtfusionDirectProvisioningMod.vnc.exit_fullscreen'] = 'Exit Full Screen';
$lang['VirtfusionDirectProvisioningMod.vnc.fit_window'] = 'Fit to Window';
$lang['VirtfusionDirectProvisioningMod.vnc.view_only'] = 'View Only';
$lang['VirtfusionDirectProvisioningMod.vnc.resize_session'] = 'Resize Remote Display';
$lang['VirtfusionDirectProvisioningMod.vnc.clipboard'] = 'Clipboard';
$lang['VirtfusionDirectProvisioningMod.vnc.clipboard_placeholder'] = 'Remote clipboard text appears here. Edit it to send text to the server.';
$lang['VirtfusionDirectProvisioningMod.vnc.clipboard_help'] = 'Clipboard actions require an active connection.';
$lang['VirtfusionDirectProvisioningMod.vnc.send_clipboard'] = 'Send to Server';
$lang['VirtfusionDirectProvisioningMod.vnc.copy_clipboard'] = 'Copy to Device';
$lang['VirtfusionDirectProvisioningMod.vnc.clipboard_received'] = 'Remote clipboard received.';
$lang['VirtfusionDirectProvisioningMod.vnc.clipboard_sent'] = 'Clipboard sent to the server.';
$lang['VirtfusionDirectProvisioningMod.vnc.clipboard_copied'] = 'Clipboard copied to this device.';
$lang['VirtfusionDirectProvisioningMod.vnc.open_virtfusion'] = 'Open in VirtFusion';
$lang['VirtfusionDirectProvisioningMod.vnc.close'] = 'Close and Disable VNC';
$lang['VirtfusionDirectProvisioningMod.build_reconciliation_required'] = 'The automatic build result could not be confirmed. The server was retained so it remains manageable; check its build state in VirtFusion before rebuilding or canceling it.';
$lang['VirtfusionDirectProvisioningMod.tasks.pending_restart'] = 'The following changes are waiting to be applied. Restart or power cycle the server to continue:';
$lang['VirtfusionDirectProvisioningMod.tasks.building'] = 'VirtFusion is building or updating this server. Please wait for it to finish. You can still open the VirtFusion control panel.';
$lang['VirtfusionDirectProvisioningMod.tasks.refreshing'] = 'Checking again in 5 seconds';
$lang['VirtfusionDirectProvisioningMod.confirm.power'] = 'Are you sure you want to perform this power action?';
$lang['VirtfusionDirectProvisioningMod.confirm.reset_password'] = 'Reset the server password? The new password will be displayed once on this page and may also be emailed.';


// Manage IP Address
$lang['VirtfusionDirectProvisioningMod.ipAddresses'] = 'IP Addresses';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.header'] = 'IP Addresses';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.main'] = 'Main IP Address';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.base'] = 'Base IP Addresses';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.extra'] = 'Additional IP Addresses';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.ipv6'] = 'IPv6 Address';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.add'] = 'Add IP';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.remove'] = 'Remove IP';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.submit'] = 'Submit';

$lang['VirtfusionDirectProvisioningMod.ipAddresses.ipv6_refresh'] = 'Refresh IPv6';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.refresh'] = 'Refresh IP Addresses';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.none'] = 'None assigned';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.refreshed'] = 'IP addresses refreshed.';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.removed'] = 'IP address removed.';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.remove_confirm'] = 'Remove this IP address?';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.remove_invalid'] = 'The selected additional IP address is unavailable.';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.remove_forbidden'] = 'This service does not allow client IP removal.';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.remove_failed'] = 'VirtFusion could not remove the IP address.';
$lang['VirtfusionDirectProvisioningMod.ipAddresses.update_failed'] = 'The IP was removed remotely, but Blesta could not update the service record.';

// Package Fields
$lang['VirtfusionDirectProvisioningMod.package_fields.service_type'] = 'Product Type';
$lang['VirtfusionDirectProvisioningMod.package_fields.service_type.server'] = 'VirtFusion Server';
$lang['VirtfusionDirectProvisioningMod.package_fields.service_type.traffic_block'] = 'Traffic Block';
$lang['VirtfusionDirectProvisioningMod.package_fields.hypervisor_group_id'] = 'Hypervisor Group ID';
$lang['VirtfusionDirectProvisioningMod.package_fields.default_ipv4'] = 'Default IPv4';
$lang['VirtfusionDirectProvisioningMod.package_fields.package_id'] = 'Package ID';
$lang['VirtfusionDirectProvisioningMod.package_fields.traffic_block_gb'] = 'Block Size (GB)';
$lang['VirtfusionDirectProvisioningMod.package_fields.traffic_block_gb.help_text'] = 'The default Traffic Block capacity provisioned by this package.';

// Service Extras
$lang['VirtfusionDirectProvisioningMod.service_extra.period_heading'] = 'Estimated traffic reset date: %1$s';
$lang['VirtfusionDirectProvisioningMod.service_extra.period_notice'] = 'This is a preview. Once activated, the Traffic Block will remain valid until this reset date.';

// Option Fields
$lang['VirtfusionDirectProvisioningMod.option.extra_ip'] = 'ipv4';

// Client Fields
$lang['VirtfusionDirectProvisioningMod.option_fields.hostname.label'] = 'Hostname';
$lang['VirtfusionDirectProvisioningMod.option_fields.hostname.tooltip'] = 'Please enter the name of your server using a fully qualified domain name. For example server.mydomain.com or web.mydomain.com';

$lang['VirtfusionDirectProvisioningMod.option_fields.extra_ip_addresses'] = 'Extra IP Addresses';
$lang['VirtfusionDirectProvisioningMod.option_fields.extra_ip_addresses.tooltip'] = 'This field must be selecting if downgrading the number of extra IPs.';


// Cron Tasks
