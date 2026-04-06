<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Block\Iris;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;

class Success extends Template
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->orderRepository = $orderRepository;
    }

    public function getOrder()
    {
        $orderId = (int) $this->getCheckoutSession()->getLastOrderId();
        if (!$orderId) {
            return null;
        }

        try {
            return $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getOrderIncrementId()
    {
        $order = $this->getOrder();
        return $order ? $order->getIncrementId() : null;
    }

    public function getContinueUrl()
    {
        return $this->getUrl('');
    }

    protected function getCheckoutSession()
    {
        return ObjectManager::getInstance()->get(\Magento\Checkout\Model\Session::class);
    }
}
