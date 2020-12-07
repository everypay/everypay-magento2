<?php
/**
 * Created by PhpStorm.
 * User: sp
 * Date: 20/12/2018
 * Time: 15:09
 */

namespace Everypay\Everypay\Model\Ui;

class EverypayConfig
{
    /**
     * Core store config
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;
    protected $_resolver;

    /*
     * @var \Everypay\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Framework\Locale\Resolver $resolver,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ){
        $this->_resolver = $resolver;
        $this->_scopeConfig = $scopeConfig;
    }


    public function getPublicKey()
    {
        $pk = $this->_scopeConfig->getValue(
            'payment/everypay/merchant_public_key'
        );
        return $pk;
    }

    public function getSecretKey()
    {
        $sk = $this->_scopeConfig->getValue(
            'payment/everypay/merchant_secret_key'
        );
        return $sk;
    }

    public function getSandboxMode()
    {
        $sb = $this->_scopeConfig->getValue(
            'payment/everypay/sandbox'
        );
        return $sb;
    }

    public function getLocale()
    {
        $locale = $this->_resolver->getLocale();
        if ($locale === 'el_GR')
        {

            $locale = 'el';


        }else {

            $locale = 'en';

        }
        return $locale;
    }

    public function getInstallmentsPlan()
    {
        $_installments = $this->_scopeConfig->getValue(
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