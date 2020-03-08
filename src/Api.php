<?php

namespace EverythingNow\Krusher;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Api
{
    /**
     *
     * @var object
     */
    public $client;

    /**
     *
     * @var object|null
     */
    private static $instance;

    /**
     * 
     */
    public function __construct()
    {
        $this->client = new Client([
                'base_uri' => config('krusher.api_url'),
                'verify' => false,
                'auth' => [
                    config('krusher.login'), 
                    config('krusher.password')
                ],
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);
    }

    /**
     *
     * @param string $url
     * @param string $method
     * @return object
     */
    public function send(string $url, string $method = 'GET'): object
    {
        $response = $this->client->request($method, $url);

        return json_decode($response->getBody());
    }

    /**
     *
     * @param string $url
     * @param string $method
     * @param object $data
     * @return object|null
     */
    public function sendJson(string $url, string $method = 'GET', object $data)
    {
        $response = $this->client->request($method, $url, ['body' => json_encode($data)]);

        return json_decode($response->getBody());
    }

    /**
     *
     * @param string $fullName
     * @param array $contacts
     * @param int $clientId
     * @param int $resId
     * @return object
     */
    public function saveClient(string $fullName, array $contacts, int $clientId = null, int $resId = null): object
    {
        $client = (object)[
            'clName'        => $fullName,
            'IsPerson'      => true,
            'isActive'      => true,
            'Comment'       => null,
            'ffID'          => config('krusher.api_file_id'),
            'ParentID'      => null,
            'responsibleID' => $resId,
            'CompanyID'     => null,
            'TaxCode'       => null,
            'address'       => null,
            'contacts'      => $contacts
        ];

        try {

            if($clientId){ // ------Updating------

                $client->clID = $clientId;
                $client->HIID = $this->send('crm/clients/save/' . $client->clID)->HIID;

                $this->sendJson('crm/clients/save/'. $client->clID, 'PUT', $client);
                
            } else { // ------Creating------

                $response = $this->sendJson('crm/clients/save', 'POST', $client);
                $client->clID = $response->clID;    
            }

            $client->updated_at = now();

        } catch (RequestException $e) {
            $client->clID       = $clientId;
            $client->updated_at = null;
        }

        return $client;
    }

    /**
     *
     * @param array $scenarios
     * @return boolean
     */
    public function setupAutoDials($scenarios)
    {
        foreach($scenarios as $scenario){

            $dataFind = (object)[
                'id_scenario' => config('krusher.scenarios')[$scenario]['scenario_id'],
                'ffID'        => config('krusher.scenarios')[$scenario]['file_id'],
            ];

            $result = $this->sendJson('ast/autodial/process/find', 'POST', $dataFind);

            if(!$result){
                return false;
            }
            
            $autodial = $result[0];
            $autodial->planDateBegin    = today()->setTimeFrom($autodial->planDateBegin)->toDateTimeLocalString();
            $autodial->process          = 101602; // krusher autodail process static id
            $autodial->errorDescription = null;
            $autodial->called           = null;
            
            unset($autodial->Created, $autodial->Changed, $autodial->offset, $autodial->limit);

            $this->sendJson('ast/autodial/process', 'PUT', $autodial);
        }

        return true;
    }

    /**
     *
     * @param array $clients
     * @param string $scenario
     * @return void
     */
    public function putClientsForAutoDial($clients, $scenario)
    {
        foreach($clients as $id){
            $client = $this->send('crm/clients/save/' . $id);
            
            if(!$client){
                continue;
            }

            $client->ffID     = config('krusher.scenarios.'. $scenario . '.file_id');
            $client->isActive = true;
            $client->IsPerson = true;
            $client->contacts = [];
            isset($client->responsibleID) ?: $client->responsibleID = null;

            unset($client->HIID, $client->clID, $client->IsActive);
            
            $contacts = $this->sendJson('crm/contacts/find', 'POST', (object)['clID' => $id]);

            foreach($contacts as $key => $contact){
                $contact = [
                    'ccName'    => $contact->ccName,
                    'ccComment' => $contact->ccComment ?: null,
                    'ccType'    => $contact->ccType,
                    'isPrimary' => $contact->isPrimary
                ];

                if($contact['isPrimary']){
                    array_push($client->contacts, $contact);
                } else {
                    array_unshift($client->contacts, $contact);
                }
            }

            $this->sendJson('crm/clients/save', 'POST', $client);
        }
    }

    /**
     *
     * @param array $scenarios
     * @return void
     */
    public function removeAutoDialClients($scenarios)
    {
        foreach($scenarios as $scenario){
            $fileId = config('krusher.scenarios')[$scenario]['file_id'];
            $this->client->request('DELETE', 'crm/files/clear/' . $fileId);
        }
    }

    /**
     *
     * @return integer
     */
    public static function getDcID(): int
    {
        if(is_null(self::$instance))
        {
            self::$instance = new self();
        }

        $response = self::$instance->send('us/sequence/next/dcID');

        return $response->seqValue;
    }
}