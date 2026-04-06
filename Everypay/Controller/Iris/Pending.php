<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Controller\Iris;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class Pending extends Action implements HttpGetActionInterface
{
    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CheckoutSession
     */
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

        if (!$order->getEntityId()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        if ($this->isSuccessfulOrder($order)) {
            return $this->resultRedirectFactory->create()->setPath('everypay/iris/success');
        }

        if (!$this->isPendingOrder($order)) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        return $this->resultPageFactory->create();
    }

    private function isSuccessfulOrder(Order $order)
    {
        return in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true);
    }

    private function isPendingOrder(Order $order)
    {
        return in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true)
            || $order->getStatus() === Order::STATE_PENDING_PAYMENT;
    }
}
