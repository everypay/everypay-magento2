<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Controller\Iris;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Everypay\Everypay;
use Everypay\Payment;
use Psr\Log\LoggerInterface;

class Callback extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var EverypayConfig
     */
    protected $epConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var HttpResponse
     */
    protected $response;

    /**
     * @param Context $context
     * @param HttpResponse $response
     * @param OrderFactory $orderFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param ManagerInterface $messageManager
     * @param EverypayConfig $epConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        HttpResponse $response,
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        ManagerInterface $messageManager,
        EverypayConfig $epConfig,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->response = $response;
        $this->resultFactory = $context->getResultFactory();
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
        $this->epConfig = $epConfig;
        $this->logger = $logger;

        // PAYMENT GATEWAY COOKIE FIX: Set SameSite=None for cross-site payment callbacks
        // This fixes session loss when banks redirect back from external domains
        $this->configurePaymentGatewayCookies();

        // Prevent session regeneration for payment callbacks
        $this->debugToStdout('Constructor: Session ID before: ' . session_id());
    }

    /**
     * Force secure cookie settings for payment gateway compatibility
     * This MUST be called before any session operations
     */
    protected function forceSecureCookies()
    {
        try {
            // Only force regenerate if we have existing session data to preserve
            if ($this->isHttpsRequest() && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)) {

                $this->debugToStdout('PAYMENT GATEWAY: Backing up session data before secure regeneration');

                // CRITICAL: Back up all session data before destroying
                $sessionDataBackup = $_SESSION;
                $this->debugToStdout('PAYMENT GATEWAY: Backed up ' . count($sessionDataBackup) . ' session variables');

                // Destroy current session (this clears the data but we have backup)
                session_destroy();
                $this->debugToStdout('PAYMENT GATEWAY: Destroyed old session');

                // Set secure cookie parameters for new session
                session_set_cookie_params([
                    'lifetime' => 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'None',
                ]);

                // Start new session with secure settings
                session_start();
                $this->debugToStdout('PAYMENT GATEWAY: Started new secure session: ' . session_id());

                // CRITICAL: Restore all session data
                foreach ($sessionDataBackup as $key => $value) {
                    $_SESSION[$key] = $value;
                }
                $this->debugToStdout('PAYMENT GATEWAY: Restored ' . count($sessionDataBackup) . ' session variables');

                // Force set a secure cookie manually as well
                $cookieName = session_name();
                $sessionId = session_id();

                // Set cookie with proper SameSite=None;Secure
                setcookie(
                    $cookieName,
                    $sessionId,
                    [
                        'expires' => time() + 3600,
                        'path' => '/',
                        'domain' => '',
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'None'
                    ]
                );

                $this->debugToStdout('PAYMENT GATEWAY FIX: Force set SameSite=None;Secure cookies WITH DATA PRESERVATION');
            } else {
                $this->debugToStdout('PAYMENT GATEWAY: Skipping secure cookie regeneration - no session data or not HTTPS');
            }
        } catch (\Exception $e) {
            $this->debugToStdout('PAYMENT GATEWAY: Force cookie setting failed: ' . $e->getMessage());
        }
    }

    /**
     * Configure cookies for payment gateway compatibility
     * Sets SameSite=None for cross-origin payment callbacks
     */
    protected function configurePaymentGatewayCookies()
    {
        // Only configure if session hasn't started yet
        if (session_status() === PHP_SESSION_NONE) {
            try {
                // Enhanced HTTPS detection for Docker/proxy environments
                $isSecure = $this->isHttpsRequest();

                if ($isSecure) {
                    // Set secure cookie parameters for payment gateway redirects
                    session_set_cookie_params([
                        'lifetime' => 3600,        // 1 hour session lifetime
                        'path' => '/',
                        'domain' => '',            // Use current domain
                        'secure' => true,          // Require HTTPS
                        'httponly' => true,        // Prevent JavaScript access
                        'samesite' => 'None',      // Allow cross-site requests (CRITICAL for payment gateways)
                    ]);

                    $this->debugToStdout('PAYMENT GATEWAY FIX: Set SameSite=None;Secure for cross-origin compatibility');
                } else {
                    // Force secure=true for magento.everypay.local since we know it's HTTPS
                    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'magento.everypay.local') !== false) {
                        session_set_cookie_params([
                            'lifetime' => 3600,
                            'path' => '/',
                            'domain' => '',
                            'secure' => true,          // Force secure for known HTTPS domain
                            'httponly' => true,
                            'samesite' => 'None',
                        ]);
                        $this->debugToStdout('PAYMENT GATEWAY FIX: Forced SameSite=None;Secure for magento.everypay.local');
                    } else {
                        // Fallback for non-HTTPS environments (development)
                        $this->debugToStdout('PAYMENT GATEWAY: Non-HTTPS environment detected, keeping default cookie settings');
                    }
                }
            } catch (\Exception $e) {
                $this->debugToStdout('PAYMENT GATEWAY: Cookie configuration failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Enhanced HTTPS detection for Docker/proxy environments
     */
    protected function isHttpsRequest()
    {
        // Check standard HTTPS indicators
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        }

        // Check proxy headers (common in Docker/reverse proxy setups)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Check standard HTTPS port
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        }

        // Check for other common proxy headers
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }

        // Force HTTPS for known secure domains (Docker development)
        if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'magento.everypay.local') !== false) {
            return true;
        }

        // Check URL scheme in request URI
        if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute IRIS callback
     *
     * @return \Magento\Framework\Controller\ResultInterface|void
     */
    public function execute()
    {
        // Debug session info at start of callback
        $this->debugSessionInfo('IRIS Callback Start');

        // Disable caching
        $this->response->setNoCacheHeaders();

        // Check request method
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET') {
            return $this->handleGetCallback();
        }

        if ($method !== 'POST') {
            // Method not allowed
            $this->response->setHttpResponseCode(405);
            $this->response->setHeader('Content-Type', 'application/json', true);
            $this->response->setBody(json_encode(['success' => false, 'message' => 'Method Not Allowed']));
            $this->response->sendResponse();
            return;
        }

        // Handle POST request - process payment and redirect user
        return $this->handlePostCallback();
    }

    /**
     * Debug session information
     */
    protected function debugSessionInfo($context)
    {
        $sessionId = session_id();
        $magentoSessionId = $this->checkoutSession->getSessionId();

        $debug = [
            'context' => $context,
            'php_session_id' => $sessionId,
            'magento_session_id' => $magentoSessionId,
            'session_name' => session_name(),
            'cookie_params' => session_get_cookie_params(),
            'headers' => getallheaders(),
            // Add HTTPS detection debugging
            'https_detection' => [
                'SERVER_HTTPS' => $_SERVER['HTTPS'] ?? 'not set',
                'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'not set',
                'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? 'not set',
                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'not set',
                'REQUEST_SCHEME' => $_SERVER['REQUEST_SCHEME'] ?? 'not set',
                'is_https_detected' => $this->isHttpsRequest() ? 'true' : 'false'
            ]
        ];

        try {
            $debug['last_order_id'] = $this->checkoutSession->getLastOrderId();
            $debug['quote_id'] = $this->checkoutSession->getQuoteId();
        } catch (\Exception $e) {
            $debug['session_error'] = $e->getMessage();
        }

        $this->logger->info('Session Debug', $debug);

        // Use the debug function for stdout
        $this->debugToStdout('Session Debug: ' . $context, $debug);
    }

    /**
     * Debug to stdout
     */
    protected function debugToStdout($message, ...$params)
    {
        static $stdout;

        if ($stdout === null) {
            $stdout = fopen('php://stdout', 'w');
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? [];

        $line = $caller['line'] ?? 'n/a';
        $class = $caller['class'] ?? '';
        $type = $caller['type'] ?? '';
        $function = $caller['function'] ?? '';

        $yellow = "\033[33m";
        $reset = "\033[0m";

        $location = sprintf(
            "[{$yellow}DEBUG@%s%s%s():%s{$reset}] ",
            $class,
            $type,
            $function,
            $line
        );

        if (count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        } else if (count($params) === 0 && is_array($message)) {
            $params = [$message];
            $message = null;
        }

        if ($message === null) {
            $message = '';
        }

        fwrite($stdout, $location . $message . PHP_EOL);

        foreach ($params as $param) {
            fwrite($stdout, var_export($param, true) . PHP_EOL);
        }
    }

    /**
     * Handle GET callback (redirect after IRIS flow - user returns from bank)
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    protected function handleGetCallback()
    {
        $token = $this->getRequest()->getParam('token');
        $md = $this->getRequest()->getParam('md');

        $order = $this->findOrderByIrisReference($token, $md);

        if ($order && $order->getId()) {
            // User successfully returned from IRIS payment flow
            // Redirect to success page
            /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('checkout/onepage/success', ['_query' => ['order_id' => $order->getId()]]);
            return $resultRedirect;
        }

        // Order not found - show error and redirect to cart
        $this->messageManager->addErrorMessage(
            __('Payment reference not found. Please contact support if you completed the payment.')
        );

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/cart');
        return $resultRedirect;
    }

    /**
     * Handle POST callback - process payment and redirect user
     * Following WooCommerce pattern: verify hash, process payment, redirect to success/error page
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    protected function handlePostCallback()
    {
        // Debug session BEFORE processing
        $this->debugSessionInfo('POST Callback Before Processing');

        try {
            // Verify hash and process payment
            $result = $this->processPostCallback();

            // Debug session AFTER processing
            $this->debugSessionInfo('POST Callback After Processing');

            // Add error message to session if present (before redirect)
            if (isset($result['error_message'])) {
                $this->messageManager->addErrorMessage($result['error_message']);
                $this->debugToStdout('Added error message to session: ' . $result['error_message']);
            }

            // Check if we have a redirect URL
            if (isset($result['redirect_url']) && !empty($result['redirect_url'])) {
                $this->debugToStdout('Redirecting to: ' . $result['redirect_url']);

                /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
                $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                $resultRedirect->setUrl($result['redirect_url']);
                return $resultRedirect;
            }

            // If no redirect URL, fall back to checkout (error case)
            $this->debugToStdout('No redirect URL, falling back to checkout/cart');

            /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;

        } catch (\Exception $e) {
            $this->logger->error('IRIS callback error: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            $this->messageManager->addErrorMessage(
                __('IRIS payment error. Please contact support if you completed the payment.')
            );

            /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }
    }

    /**
     * Process POST callback following WooCommerce pattern
     * Verify hash, process payment, return redirect URL or error
     *
     * @return array ['redirect_url' => string] or ['error_message' => string]
     */
    protected function processPostCallback()
    {
        // Get hash from POST data
        $hash = $this->getRequest()->getParam('hash');

        if (empty($hash)) {
            $this->logger->error('IRIS callback: Missing hash');
            return ['error_message' => __('IRIS payment error. Missing hash.')];
        }

        // Get secret key
        $secretKey = $this->epConfig->getSecretKey();
        if (empty($secretKey)) {
            $this->logger->error('IRIS callback: Secret key not configured');
            return ['error_message' => __('EveryPay is not configured properly. Please contact support.')];
        }

        // Decode and verify hash
        $decoded = base64_decode($hash, true);
        if ($decoded === false || strpos($decoded, '|') === false) {
            $this->logger->error('IRIS callback: Invalid hash payload');
            return ['error_message' => __('IRIS payment error. Invalid hash payload.')];
        }

        list($providedHash, $payloadJson) = explode('|', $decoded, 2);
        $calculatedHash = hash_hmac('sha256', $payloadJson, $secretKey);

        if (!hash_equals($providedHash, $calculatedHash)) {
            $this->logger->error('IRIS callback: Hash verification failed');
            return ['error_message' => __('IRIS payment error. Hash verification failed.')];
        }

        // Parse payload
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        // Log verified callback
        $this->logger->info('IRIS Callback Verified', [
            'payload' => $payload,
            'verified' => true
        ]);

        // Extract data from payload
        $token = $payload['token'] ?? $this->getRequest()->getParam('token') ?? '';
        $md = $payload['md'] ?? $this->getRequest()->getParam('md') ?? '';
        $errorStatus = $payload['error_status'] ?? $this->getRequest()->getParam('error_status') ?? '';
        $errorMessage = $payload['error_message'] ?? $this->getRequest()->getParam('error_message') ?? '';
        $hasError = !empty($errorStatus) || (!empty($errorMessage) && empty($token));

        $this->logger->info('IRIS Callback Data', [
            'token' => $token,
            'md' => $md,
            'error_status' => $errorStatus,
            'error_message' => $errorMessage,
            'has_error' => $hasError
        ]);

        // Find order
        $order = $this->findOrderByIrisReference($token, $md);

        if (!$order || !$order->getEntityId()) {
            $message = __('Your payment session has expired. Please try placing your order again.');
            $this->logger->error('IRIS callback: Order not found - order must be created before IRIS redirect', [
                'token' => $token,
                'md' => $md,
                'session_id' => $this->checkoutSession->getSessionId()
            ]);

            return [
                'error_message' => $message,
                'redirect_url' => $this->_url->getUrl('checkout/cart')
            ];
        }

        // Store IRIS metadata
        if ($md && !$order->getData('everypay_iris_md')) {
            $order->setData('everypay_iris_md', $md);
        }
        if ($token && !$order->getData('everypay_source_token')) {
            $order->setData('everypay_source_token', $token);
        }

        $paymentToken = null;

        // Check if order is already paid (duplicate callback)
        $isPaid = $order->getState() === \Magento\Sales\Model\Order::STATE_PROCESSING
                  || $order->getState() === \Magento\Sales\Model\Order::STATE_COMPLETE;

        // Process payment if no error and not already paid
        if (!$hasError && !$isPaid) {
            try {
                $this->logger->info('Processing IRIS payment', [
                    'order_id' => $order->getId(),
                    'token' => $token,
                    'order_state' => $order->getState()
                ]);

                $paymentToken = $this->createIrisPayment($order, $token);

                if (empty($paymentToken)) {
                    throw new \Exception('IRIS payment failed. Please try another payment method.');
                }

                $this->logger->info('IRIS payment created', [
                    'order_id' => $order->getId(),
                    'payment_token' => $paymentToken
                ]);

                // Update order with payment info
                $order->setData('everypay_payment_token', $paymentToken);
                $order->setData('everypay_payment_method', 'iris');

                // Complete payment
                $payment = $order->getPayment();
                $payment->setTransactionId($paymentToken);
                $payment->setIsTransactionClosed(false);
                $payment->registerCaptureNotification($order->getGrandTotal());

                $order->addCommentToStatusHistory('EveryPay IRIS payment completed.');
                $this->orderRepository->save($order);

                $this->logger->info('IRIS payment completed successfully', [
                    'order_id' => $order->getId(),
                    'payment_token' => $paymentToken
                ]);

            } catch (\Exception $e) {
                $hasError = true;
                $errorMessage = $e->getMessage();
                $this->logger->error('IRIS payment error: ' . $e->getMessage(), [
                    'order_id' => $order->getId(),
                    'exception' => $e
                ]);
            }
        } elseif ($isPaid) {
            // Order already paid, this is a duplicate callback
            $this->logger->info('IRIS callback for already paid order', [
                'order_id' => $order->getId(),
                'order_state' => $order->getState()
            ]);
        }

        // Handle errors
        if ($hasError) {
            $message = $errorMessage ?: __('IRIS payment failed. Please try another payment method.');

            $order->addCommentToStatusHistory('EveryPay IRIS callback error: ' . $message);
            $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
            $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            $this->orderRepository->save($order);

            return [
                'error_message' => $message,
                'redirect_url' => $this->_url->getUrl('checkout/cart')
            ];
        }

        // Payment successful - redirect to success page
        $order->addCommentToStatusHistory('Everypay IRIS callback received.');
        $order->getPayment()->setAdditionalInformation('payment_type', 'IRIS Bank Payment');
        $this->orderRepository->save($order);

        // Store last order ID in session for success page
        $this->checkoutSession->setLastOrderId($order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());

        // Build success URL
        $successUrl = $this->_url->getUrl('checkout/onepage/success', ['_query' => ['order_id' => $order->getId()]]);

        return ['redirect_url' => $successUrl];
    }

    /**
     * Find order for IRIS payment
     * Don't rely on session - use persistent data from quote/order lookup
     *
     * @param string $token
     * @param string $md
     * @return \Magento\Sales\Model\Order|null
     */
    protected function findOrderByIrisReference($token, $md)
    {
        $this->debugToStdout('Finding order for IRIS callback - md: ' . $md . ', token: ' . $token);

        // Extract quote ID from md parameter if present
        if (strpos($md, '_qid_') !== false) {
            $parts = explode('_qid_', $md);
            if (count($parts) === 2) {
                $quoteId = (int)$parts[1];

                $this->debugToStdout('Extracted quote ID from md: ' . $quoteId);

                try {
                    // Find order by quote ID (ignores session completely)
                    $orderCollection = $this->orderFactory->create()->getCollection()
                        ->addFieldToFilter('quote_id', $quoteId)
                        ->setOrder('created_at', 'DESC')
                        ->setPageSize(1);

                    if ($orderCollection->getSize() > 0) {
                        /** @var \Magento\Sales\Model\Order $order */
                        $order = $orderCollection->getFirstItem();

                        $this->debugToStdout('Found order by quote ID: ' . $order->getEntityId());

                        return $order;
                    } else {
                        $this->debugToStdout('No order found for quote ID: ' . $quoteId);

                        // CRITICAL FIX: Create order from quote if not found
                        $this->debugToStdout('Attempting to create order from quote: ' . $quoteId);
                        $order = $this->createOrderFromQuote($quoteId);

                        if ($order) {
                            $this->debugToStdout('Successfully created order: ' . $order->getEntityId());
                            return $order;
                        } else {
                            $this->debugToStdout('Failed to create order from quote');
                        }
                    }
                } catch (\Exception $e) {
                    $this->debugToStdout('Error finding order by quote ID: ' . $e->getMessage());
                }
            }
        }

        // Fallback: Check session if available (but don't rely on it)
        try {
            $orderId = $this->checkoutSession->getLastOrderId();

            if ($orderId) {
                /** @var \Magento\Sales\Model\Order $order */
                $order = $this->orderRepository->get($orderId);

                if ($order && $order->getEntityId()) {
                    $this->debugToStdout('Found order from session as fallback: ' . $order->getEntityId());
                    return $order;
                }
            }
        } catch (\Exception $e) {
            $this->debugToStdout('Session fallback failed: ' . $e->getMessage());
        }

        $this->debugToStdout('No order found - this means order was never created before IRIS redirect');
        return null;
    }

    /**
     * Create order from quote ID for IRIS callback
     * Emergency order creation when order wasn't created before redirect
     */
    protected function createOrderFromQuote($quoteId)
    {
        try {
            $this->debugToStdout('Loading quote ID: ' . $quoteId);

            // Load quote by ID
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quoteRepository = $objectManager->get(\Magento\Quote\Api\CartRepositoryInterface::class);
            $cartManagement = $objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
            $orderRepository = $objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);

            $quote = $quoteRepository->get($quoteId);

            if (!$quote || !$quote->getId()) {
                $this->debugToStdout('Quote not found for ID: ' . $quoteId);
                return null;
            }

            $this->debugToStdout('Quote loaded successfully: ' . $quote->getId() . ', total: ' . $quote->getGrandTotal());

            // Properly configure payment method for order creation
            $payment = $quote->getPayment();
            $payment->setMethod('everypay');

            // CRITICAL: Get all IRIS callback data from the POST request
            $request = $this->getRequest();
            $md = $request->getParam('md');
            $token = $request->getParam('token');
            $hash = $request->getParam('hash');

            $this->debugToStdout('IRIS Callback Data - md: ' . ($md ?: 'NOT_PROVIDED'));
            $this->debugToStdout('IRIS Callback Data - token: ' . ($token ?: 'NOT_PROVIDED'));
            $this->debugToStdout('IRIS Callback Data - hash: ' . ($hash ? 'PROVIDED' : 'NOT_PROVIDED'));

            // Set payment method additional information for IRIS
            $payment->setAdditionalInformation('payment_type', 'IRIS');
            $payment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');

            // CRITICAL: Store all IRIS callback data for transaction validation
            if ($md) {
                $payment->setAdditionalInformation('iris_md', $md);
            }
            if ($token) {
                $payment->setAdditionalInformation('token', $token);
                $payment->setAdditionalInformation('iris_token', $token);
                $this->debugToStdout('Set IRIS token in payment: ' . $token);
            }
            if ($hash) {
                $payment->setAdditionalInformation('iris_hash', $hash);
            }

            // Set transaction result as success (since callback was triggered)
            $payment->setAdditionalInformation('transaction_result', 'success');

            if (!$token) {
                $this->debugToStdout('ERROR: No IRIS token found in callback - order creation will likely fail');
                return null;
            }

            // Save quote with payment method
            $quoteRepository->save($quote);
            $quote->collectTotals();

            $this->debugToStdout('Payment method set to everypay with additional info');

            // Create order from quote
            $orderId = $cartManagement->placeOrder($quote->getId());

            /** @var \Magento\Sales\Model\Order $order */
            $order = $orderRepository->get($orderId);

            // CRITICAL: Update order payment info to show IRIS
            $orderPayment = $order->getPayment();
            $orderPayment->setAdditionalInformation('payment_type', 'IRIS');
            $orderPayment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');
            if ($token) {
                $orderPayment->setAdditionalInformation('iris_token', $token);
            }
            if ($md) {
                $orderPayment->setAdditionalInformation('iris_md', $md);
            }
            if ($hash) {
                $orderPayment->setAdditionalInformation('iris_hash', $hash);
            }

            // Update payment method title in the order
            $orderPayment->setMethod('everypay');
            $orderPayment->save();

            $this->debugToStdout('Updated order payment info with IRIS details');

            // Store order ID in session for success page
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastQuoteId($quote->getId());
            $this->checkoutSession->setLastSuccessQuoteId($quote->getId());

            $this->debugToStdout('Order created from quote - Order ID: ' . $order->getId() . ', Increment ID: ' . $order->getIncrementId());

            return $order;

        } catch (\Exception $e) {
            $this->debugToStdout('Failed to create order from quote: ' . $e->getMessage());
            $this->logger->error('IRIS: Failed to create order from quote during callback', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create IRIS payment
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $token
     * @return string|null
     */
    protected function createIrisPayment($order, $token)
    {
        try {
            $amount = (int)($order->getGrandTotal() * 100);
            $storeName = $order->getStore()->getName();
            $orderNumber = $order->getIncrementId();

            $description = $storeName . ' / Order #' . $orderNumber;

            $params = [
                'amount' => $amount,
                'description' => $description,
                'token' => $token,
            ];

            if ($order->getCustomerEmail()) {
                $params['payee_email'] = $order->getCustomerEmail();
            }

            $billingAddress = $order->getBillingAddress();
            if ($billingAddress && $billingAddress->getTelephone()) {
                $params['payee_phone'] = preg_replace('/[^0-9+]/', '', $billingAddress->getTelephone());
            }

            if ($order->getOrderCurrencyCode()) {
                $params['currency'] = strtoupper($order->getOrderCurrencyCode());
            }

            if ($billingAddress && $billingAddress->getCountryId()) {
                $params['country'] = strtoupper($billingAddress->getCountryId());
            }

            Everypay::setApiKey($this->epConfig->getSecretKey());
            Everypay::$isTest = (bool)$this->epConfig->getSandboxMode();

            $response = Payment::create($params);

            $this->logger->debug('IRIS Payment Request', ['params' => $params]);
            $this->logger->debug('IRIS Payment Response', ['response' => $response]);

            if (isset($response->error)) {
                throw new \Exception($response->error->message ?? 'Payment creation failed');
            }

            $paymentToken = $response->token ?? null;

            return $paymentToken;

        } catch (\Exception $e) {
            $this->logger->error('IRIS payment creation error: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return null;
        }
    }
}
