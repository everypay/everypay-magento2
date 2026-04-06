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

class SuccessTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Context|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $context;

    /**
     * @var OrderRepositoryInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $orderRepository;

    /**
     * @var CheckoutSession|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $checkoutSession;

    public function setUp()
    {
        $this->context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->orderRepository = $this->getMock(OrderRepositoryInterface::class);
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testGetOrderUsesCheckoutSessionOrderId()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->checkoutSession->expects(static::once())
            ->method('getLastOrderId')
            ->willReturn(11);
        $this->orderRepository->expects(static::once())
            ->method('get')
            ->with(11)
            ->willReturn($order);

        $block = $this->getMockBuilder(Success::class)
            ->setConstructorArgs([
                $this->context,
                $this->orderRepository,
            ])
            ->setMethods(['getCheckoutSession'])
            ->getMock();
        $block->expects(static::once())
            ->method('getCheckoutSession')
            ->willReturn($this->checkoutSession);

        static::assertSame($order, $block->getOrder());
    }

    public function testGetOrderReturnsNullWithoutCheckoutSessionOrderId()
    {
        $this->checkoutSession->expects(static::once())
            ->method('getLastOrderId')
            ->willReturn(0);
        $this->orderRepository->expects(static::never())
            ->method('get');

        $block = $this->getMockBuilder(Success::class)
            ->setConstructorArgs([
                $this->context,
                $this->orderRepository,
            ])
            ->setMethods(['getCheckoutSession'])
            ->getMock();
        $block->expects(static::once())
            ->method('getCheckoutSession')
            ->willReturn($this->checkoutSession);

        static::assertNull($block->getOrder());
    }
}
