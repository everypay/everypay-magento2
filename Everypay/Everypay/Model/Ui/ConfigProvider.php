<?php
/**
 * Copyright © 2016 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Everypay\Everypay\Gateway\Http\Client\ClientMock;

/**
 * Class ConfigProvider
 */
final class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'everypay';
    private $epConfig;

    public function __construct(
        EverypayConfig $epConfig
    ) {
        $this->epConfig = $epConfig;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {

        return [
            'payment' => [
                self::CODE => [
                    'transactionResults' => [
                        ClientMock::SUCCESS => __('Success'),
                        ClientMock::FAILURE => __('Fail')
                    ],
                    'publicKey' => $this->epConfig->getPublicKey(),
                    'sandboxMode' => $this->epConfig->getSandboxMode(),
                    'locale' => $this->epConfig->getLocale(),
                    'token' => null,
                    'installments' => $this->epConfig->getInstallmentsPlan(),
                ]
            ]
        ];
    }


}
