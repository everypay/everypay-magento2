<?php

namespace Everypay\Everypay\Model\Ui;

use Magento\Framework\App\Config\ScopeConfigInterface;

class EverypayConfig
{
    /**
     * @var string
     */
    private $publicKey;

    /**
     * @var string
     */
    private $secretKey;

    /**
     * @var bool
     */
    private $isSandboxMode;

    /**
     * @var mixed
     */
    private $installments;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->publicKey = $scopeConfig->getValue(
            'payment/everypay/merchant_public_key'
        );

        $this->secretKey = $scopeConfig->getValue(
            'payment/everypay/merchant_secret_key'
        );

        $this->isSandboxMode = $scopeConfig->getValue(
            'payment/everypay/sandbox'
        );

        $this->installments = $scopeConfig->getValue(
            'payment/everypay/installments'
        );
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function isSandboxMode()
    {
        return $this->isSandboxMode;
    }

    public function getInstallmentsPlan(): array
    {
        $installmentsSecondLevel = [];

        if ($this->installments != ''){
            $installmentsFirstLevel = explode(',', $this->installments);
            foreach ($installmentsFirstLevel as $x){
                $installmentsSecondLevel[] = explode(';',$x);
            }
        }

        return $installmentsSecondLevel;
    }
}
