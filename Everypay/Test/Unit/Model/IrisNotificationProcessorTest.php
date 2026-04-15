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
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IrisNotificationProcessorTest extends TestCase
{
    private OrderFactory $orderFactory;
    private OrderRepositoryInterface $orderRepository;
    private CartRepositoryInterface $quoteRepository;
    private CartManagementInterface $cartManagement;
    private QuoteManagement $quoteManagement;
    private EverypayConfig $epConfig;
    private LoggerInterface $logger;
    private ObjectManagerInterface $objectManager;
    private IrisNotificationProcessor $processor;

    protected function setUp(): void
    {
        $this->orderFactory = $this->createMock(OrderFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->quoteManagement = $this->createMock(QuoteManagement::class);
        $this->epConfig = $this->createMock(EverypayConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);

        $this->processor = new IrisNotificationProcessor(
            $this->orderFactory,
            $this->orderRepository,
            $this->quoteRepository,
            $this->cartManagement,
            $this->quoteManagement,
            $this->epConfig,
            $this->logger,
            $this->objectManager
        );
    }

    public function testFindOrderByIrisReferenceDoesNotCreateOrderWithoutVerifiedToken(): void
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection'])
            ->getMock();
        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFieldToFilter', 'setOrder', 'setPageSize', 'getSize'])
            ->getMock();
        $resource = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection'])
            ->getMock();
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['fetchOne'])
            ->getMock();

        $this->orderFactory->expects($this->once())
            ->method('create')
            ->willReturn($order);
        $this->objectManager->expects($this->once())
            ->method('get')
            ->with(ResourceConnection::class)
            ->willReturn($resource);
        $resource->expects($this->once())
            ->method('getConnection')
            ->willReturn($connection);
        $connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnMap([
                ['SELECT GET_LOCK(?, 5)', ['everypay_iris_quote_42'], 1],
                ['SELECT RELEASE_LOCK(?)', ['everypay_iris_quote_42'], 1],
            ]);
        $order->expects($this->once())
            ->method('getCollection')
            ->willReturn($collection);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('quote_id', 42)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('setOrder')
            ->with('created_at', 'DESC')
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('getSize')
            ->willReturn(0);
        $this->quoteRepository->expects($this->never())
            ->method('get');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IRIS quote reference found without a verified payment token; skipping order creation',
                [
                    'quote_id' => 42,
                    'token_present' => false,
                    'allow_create_from_quote' => false,
                ]
            );

        self::assertNull($this->processor->findOrderByIrisReference('', 'checkout_qid_42', false));
    }

    public function testProcessNotificationFinalizesVerifiedIrisPaymentDirectly(): void
    {
        $token = 'src_secure_token_1234';
        $md = 'checkout_qid_42';
        $hash = $this->buildValidHash([
            'token' => $token,
            'md' => $md,
            'error_status' => '',
            'error_message' => '',
        ], 'secret');

        $request = $this->createMock(RequestInterface::class);
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection', 'getEntityId', 'getPayment', 'getState', 'setData', 'getGrandTotal', 'getStatusHistories', 'addCommentToStatusHistory'])
            ->getMock();
        $payment = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getAdditionalInformation', 'setMethod', 'setAdditionalInformation', 'setTransactionId', 'setLastTransId', 'setIsTransactionClosed', 'registerCaptureNotification'])
            ->getMock();
        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['join', 'addFieldToFilter', 'setPageSize', 'getSize', 'getFirstItem'])
            ->getMock();

        $request->expects($this->once())
            ->method('getParam')
            ->with('hash')
            ->willReturn($hash);
        $this->epConfig->expects($this->once())
            ->method('getSecretKey')
            ->willReturn('secret');
        $this->orderFactory->expects($this->once())
            ->method('create')
            ->willReturn($order);
        $order->expects($this->once())
            ->method('getCollection')
            ->willReturn($collection);
        $collection->expects($this->once())
            ->method('join')
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('payment.additional_information', ['like' => '%"iris_token":"src\_secure\_token\_1234"%'])
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('getSize')
            ->willReturn(1);
        $collection->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($order);

        $order->method('getEntityId')->willReturn(10);
        $order->method('getPayment')->willReturn($payment);
        $order->expects($this->once())
            ->method('getState')
            ->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->expects($this->exactly(2))
            ->method('setData');
        $order->expects($this->atLeastOnce())
            ->method('getGrandTotal')
            ->willReturn(10.5);
        $order->expects($this->atLeastOnce())
            ->method('getStatusHistories')
            ->willReturn([]);
        $order->expects($this->atLeastOnce())
            ->method('addCommentToStatusHistory')
            ->with(
                'EveryPay IRIS payment completed via webhook.',
                $this->anything(),
                $this->anything()
            );

        $payment->expects($this->once())
            ->method('getAdditionalInformation')
            ->with('iris_token')
            ->willReturn('');
        $payment->expects($this->once())
            ->method('setMethod')
            ->with('everypay');
        $payment->expects($this->atLeastOnce())
            ->method('setAdditionalInformation');
        $payment->expects($this->atLeastOnce())
            ->method('setTransactionId')
            ->with($token);
        $payment->expects($this->atLeastOnce())
            ->method('setLastTransId')
            ->with($token);
        $payment->expects($this->atLeastOnce())
            ->method('setIsTransactionClosed')
            ->with(false);
        $payment->expects($this->atLeastOnce())
            ->method('registerCaptureNotification')
            ->with(10.5);
        $this->orderRepository->expects($this->atLeastOnce())
            ->method('save')
            ->with($order);

        $result = $this->processor->processNotification($request, 'webhook');

        self::assertTrue($result['success']);
        self::assertFalse($result['already_processed']);
        self::assertSame($token, $result['payment_token']);
        self::assertSame($order, $result['order']);
    }

    public function testMarkOrderAsFailedRestoresQuote(): void
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getState', 'setState', 'setStatus', 'getQuoteId', 'getStatusHistories', 'addCommentToStatusHistory'])
            ->getMock();
        $quote = $this->createMock(CartInterface::class);

        $order->expects($this->once())
            ->method('getState')
            ->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->expects($this->once())
            ->method('setState')
            ->with(Order::STATE_CANCELED);
        $order->expects($this->once())
            ->method('setStatus')
            ->with(Order::STATE_CANCELED);
        $order->expects($this->once())
            ->method('getQuoteId')
            ->willReturn(42);
        $this->quoteRepository->expects($this->once())
            ->method('get')
            ->with(42)
            ->willReturn($quote);
        $quote->expects($this->once())
            ->method('getId')
            ->willReturn(42);
        $quote->expects($this->once())
            ->method('setIsActive')
            ->with(true)
            ->willReturnSelf();
        $quote->expects($this->once())
            ->method('setReservedOrderId')
            ->with(null)
            ->willReturnSelf();
        $this->quoteRepository->expects($this->once())
            ->method('save')
            ->with($quote);
        $order->expects($this->once())
            ->method('getStatusHistories')
            ->willReturn([]);
        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('EveryPay IRIS callback error: failed');
        $this->orderRepository->expects($this->once())
            ->method('save')
            ->with($order);

        $this->invokePrivateMethod($this->processor, 'markOrderAsFailed', [$order, 'failed', 'callback']);
    }

    private function invokePrivateMethod(object $object, string $methodName, array $arguments = [])
    {
        $reflection = new \ReflectionMethod($object, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function buildValidHash(array $payload, string $secret): string
    {
        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $secret);

        return base64_encode($signature . '|' . $payloadJson);
    }
}
