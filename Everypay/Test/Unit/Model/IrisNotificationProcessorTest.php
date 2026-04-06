<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Test\Unit\Model;

use Everypay\Everypay\Model\IrisNotificationProcessor;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class IrisNotificationProcessorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var OrderFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $orderFactory;

    /**
     * @var OrderRepositoryInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $orderRepository;

    /**
     * @var CartRepositoryInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $quoteRepository;

    /**
     * @var CartManagementInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $cartManagement;

    /**
     * @var EverypayConfig|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $epConfig;

    /**
     * @var LoggerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $logger;

    /**
     * @var ObjectManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $objectManager;

    /**
     * @var IrisNotificationProcessor
     */
    protected $processor;

    public function setUp()
    {
        $this->orderFactory = $this->getMockBuilder(OrderFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->orderRepository = $this->getMock(OrderRepositoryInterface::class);
        $this->quoteRepository = $this->getMock(CartRepositoryInterface::class);
        $this->cartManagement = $this->getMock(CartManagementInterface::class);
        $this->epConfig = $this->getMockBuilder(EverypayConfig::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->getMock(LoggerInterface::class);
        $this->objectManager = $this->getMock(ObjectManagerInterface::class);

        $this->processor = new IrisNotificationProcessor(
            $this->orderFactory,
            $this->orderRepository,
            $this->quoteRepository,
            $this->cartManagement,
            $this->epConfig,
            $this->logger,
            $this->objectManager
        );
    }

    public function testFindOrderByIrisReferenceDoesNotCreateOrderWithoutVerifiedToken()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['addFieldToFilter', 'setOrder', 'setPageSize', 'getSize'])
            ->getMock();

        $this->orderFactory->expects(static::once())
            ->method('create')
            ->willReturn($order);
        $order->expects(static::once())
            ->method('getCollection')
            ->willReturn($collection);
        $collection->expects(static::once())
            ->method('addFieldToFilter')
            ->with('quote_id', 42)
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('setOrder')
            ->with('created_at', 'DESC')
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('getSize')
            ->willReturn(0);
        $this->quoteRepository->expects(static::never())
            ->method('get');
        $this->logger->expects(static::once())
            ->method('warning')
            ->with(
                'IRIS quote reference found without a verified payment token; skipping order creation',
                [
                    'quote_id' => 42,
                    'token_present' => false,
                    'allow_create_from_quote' => false,
                ]
            );

        static::assertNull($this->processor->findOrderByIrisReference('', 'checkout_qid_42', false));
    }
}
