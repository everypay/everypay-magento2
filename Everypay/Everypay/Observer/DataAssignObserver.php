<?php
/**
 * Copyright © 2016 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;


class DataAssignObserver extends AbstractDataAssignObserver
{

    const TRANSACTION_RESULT = 'transaction_result';
    const TOKEN = 'token';
    /**
     * @var array
     */
    protected $additionalInformationList = [
        self::TRANSACTION_RESULT,
        self::TOKEN
    ];

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);

        foreach ($this->additionalInformationList as $additionalInformationKey) {
            if (isset($additionalData[$additionalInformationKey])) {
                $paymentInfo->setAdditionalInformation(
                    $additionalInformationKey,
                    $additionalData[$additionalInformationKey]
                );
            }
        }
    }
}
