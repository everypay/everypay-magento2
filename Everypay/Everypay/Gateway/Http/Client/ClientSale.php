<?php
/**
 * Copyright © 2016 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Everypay\Everypay;
use Everypay\Payment;
use Everypay\Token;


class ClientSale implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    /**
     * @var array
     */
    private $results = [
        self::SUCCESS,
        self::FAILURE
    ];

    /**
     * @var Logger
     */
    private $logger;
    private $epConfig;

    /**
     * @param Logger $logger
     * @param EverypayConfig $epConfig
     */
    public function __construct(
        Logger $logger,
        EverypayConfig $epConfig
    ) {
        $this->logger = $logger;
        $this->epConfig = $epConfig;

        $secretKey = $this->epConfig->getSecretKey();
        $publicKey = $this->epConfig->getPublicKey();
        $sandboxMode = $this->epConfig->getSandboxMode();

        $this->_secretKey = $secretKey;
        $this->_publicKey = $publicKey;
        $this->_sandboxMode = $sandboxMode;
    }

    /**
     * Places request to gateway. Returns result as ENV array
     *
     * @param TransferInterface $transferObject
     * @return array
     */
    public function placeRequest(TransferInterface $transferObject)
    {

        $this->logger->debug(
            [
                'intiRequest' => $transferObject->getBody()
            ]
        );


        Everypay::$isTest = $this->_sandboxMode;


        $requestData = $transferObject->getBody();

        if (isset($requestData['token']))
        {
            $token = $requestData['token'];
        }else{
            throw new \InvalidArgumentException('Card token does not exist !!! (clientSale)');
        }

        $customerEmail = $requestData['EMAIL'];
        $orderNumber = $requestData['INVOICE'];

        Everypay::setApiKey($this->_publicKey);

        $token = Token::retrieve($token);


        if($token->is_used || $token->has_expired)
        {
            throw new \InvalidArgumentException('Card token has expired or been used already !!!');
        }

        Everypay::setApiKey($this->_secretKey);

        $params = array(
            'token'         => $token->token,
            'amount'        => $token->amount,
            'payee_email'   => $customerEmail,
            'description'   => 'Order: ' . $orderNumber,
        );

        $response=Payment::create($params);

        if(isset($response->error))
        {
            $rcode = 0;
            $pmt = 'error';
        }else{
            $rcode = 1;
            $pmt = $response->token;
        }

        $response = $this->generateResponseForCode($rcode, $pmt);

        $this->logger->debug(
            [
                'request' => $transferObject->getBody(),
                'response' => $response
            ]
        );

        return $response;
    }

    /**
     * Generates response
     *
     * @return array
     */
    protected function generateResponseForCode($resultCode, $pmt)
    {

        return array_merge(
            [
                'RESULT_CODE' => $resultCode,
                'TXN_ID' => $pmt
            ],
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
     * Returns result code
     *
     * @param TransferInterface $transfer
     * @return int
     */
    private function getResultCode(TransferInterface $transfer)
    {
        $headers = $transfer->getHeaders();

        if (isset($headers['force_result'])) {
            return (int)$headers['force_result'];
        }

        return $this->results[mt_rand(0, 1)];
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
}
