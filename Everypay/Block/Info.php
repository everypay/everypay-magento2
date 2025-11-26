<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Block;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use Everypay\Everypay\Gateway\Response\FraudHandler;

class Info extends ConfigurableInfo
{
    /**
     * Returns label
     *
     * @param string $field
     * @return Phrase
     */
    protected function getLabel($field)
    {
        switch ($field) {
            case 'payment_type':
                return __('Payment Type');
            case 'method_title':
                return __('Payment Method');
            case 'iris_token':
                return __('IRIS Transaction Token');
            default:
                return __($field);
        }
    }

    /**
     * Returns value view
     *
     * @param string $field
     * @param string $value
     * @return string | Phrase
     */
    protected function getValueView($field, $value)
    {
        switch ($field) {
            case FraudHandler::FRAUD_MSG_LIST:
                return is_array($value) ? implode('; ', $value) : $value;
            case 'payment_type':
                return $value === 'IRIS' ? 'IRIS Bank Payment' : $value;
            case 'method_title':
                return $value ?: 'Everypay';
            case 'iris_token':
                return $value;
        }
        return parent::getValueView($field, $value);
    }

    /**
     * Get payment method title with IRIS indication
     * @return string
     */
    public function getMethodTitle()
    {
        $payment = $this->getInfo();
        $paymentType = $payment->getAdditionalInformation('payment_type');
        $methodTitle = $payment->getAdditionalInformation('method_title');

        // Check if this is an IRIS payment
        if ($paymentType === 'IRIS' || $methodTitle === 'Everypay IRIS Bank Payment') {
            return 'Everypay IRIS';
        }

        return $this->getMethod()->getTitle();
    }

    /**
     * Get specific value from additional information
     * @param string $key
     * @return string|null
     */
    public function getAdditionalInformation($key)
    {
        $payment = $this->getInfo();
        return $payment->getAdditionalInformation($key);
    }

    /**
     * Get payment type display
     * @return string
     */
    public function getPaymentType()
    {
        $payment = $this->getInfo();
        $paymentType = $payment->getAdditionalInformation('payment_type');

        if ($paymentType === 'IRIS') {
            return 'IRIS Bank Payment';
        }

        return $paymentType ?: 'Credit Card';
    }
}
