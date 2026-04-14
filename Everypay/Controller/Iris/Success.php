<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Controller\Iris;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class Success extends Action implements HttpGetActionInterface
{
    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    private $checkoutSession;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute()
    {
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if (!$orderId) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        if (!$order->getEntityId() || !$this->isSuccessfulOrder($order)) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        return $this->resultPageFactory->create();
    }

    private function isSuccessfulOrder(Order $order)
    {
        return in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true);
    }
}
