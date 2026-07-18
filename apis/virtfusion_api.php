<?php

class VirtfusionApi
{
    private $api_token;
    private $hostname;

    private $port;

    private $verify_ssl;

    private $last_request = ['url' => null, 'args' => null];

    public function __construct($api_token, $hostname, $port = 443, $verify_ssl = true)
    {
        $this->api_token = $api_token;
        $this->hostname = $hostname;
        $this->port = $port;
        $this->verify_ssl = (bool) $verify_ssl;
    }

    public function get_query($query = '')
    {
        return $this->submit(ltrim($query, '/'));
    }

    public function submit($command, $type = 'GET', array $args = [])
    {
        $url = 'https://' . $this->hostname . ':' . $this->port . '/api/v1/' . $command;

        $this->last_request = [
            'url' => $url,
            'args' => $args
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if (!empty($args)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));
        }

        switch ($type) {
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verify_ssl ? 2 : 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-type: application/json; charset=utf-8',
            'authorization: Bearer ' . $this->api_token
        ]);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        return [
            'info' => $info,
            'response' => $response,
            'error' => $error,
            'errno' => $errno
        ];
    }

    public function lastRequest()
    {
        return $this->last_request;
    }

    public function loadCommand($command)
    {
        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'commands' . DIRECTORY_SEPARATOR . $command . '.php';
    }
}
