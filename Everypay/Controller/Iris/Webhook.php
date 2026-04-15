<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Controller\Iris;

use Everypay\Everypay\Model\IrisNotificationProcessor;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Webhook extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private $resultJsonFactory;
    private $notificationProcessor;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        IrisNotificationProcessor $notificationProcessor
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->notificationProcessor = $notificationProcessor;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $processingResult = $this->notificationProcessor->processNotification($this->getRequest(), 'webhook');

        if ($processingResult['success']) {
            return $result->setHttpResponseCode(200)->setData([
                'success' => true,
                'already_processed' => (bool) ($processingResult['already_processed'] ?? false),
                'order_id' => $processingResult['order'] ? $processingResult['order']->getEntityId() : null,
            ]);
        }

        return $result->setHttpResponseCode(400)->setData([
            'success' => false,
            'message' => (string) ($processingResult['error_message'] ?? __('IRIS webhook failed.')),
        ]);
    }
}
