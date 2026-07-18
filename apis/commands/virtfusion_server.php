<?php

class VirtfusionServer
{
    private $api;

    public function __construct(VirtfusionApi $api)
    {
        $this->api = $api;
    }

    public function suspend($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/suspend', 'POST', $vars);
    }

    public function unsuspend($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/unsuspend', 'POST', $vars);
    }

    public function cancel($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId, 'DELETE', $vars);
    }

    public function create(array $vars)
    {
        return $this->api->submit('servers/', 'POST', $vars);
    }

    public function build($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/build', 'POST', $vars);
    }

    public function get($serverId, $remoteState = false)
    {
        $path = 'servers/' . $serverId;
        if ($remoteState) {
            $path .= '?remoteState=true';
        }

        return $this->api->submit($path, 'GET');
    }

    public function getPkg($pkgId){

        return $this->api->submit('packages/' . $pkgId, 'GET');
    }

    public function changePkg($serverId, $pkgId, array $vars = [])
    {
        return $this->api->submit('servers/' . $serverId . '/package/' . $pkgId, 'PUT', $vars);
    }

    public function powerAction($serverId, $action)
    {
        return $this->api->submit('servers/' . $serverId . '/power/' . $action, 'POST');
    }

    public function resetPassword($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/resetPassword', 'POST', $vars);
    }

    public function setVnc($serverId, $action)
    {
        return $this->api->submit('servers/' . $serverId . '/vnc', 'POST', ['action' => $action]);
    }

    public function setBackupPlan($serverId, $planId)
    {
        return $this->api->submit('servers/' . $serverId . '/backups/plan/' . (int) $planId, 'PUT');
    }

    public function modifyCpuThrottle($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/modify/cpuThrottle', 'PUT', $vars);
    }

    public function modifyMemory($serverId, $memory)
    {
        return $this->api->submit(
            'servers/' . $serverId . '/modify/memory',
            'PUT',
            ['memory' => (int) $memory]
        );
    }

    public function modifyCpuCores($serverId, $cores)
    {
        return $this->api->submit(
            'servers/' . $serverId . '/modify/cpuCores',
            'PUT',
            ['cores' => (int) $cores]
        );
    }

    public function fetchToken($serverId, $clientId, array $vars)
    {
        return $this->api->submit('users/' . $clientId . '/serverAuthenticationTokens/' . $serverId, 'POST', $vars);
    }
    
    public function modifyPrimaryTraffic($serverId, array $vars) {
        return $this->api->submit('servers/' . $serverId . '/modify/traffic', 'PUT', $vars);
    }
    
    public function getTraffic($serverId) {
        return $this->api->submit('servers/' . $serverId . '/traffic', 'GET');
    }

    public function getTrafficBlocks($serverId)
    {
        return $this->api->submit('servers/' . $serverId . '/traffic/blocks', 'GET');
    }

    public function addTrafficBlock($serverId, $month, $amount)
    {
        return $this->api->submit(
            'servers/' . $serverId . '/traffic/blocks',
            'POST',
            ['month' => (int) $month, 'amount' => (int) $amount]
        );
    }

    public function getBackups($serverId)
    {
        return $this->api->submit('backups/server/' . $serverId, 'GET');
    }

    public function addIpv4Qty($serverId, $qty, $interface = 'primary')
    {
        $vars = [
            'interface' => $interface,
            'quantity' => (int) $qty
        ];

        return $this->api->submit('servers/' . $serverId . '/ipv4Qty', 'POST', $vars);
    }

    public function removeIpv4($serverId, array $ips)
    {
        $vars = [
            'ip' => $ips
        ];

        return $this->api->submit('servers/' . $serverId . '/ipv4', 'DELETE', $vars);
    }
}
