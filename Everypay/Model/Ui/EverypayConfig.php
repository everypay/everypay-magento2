<?php

namespace Everypay\Everypay\Model\Ui;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\Resolver;

class EverypayConfig
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @param Resolver $resolver
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(Resolver $resolver, ScopeConfigInterface $scopeConfig){
        $this->resolver = $resolver;
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

    public function getLocale(): string
    {
        if ($this->resolver->getLocale() !== 'el_GR') {
            return 'en';
        }

        return 'el';
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
