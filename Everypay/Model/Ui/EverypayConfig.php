<?php

namespace Everypay\Everypay\Model\Ui;

use Magento\Framework\App\Config\ScopeConfigInterface;

class EverypayConfig
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig){
        $this->scopeConfig = $scopeConfig;
    }

    public function getPublicKey()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/merchant_public_key'
        );
    }

    public function getSecretKey()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/merchant_secret_key'
        );
    }

    public function getSandboxMode()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/sandbox'
        );
    }

    public function getInstallmentsPlan()
    {
        $_installments = $this->scopeConfig->getValue(
            'payment/everypay/installments'
        );

        $installmentsSecondLevel=array();

        if($_installments != ''){
            $installmentsFirstLevel = explode(',',$_installments);
            foreach ($installmentsFirstLevel as $x){
                $installmentsSecondLevel[] = explode(';',$x);
            }
        }

        return $installmentsSecondLevel;
    }
}
