<?php
/**
 * Copyright © 2016 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

class SaleRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        ConfigInterface $config
    ) {
        $this->config = $config;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $payment */
        $payment = $buildSubject['payment'];
        $order = $payment->getOrder();
        $customer_id = $order->getCustomerId();
        $address = $order->getShippingAddress();

        $paymentX = $payment->getPayment();

        $token = $paymentX->getAdditionalInformation('token');
        $customer_token = $paymentX->getAdditionalInformation('customer_token');
        $card_token = $paymentX->getAdditionalInformation('card_token');
        $save_card = $paymentX->getAdditionalInformation('save_card');
        $everypay_vault = $paymentX->getAdditionalInformation('everypay_vault');
        $removed_cards = $paymentX->getAdditionalInformation('removed_cards');
        $empty_vault = $paymentX->getAdditionalInformation('empty_vault');
        $max_installments = $paymentX->getAdditionalInformation('max_installments');

        return [
            'TXN_TYPE' => 'S',
            'INVOICE' => $order->getOrderIncrementId(),
            'AMOUNT' => $order->getGrandTotalAmount(),
            'CURRENCY' => $order->getCurrencyCode(),
            'EMAIL' => (isset($address))?$address->getEmail():'',
            'token' => $token,
            'customer_token' => $customer_token,
            'card_token' => $card_token,
            'save_card' => $save_card,
            'everypay_vault' => $everypay_vault,
            'customer_id' => $customer_id,
            'removed_cards' => $removed_cards,
            'empty_vault' => $empty_vault,
            'max_installments' => $max_installments
        ];
    }
}
