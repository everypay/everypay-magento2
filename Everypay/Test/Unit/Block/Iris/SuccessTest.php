<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Test\Unit\Block\Iris;

use Everypay\Everypay\Block\Iris\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class SuccessTest extends TestCase
{
    private Context $context;
    private OrderRepositoryInterface $orderRepository;
    private CheckoutSession $checkoutSession;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
    }

    public function testGetOrderUsesCheckoutSessionOrderId(): void
    {
        $order = $this->createMock(Order::class);

        $this->checkoutSession->expects(self::once())
            ->method('getLastOrderId')
            ->willReturn(11);
        $this->orderRepository->expects(self::once())
            ->method('get')
            ->with(11)
            ->willReturn($order);

        $block = $this->getMockBuilder(Success::class)
            ->setConstructorArgs([
                $this->context,
                $this->orderRepository,
            ])
            ->onlyMethods(['getCheckoutSession'])
            ->getMock();
        $block->expects(self::once())
            ->method('getCheckoutSession')
            ->willReturn($this->checkoutSession);

        self::assertSame($order, $block->getOrder());
    }

    public function testGetOrderReturnsNullWithoutCheckoutSessionOrderId(): void
    {
        $this->checkoutSession->expects(self::once())
            ->method('getLastOrderId')
            ->willReturn(0);
        $this->orderRepository->expects(self::never())
            ->method('get');

        $block = $this->getMockBuilder(Success::class)
            ->setConstructorArgs([
                $this->context,
                $this->orderRepository,
            ])
            ->onlyMethods(['getCheckoutSession'])
            ->getMock();
        $block->expects(self::once())
            ->method('getCheckoutSession')
            ->willReturn($this->checkoutSession);

        self::assertNull($block->getOrder());
    }
}
