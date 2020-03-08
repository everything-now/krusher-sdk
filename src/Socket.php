<?php

namespace EverythingNow\Krusher;

use \EverythingNow\Krusher\Api;
use \WSSC\Exceptions\ConnectionException;
use \WSSC\Components\ClientConfig;
use \WSSC\WebSocketClient;

class Socket
{
    /**
     * @var object
     */
    protected $actions;

    /**
     * @var object
     */
    protected $config;

    /**
     * @var object
     */
    private $socketConf;
    
    /**
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config  = $this->setConnectConfig($config);
        $this->actions = $this->initializeActions();

        $this->socketConf = new ClientConfig();
        $this->socketConf->setContextOptions([
            'ssl' => [
                'verify_peer' => false, 
                'verify_peer_name' => false
            ]
        ]);
    }

    /**
     *
     * @param array $config
     * @return object
     */
    protected function setConnectConfig(array $config): object
    {
        $config['socket_url'] = config('krusher.socket_url');

        if(!isset($config['sip'])){
            $config['sip'] = config('krusher.sip');
        }

        if(!isset($config['login'])){
            $config['login'] = config('krusher.login');
        }

        return (object) $config;
    }

    /**
     *
     * @param string $phone
     * @return object
     */
    public function makeCall(string $phone): object
    {
        try {
            $client = new WebSocketClient($this->config->socket_url, $this->socketConf);
            $client->send($this->getAction('join'));
            
            while($response = $client->receive()){
                $response = json_decode($response);
                $dcID     = Api::getDcID();
    
                if($response->event == 'joined'){
                    $this->setActionValues([
                        'call' => [
                            'source' => [
                                'ccName' => $phone,
                                'phone'  => $phone,
                                'dcID'   => $dcID
                            ]
                        ]
                    ]);
                                
                    $client->send($this->getAction('call'));
                }
                            
                if($response->event == 'devicestatechange'){
                    $client->close();

                    return (object)['status' => true, 'dcID' => $dcID];
                }
            }
        } catch(ConnectionException $e) {
            return (object)['status' => false];
        }
                    
        return (object)['status' => false];
    }
                
    /**
     *
     * @return object
     */
    protected function initializeActions(): object
    {
        return (object) [
            'join' => [
                'action' => 'join',
                'user'   => [
                    'sip'       => $this->config->sip,
                    'loginName' => $this->config->login,
                ]
            ],
            'call' => [
                'action' => 'call',
                'exten'  => $this->config->sip,
                'source' => [
                    'ccName'   => null,
                    'coID'     => null,
                    'isDial'   => true,
                    'isBridge' => false,
                    'phone'    => null,
                    'dcID'     => null,
                ],
            ]
        ];
    }

    /**
     *
     * @param array $values
     * @return void
     */
    protected function setActionValues(array $values)
    {
        $this->actions = (object) array_replace_recursive((array) $this->actions, $values);
    }

    /**
     *
     * @param string $action
     * @return string
     */
    protected function getAction(string $action): string
    {
        return json_encode($this->actions->$action);
    }

}