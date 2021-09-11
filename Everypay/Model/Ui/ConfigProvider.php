<?php
/**
 * Copyright © 2016 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Everypay\Everypay\Gateway\Http\Client\ClientMock;
use Magento\Framework\Locale\Resolver;

/**
 * Class ConfigProvider
 */
final class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'everypay';
    private $epConfig;

    /**
     * @var Resolver
     */
    private $localeResolver;

    public function __construct(EverypayConfig $epConfig, Resolver $localeResolver) {
        $this->epConfig = $epConfig;
        $this->localeResolver = $localeResolver;
    }

    public function getLocale(): string
    {
        if ($this->localeResolver->getLocale() !== 'el_GR') {
            return 'en';
        }

        return 'el';
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
                    'sandboxMode' => $this->epConfig->isSandboxMode(),
                    'locale' => $this->getLocale(),
                    'token' => null,
                    'installments' => $this->epConfig->getInstallmentsPlan(),
                    'saveCard' => false,
                    'customerToken' => null,
                    'cardToken' =>  null,
                    'everypayVault' => null,
                    'removedCards' => null,
                    'emptyVault' => false,
                    'max_installments' => null
                ]
            ]
        ];
    }


}
