<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Test\Unit\Controller\Iris;

use Everypay\Everypay\Controller\Iris\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class SuccessTest extends TestCase
{
    private Context $context;
    private PageFactory $resultPageFactory;
    private OrderRepositoryInterface $orderRepository;
    private CheckoutSession $checkoutSession;
    private RedirectFactory $resultRedirectFactory;
    private Success $controller;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->resultPageFactory = $this->createMock(PageFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->resultRedirectFactory = $this->createMock(RedirectFactory::class);

        $this->controller = new Success(
            $this->context,
            $this->resultPageFactory,
            $this->orderRepository,
            $this->checkoutSession
        );

        $this->setObjectProperty($this->controller, 'resultRedirectFactory', $this->resultRedirectFactory);
    }

    public function testExecuteRedirectsToCartWithoutLastOrderId(): void
    {
        $redirect = $this->createMock(Redirect::class);

        $this->checkoutSession->expects(self::once())
            ->method('getLastOrderId')
            ->willReturn(0);
        $this->resultRedirectFactory->expects(self::once())
            ->method('create')
            ->willReturn($redirect);
        $redirect->expects(self::once())
            ->method('setPath')
            ->with('checkout/cart')
            ->willReturnSelf();
        $this->orderRepository->expects(self::never())
            ->method('get');
        $this->resultPageFactory->expects(self::never())
            ->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    public function testExecuteRedirectsToCartWhenOrderCannotBeLoaded(): void
    {
        $redirect = $this->createMock(Redirect::class);

        $this->checkoutSession->expects(self::once())
            ->method('getLastOrderId')
            ->willReturn(21);
        $this->orderRepository->expects(self::once())
            ->method('get')
            ->with(21)
            ->willThrowException(new \RuntimeException('missing order'));
        $this->resultRedirectFactory->expects(self::once())
            ->method('create')
            ->willReturn($redirect);
        $redirect->expects(self::once())
            ->method('setPath')
            ->with('checkout/cart')
            ->willReturnSelf();
        $this->resultPageFactory->expects(self::never())
            ->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    public function testExecuteRedirectsToCartForUnsuccessfulOrder(): void
    {
        $redirect = $this->createMock(Redirect::class);
        $order = $this->createMock(Order::class);

        $this->checkoutSession->expects(self::once())
            ->method('getLastOrderId')
            ->willReturn(22);
        $this->orderRepository->expects(self::once())
            ->method('get')
            ->with(22)
            ->willReturn($order);
        $order->expects(self::once())
            ->method('getEntityId')
            ->willReturn(22);
        $order->expects(self::once())
            ->method('getState')
            ->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->resultRedirectFactory->expects(self::once())
            ->method('create')
            ->willReturn($redirect);
        $redirect->expects(self::once())
            ->method('setPath')
            ->with('checkout/cart')
            ->willReturnSelf();
        $this->resultPageFactory->expects(self::never())
            ->method('create');

        self::assertSame($redirect, $this->controller->execute());
    }

    public function testExecuteReturnsPageForProcessingOrder(): void
    {
        $page = $this->createMock(Page::class);
        $order = $this->createMock(Order::class);

        $this->checkoutSession->expects(self::once())
            ->method('getLastOrderId')
            ->willReturn(23);
        $this->orderRepository->expects(self::once())
            ->method('get')
            ->with(23)
            ->willReturn($order);
        $order->expects(self::once())
            ->method('getEntityId')
            ->willReturn(23);
        $order->expects(self::once())
            ->method('getState')
            ->willReturn(Order::STATE_PROCESSING);
        $this->resultPageFactory->expects(self::once())
            ->method('create')
            ->willReturn($page);
        $this->resultRedirectFactory->expects(self::never())
            ->method('create');

        self::assertSame($page, $this->controller->execute());
    }

    private function setObjectProperty(object $object, string $property, mixed $value): void
    {
        $class = new \ReflectionClass($object);
        while (!$class->hasProperty($property)) {
            $class = $class->getParentClass();
            if (!$class) {
                throw new \RuntimeException(sprintf('Property "%s" was not found on %s.', $property, get_debug_type($object)));
            }
        }

        $reflection = $class->getProperty($property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
