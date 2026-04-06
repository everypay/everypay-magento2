<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Test\Unit\Model;

use Everypay\Everypay\Model\IrisNotificationProcessor;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Magento\Framework\App\RequestInterface;
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

    public function testFindOrderByIrisReferenceReturnsOrderByToken()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collection = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['join', 'addFieldToFilter', 'setPageSize', 'getSize', 'getFirstItem'])
            ->getMock();

        $this->orderFactory->expects(static::once())
            ->method('create')
            ->willReturn($order);
        $order->expects(static::once())
            ->method('getCollection')
            ->willReturn($collection);
        $collection->expects(static::once())
            ->method('join')
            ->with(
                ['payment' => 'sales_order_payment'],
                'main_table.entity_id = payment.parent_id',
                []
            )
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('addFieldToFilter')
            ->with('payment.additional_information', ['like' => '%"iris_token":"secure_token"%'])
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('getSize')
            ->willReturn(1);
        $collection->expects(static::once())
            ->method('getFirstItem')
            ->willReturn($order);

        static::assertSame($order, $this->processor->findOrderByIrisReference('secure_token', 'ignored', false));
    }

    public function testExtractPayloadRejectsInvalidHash()
    {
        $request = $this->getMock(RequestInterface::class);

        $request->expects(static::once())
            ->method('getParam')
            ->with('hash')
            ->willReturn(base64_encode('invalid|{"token":"secure_token"}'));
        $this->epConfig->expects(static::once())
            ->method('getSecretKey')
            ->willReturn('secret');

        $result = $this->invokePrivateMethod($this->processor, 'extractPayload', [$request]);

        static::assertFalse($result['success']);
        static::assertSame('IRIS payment error. Hash verification failed.', (string) $result['error_message']);
    }

    public function testProcessNotificationReturnsAlreadyProcessedForPaidOrder()
    {
        $token = 'secure_token';
        $md = 'checkout_qid_42';
        $hash = $this->buildValidHash([
            'token' => $token,
            'md' => $md,
            'error_status' => '',
            'error_message' => '',
        ], 'secret');
        $request = $this->getMock(RequestInterface::class);
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getCollection',
                'getEntityId',
                'getPayment',
                'getState',
                'setData',
            ])
            ->getMock();
        $payment = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['getAdditionalInformation', 'setMethod', 'setAdditionalInformation'])
            ->getMock();
        $collection = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['join', 'addFieldToFilter', 'setPageSize', 'getSize', 'getFirstItem'])
            ->getMock();

        $request->expects(static::once())
            ->method('getParam')
            ->with('hash')
            ->willReturn($hash);
        $this->epConfig->expects(static::once())
            ->method('getSecretKey')
            ->willReturn('secret');
        $this->orderFactory->expects(static::once())
            ->method('create')
            ->willReturn($order);
        $order->expects(static::once())
            ->method('getCollection')
            ->willReturn($collection);
        $collection->expects(static::once())
            ->method('join')
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('addFieldToFilter')
            ->with('payment.additional_information', ['like' => '%"iris_token":"' . $token . '"%'])
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->expects(static::once())
            ->method('getSize')
            ->willReturn(1);
        $collection->expects(static::once())
            ->method('getFirstItem')
            ->willReturn($order);
        $order->expects(static::atLeastOnce())
            ->method('getEntityId')
            ->willReturn(10);
        $order->expects(static::exactly(2))
            ->method('getPayment')
            ->willReturn($payment);
        $payment->expects(static::once())
            ->method('getAdditionalInformation')
            ->with('iris_token')
            ->willReturn($token);
        $order->expects(static::once())
            ->method('getState')
            ->willReturn(Order::STATE_PROCESSING);
        $payment->expects(static::once())
            ->method('setMethod')
            ->with('everypay');
        $payment->expects(static::exactly(5))
            ->method('setAdditionalInformation')
            ->willReturnMap([
                ['payment_type', 'IRIS', null],
                ['method_title', 'Everypay IRIS Bank Payment', null],
                ['iris_token', $token, null],
                ['iris_md', $md, null],
                ['iris_hash', $hash, null],
            ]);
        $order->expects(static::exactly(2))
            ->method('setData')
            ->willReturnMap([
                ['everypay_source_token', $token, null],
                ['everypay_iris_md', $md, null],
            ]);

        $result = $this->processor->processNotification($request, 'callback');

        static::assertTrue($result['success']);
        static::assertTrue($result['already_processed']);
        static::assertSame($order, $result['order']);
    }

    public function testMarkOrderAsFailedRestoresQuote()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getState',
                'setState',
                'setStatus',
                'getQuoteId',
                'getStatusHistories',
                'addCommentToStatusHistory',
            ])
            ->getMock();
        $quote = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['getId', 'setIsActive', 'setReservedOrderId'])
            ->getMock();

        $order->expects(static::once())
            ->method('getState')
            ->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->expects(static::once())
            ->method('setState')
            ->with(Order::STATE_CANCELED);
        $order->expects(static::once())
            ->method('setStatus')
            ->with(Order::STATE_CANCELED);
        $order->expects(static::once())
            ->method('getQuoteId')
            ->willReturn(42);
        $this->quoteRepository->expects(static::once())
            ->method('get')
            ->with(42)
            ->willReturn($quote);
        $quote->expects(static::once())
            ->method('getId')
            ->willReturn(42);
        $quote->expects(static::once())
            ->method('setIsActive')
            ->with(true);
        $quote->expects(static::once())
            ->method('setReservedOrderId')
            ->with(null);
        $this->quoteRepository->expects(static::once())
            ->method('save')
            ->with($quote);
        $order->expects(static::once())
            ->method('getStatusHistories')
            ->willReturn([]);
        $order->expects(static::once())
            ->method('addCommentToStatusHistory')
            ->with('EveryPay IRIS callback error: failed');
        $this->orderRepository->expects(static::once())
            ->method('save')
            ->with($order);

        $this->invokePrivateMethod($this->processor, 'markOrderAsFailed', [$order, 'failed', 'callback']);
    }

    public function testIsIdempotentPaidOrderRequiresPaidStateAndMatchingToken()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['getState', 'getPayment'])
            ->getMock();
        $payment = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['getAdditionalInformation'])
            ->getMock();

        $order->expects(static::once())
            ->method('getState')
            ->willReturn(Order::STATE_PROCESSING);
        $order->expects(static::once())
            ->method('getPayment')
            ->willReturn($payment);
        $payment->expects(static::once())
            ->method('getAdditionalInformation')
            ->with('iris_token')
            ->willReturn('secure_token');

        static::assertTrue($this->invokePrivateMethod($this->processor, 'isIdempotentPaidOrder', [$order, 'secure_token']));
    }

    public function testIsIdempotentPaidOrderRejectsMismatchedToken()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['getState', 'getPayment'])
            ->getMock();
        $payment = $this->getMockBuilder(\stdClass::class)
            ->setMethods(['getAdditionalInformation'])
            ->getMock();

        $order->expects(static::once())
            ->method('getState')
            ->willReturn(Order::STATE_PROCESSING);
        $order->expects(static::once())
            ->method('getPayment')
            ->willReturn($payment);
        $payment->expects(static::once())
            ->method('getAdditionalInformation')
            ->with('iris_token')
            ->willReturn('different_token');

        static::assertFalse($this->invokePrivateMethod($this->processor, 'isIdempotentPaidOrder', [$order, 'secure_token']));
    }

    private function invokePrivateMethod($object, $methodName, array $arguments = [])
    {
        $reflection = new \ReflectionMethod($object, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function buildValidHash(array $payload, $secret)
    {
        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $secret);

        return base64_encode($signature . '|' . $payloadJson);
    }
}
