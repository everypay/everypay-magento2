<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Controller\Iris;

use Everypay\Everypay\Model\IrisNotificationProcessor;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class Callback extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    protected $resultFactory;
    protected $checkoutSession;
    protected $messageManager;
    protected $logger;
    protected $response;
    private $notificationProcessor;

    public function __construct(
        Context $context,
        HttpResponse $response,
        CheckoutSession $checkoutSession,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        IrisNotificationProcessor $notificationProcessor
    ) {
        parent::__construct($context);
        $this->response = $response;
        $this->resultFactory = $context->getResultFactory();
        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->notificationProcessor = $notificationProcessor;
        $this->configurePaymentGatewayCookies();
    }

    protected function forceSecureCookies()
    {
        try {
            if ($this->isHttpsRequest() && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)) {
                $sessionDataBackup = $_SESSION;
                session_destroy();

                session_set_cookie_params([
                    'lifetime' => 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'None',
                ]);

                session_start();

                foreach ($sessionDataBackup as $key => $value) {
                    $_SESSION[$key] = $value;
                }

                setcookie(
                    session_name(),
                    session_id(),
                    [
                        'expires' => time() + 3600,
                        'path' => '/',
                        'domain' => '',
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'None',
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('PAYMENT GATEWAY: Force cookie setting failed: ' . $e->getMessage());
        }
    }

    protected function configurePaymentGatewayCookies()
    {
        if (session_status() === PHP_SESSION_NONE) {
            try {
                if ($this->isHttpsRequest()) {
                    session_set_cookie_params([
                        'lifetime' => 3600,
                        'path' => '/',
                        'domain' => '',
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'None',
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PAYMENT GATEWAY: Cookie configuration failed: ' . $e->getMessage());
            }
        }
    }

    protected function isHttpsRequest()
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }
        if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
            return true;
        }

        try {
            $store = $this->_objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore();
            return (bool) $store->isCurrentlySecure();
        } catch (\Exception $e) {
            return false;
        }
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
        $this->response->setNoCacheHeaders();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET') {
            return $this->handleGetCallback();
        }

        if ($method !== 'POST') {
            $this->response->setHttpResponseCode(405);
            $this->response->setHeader('Content-Type', 'application/json', true);
            $this->response->setBody(json_encode(['success' => false, 'message' => 'Method Not Allowed']));
            $this->response->sendResponse();
            return;
        }

        return $this->handlePostCallback();
    }

    protected function handleGetCallback()
    {
        $token = $this->getRequest()->getParam('token');
        $md = $this->getRequest()->getParam('md');

        if (empty($md)) {
            $md = $this->checkoutSession->getData('everypay_iris_last_md');
        }

        $order = $this->notificationProcessor->findOrderByIrisReference($token, $md, false);

        if ((!$order || !$order->getId()) && !$token && !$md) {
            $lastOrderId = (int) $this->checkoutSession->getLastOrderId();
            if ($lastOrderId) {
                try {
                    $order = $this->_objectManager
                        ->get(\Magento\Sales\Api\OrderRepositoryInterface::class)
                        ->get($lastOrderId);
                } catch (\Exception $e) {
                    $this->logger->warning('IRIS GET callback session fallback failed: ' . $e->getMessage());
                }
            }
        }

        if ($order && $order->getId()) {
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->unsetData('everypay_iris_last_md');
            $this->checkoutSession->unsetData('everypay_iris_last_quote_id');
            $this->checkoutSession->clearQuote();

            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('everypay/iris/success');
            return $resultRedirect;
        }

        $this->messageManager->addErrorMessage(
            __('Payment reference not found. Please contact support if you completed the payment.')
        );

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/cart');
        return $resultRedirect;
    }

    protected function handlePostCallback()
    {
        try {
            $result = $this->notificationProcessor->processNotification($this->getRequest(), 'callback');

            if (!empty($result['success']) && !empty($result['order'])) {
                $order = $result['order'];
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastOrderId($order->getId());
                $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
                $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                $this->checkoutSession->unsetData('everypay_iris_last_md');
                $this->checkoutSession->unsetData('everypay_iris_last_quote_id');
                $this->checkoutSession->clearQuote();

                $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                $resultRedirect->setPath('everypay/iris/success');
                return $resultRedirect;
            }

            if (!empty($result['error_message'])) {
                $this->messageManager->addErrorMessage($result['error_message']);
            }

            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        } catch (\Exception $e) {
            $this->logger->error('IRIS callback error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            $this->messageManager->addErrorMessage(
                __('IRIS payment error. Please contact support if you completed the payment.')
            );

            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }
    }
}
