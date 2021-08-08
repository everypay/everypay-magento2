<?php
/**
 * Copyright © 2021 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Gateway\Http\Client;

use Exception;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Everypay\Everypay;
use Everypay\Payment;
use Everypay\Customer;
use Psr\Log\LoggerInterface;


class ClientSale implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    /**
     * @var Logger
     */
    private $logger;
    private $epConfig;

    /**
     * @param LoggerInterface $logger
     * @param EverypayConfig $epConfig
     * @param PageFactory $resultPageFactory
     * @param CustomerRepositoryInterface $customerRepositoryInterface
     */
    public function __construct(
        LoggerInterface $logger,
        EverypayConfig $epConfig,
        PageFactory $resultPageFactory,
        CustomerRepositoryInterface $customerRepositoryInterface
    ) {
        $this->logger = $logger;
        $this->epConfig = $epConfig;

        $secretKey = $this->epConfig->getSecretKey();
        $publicKey = $this->epConfig->getPublicKey();
        $sandboxMode = $this->epConfig->getSandboxMode();

        $this->_secretKey = $secretKey;
        $this->_publicKey = $publicKey;
        $this->_sandboxMode = $sandboxMode;

        $this->_customerRepositoryInterface = $customerRepositoryInterface;
        $this->_resultPageFactory = $resultPageFactory;
    }

    /**
     * Places request to gateway. Returns result as ENV array
     *
     * @param TransferInterface $transferObject
     * @return array
     * @throws Exception
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $this->logger->debug('Everypay', [
            'initRequest' => $transferObject->getBody()
        ]);

        Everypay::$isTest = $this->_sandboxMode;
        $requestData = $transferObject->getBody();
        $trxType = $this->checkTrxType($requestData);

        $removed_cards = $requestData['removed_cards'];
        $empty_vault = $requestData['empty_vault'];

        $this->proccessRemovedCards($removed_cards, $empty_vault, $requestData);

        $existing_customer = '';
        $token = $requestData['token'];
        $customerEmail = $requestData['EMAIL'];
        $orderNumber = $requestData['INVOICE'];
        $amount = floatval($requestData['AMOUNT'])*100;

        if (!$token || !$amount) {
            throw new Exception('Token or amount error.');
        }

        $params = array(
            'token'         => $token,
            'amount'        => $amount,
            'payee_email'   => $customerEmail,
            'description'   => 'Order: ' . $orderNumber,
        );

        if ($trxType === 'paySave') {
            $vault = $requestData['everypay_vault'];

            if (!in_array($vault, ['[]', '{}'])) {
                $existing_customer = $this->getEverypayCustomer($requestData['customer_id']);
            }

            if ($existing_customer !== ''){
                $params['customer'] = $existing_customer;
            } else {
                $params['create_customer'] = 1;
            }
        }

        if ($trxType === 'payCustomer'){
            $customerToken = $requestData['customer_token'];
            $cardToken = $requestData['card_token'];
            $params['customer'] = $customerToken;
            $params['card'] = $cardToken;
        }

        Everypay::setApiKey($this->_secretKey);
        $response = Payment::create($params);

        if (isset($response->error))
        {
            $rcode = 0;
            $pmt = 'error';
        }else {
            $rcode = 1;
            $pmt = $response;

            if (isset($pmt->customer) && $trxType === 'paySave') {
                $customerToken = $pmt->customer->token;
                $cardToken = $pmt->card->token;
                $name = $pmt->card->friendly_name;
                $vault = $requestData['everypay_vault'];

                $new_card = [
                    'custToken' => $customerToken,
                    'crdToken' => $cardToken,
                    'name' => $name,
                    'cardExpirationMonth' => $pmt->card->expiration_month,
                    'cardExpirationYear' => $pmt->card->expiration_year,
                    'cardType' => $pmt->card->type,
                    'cardLastFourDigits' => $pmt->card->last_four
                ];

                $this->updateCustomer($requestData['customer_id'], $new_card, $vault);
            }

        }

        $response = $this->generateResponseForCode($rcode, $pmt);

        $this->logger->debug([
             'request' => $transferObject->getBody(),
             'response' => $response
         ]);

        return $response;
    }


    /**
     * @param $data
     * @return string
     */
    protected function checkTrxType($data)
    {
        $trxType = null;

        if($data['token'] !== ''){
            if($data['save_card'] !== ''){
                $trxType = 'paySave';
            }else{
                $trxType = 'pay';
            }
        }

        if($data['customer_token'] !== '' && $data['card_token'] !== '' ){
            $trxType = 'payCustomer';
        }

        if($trxType === null ){
            throw new \InvalidArgumentException('No valid transaction type was found!!!');
        }else{
            return $trxType;
        }

    }

    /**
     * Generates response
     * @param $resultCode
     * @param $pmt
     * @return array
     */
    protected function generateResponseForCode($resultCode, $pmt)
    {

        if($pmt === 'error'){
            $trx_array = [];
        }
        else{
            $trx_array = [
                'RESULT_CODE' => $resultCode,
                'TXN_ID' => $pmt->token,
                'CUSTOMER_TOKEN' => isset($pmt->customer)?$pmt->customer->token:'',
                'CARD_TOKEN' => isset($pmt->customer)?$pmt->card->token:'',
                'FRIENDLY_NAME' => isset($pmt->customer)?$pmt->card->friendly_name:''
            ];
        }
        return array_merge(
            $trx_array,
            $this->getFieldsBasedOnResponseType($resultCode)
        );
    }

    /**
     * @return string
     */
    protected function generateTxnId()
    {
        return md5(mt_rand(0, 1000));
    }


    /**
     * Returns response fields for result code
     *
     * @param int $resultCode
     * @return array
     */
    private function getFieldsBasedOnResponseType($resultCode)
    {
        switch ($resultCode) {
            case self::FAILURE:
                return [
                    'FRAUD_MSG_LIST' => [
                        'Stolen card',
                        'Customer location differs'
                    ]
                ];
        }

        return [];
    }

    private function updateCustomer($customer_id, $saved_card, $vault)
    {
        $customerId = $customer_id;
        if ($customerId) {
            $customer = $this->_customerRepositoryInterface->getById($customerId);

            $vault_data = json_decode($vault,true);
            $vault_data[] = $saved_card;
            $everypay_vault['cards'] = $vault_data;

            $vault_data = json_encode($everypay_vault);

            $customer->setCustomAttribute('everypay_vault', $vault_data);
            $this->_customerRepositoryInterface->save($customer);

        }
    }

    private function getEverypayCustomer($customer_id)
    {
        $customerId = $customer_id;
        if ($customerId) {
            $customer = $this->_customerRepositoryInterface->getById($customerId);
            $vault = $customer->getCustomAttribute('everypay_vault')->getValue();

            if($vault === null){
                return '';
            }

            $vault = json_decode($vault,true);

            if (key_exists('cards',$vault)){
                return $vault['cards'][0]['custToken'];
            }
        }
        return '';
    }

    private function deleteEverypayCustomerCard($username, $cus_token, $crd_token, $vault)
    {
        if($this->_sandboxMode){
            $server = 'sandbox-api.everypay.gr';
        }else{
            $server = 'api.everypay.gr';
        }


        $vault = json_decode($vault,true);
        $new_default_card = $vault[0]['crdToken'];

        $data = [
            'card' => $new_default_card,
            'default_card' => 1
        ];

        $post_data = http_build_query($data);

        $url = "https://".$server."/customers/".$cus_token;
        $username = $username.":";
        $action = 'POST';

        $xcurl = curl_init();

        curl_setopt_array($xcurl, array(
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $username,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $action,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_HTTPHEADER => array(
                "cache-control: no-cache"
            ),
        ));


        $response = curl_exec($xcurl);
        $err = curl_error($xcurl);

        curl_close($xcurl);

        if ($err) {
            throw new \InvalidArgumentException('UPDATE DEFAULT CARD TO CUSTOMER ERROR >>> URL: '.$url.  ' ERROR: '. $err .' DATA: '.$post_data);
        }

        $url = "https://".$server."/customers/".$cus_token."/card/".$crd_token;
        $action = 'DELETE';

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $username,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $action,
            CURLOPT_HTTPHEADER => array(
                "cache-control: no-cache"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new \InvalidArgumentException('REMOVE CARD FROM CUSTOMER ERROR >>> URL: '.$url.  'ERROR: '. $err);
        } else {
            return $response;
        }
    }

    private function proccessRemovedCards($removed_cards, $empty_vault, $request_data)
    {
        if($removed_cards != ""){
            $vault = $request_data['everypay_vault'];

            $rcards = explode(",", $removed_cards);

            if(sizeof($rcards) > 0){
                if($empty_vault == true){
                    Everypay::setApiKey($this->_secretKey);
                    $removed_cards = explode(";", $rcards[1]);
                    $xtoken = $removed_cards[0];
                    $response = Customer::delete($xtoken);
                }else{
                    foreach($rcards as $card){
                        if($card != "") {
                            $xcard = explode(";", $card);

                            $custToken = $xcard[0];
                            $cardToken = $xcard[1];
                            $this->deleteEverypayCustomerCard($this->_secretKey, $custToken, $cardToken, $vault);
                        }
                    }
                }

            }


            $customer_id = $request_data['customer_id'];

            $this->updateVault($vault, $empty_vault, $customer_id);

        }
    }

    private function updateVault($vault, $empty_vault, $customer_id)
    {
        $customerId = $customer_id;

        if ($customerId) {
            if ($empty_vault == true) {
                $vault_data = "";
                $everypay_vault['cards'] = $vault_data;
            } else {
                $vault_data = $vault;
                $everypay_vault['cards'] = json_decode($vault_data,true);
                $vault_data = json_encode($everypay_vault);
            }
        }else {
            return;
        }

        $customer = $this->_customerRepositoryInterface->getById($customerId);

        $customer->setCustomAttribute('everypay_vault', $vault_data);
        $this->_customerRepositoryInterface->save($customer);


    }
}
