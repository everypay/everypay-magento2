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

        $installmentsSecondLevel = [];

        if (!empty($_installments)) {
            $installmentsFirstLevel = explode(',', $_installments);
            foreach ($installmentsFirstLevel as $x) {
                $installmentsSecondLevel[] = explode(';', $x);
            }
        }

        return $installmentsSecondLevel;
    }

    public function getIsGooglePayEnabled()
    {
        $isGooglePayEnabled = $this->scopeConfig->getValue(
            'payment/everypay/googlepay_feature'
        );

        return boolval($isGooglePayEnabled);
    }

    public function getGooglePayCountryCode()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/googlepay_country_code'
        );
    }

    public function getGooglePayMerchantName()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/googlepay_merchant_name'
        );
    }

    public function getGooglePayMerchantUrl()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/googlepay_merchant_url'
        );
    }

    public function getGooglePayAllowedCardNetworks()
    {
        $alowedCardNetworks = $this->scopeConfig->getValue(
            'payment/everypay/googlepay_allowed_card_networks'
        );

        return explode(',', $alowedCardNetworks);
    }

    public function getGooglePayAllowedAuthMethods()
    {
        $alowedAuthMethods = $this->scopeConfig->getValue(
            'payment/everypay/googlepay_allowed_auth_methods'
        );

        return explode(',', $alowedAuthMethods);
    }

    public function getGooglePayButtonColor()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/googlepay_button_color'
        );
    }

    public function getIsApplePayEnabled()
    {
        $isApplePayEnabled = $this->scopeConfig->getValue(
            'payment/everypay/applepay_feature'
        );

        return boolval($isApplePayEnabled);
    }

    public function getApplePayCountryCode()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/applepay_country_code'
        );
    }

    public function getApplePayMerchantName()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/applepay_merchant_name'
        );
    }

    public function getApplePayMerchantUrl()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/applepay_merchant_url'
        );
    }

    public function getApplePayAllowedCardNetworks()
    {
        $alowedCardNetworks = $this->scopeConfig->getValue(
            'payment/everypay/applepay_allowed_card_networks'
        );

        return explode(',', $alowedCardNetworks);
    }

    public function getApplePayButtonColor()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/applepay_button_color'
        );
    }

    public function getIsIrisEnabled()
    {
        $isIrisEnabled = $this->scopeConfig->getValue(
            'payment/everypay/iris_feature'
        );

        return boolval($isIrisEnabled);
    }

    public function getIrisMerchantName()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/iris_merchant_name'
        );
    }

    public function getIrisCountry()
    {
        return $this->scopeConfig->getValue(
            'payment/everypay/iris_country'
        );
    }
}
