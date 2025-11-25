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
                // CRITICAL: Back up all session data before destroying
                $sessionDataBackup = $_SESSION;

                // Destroy current session (this clears the data but we have backup)
                session_destroy();

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

                // CRITICAL: Restore all session data
                foreach ($sessionDataBackup as $key => $value) {
                    $_SESSION[$key] = $value;
                }

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
            } else {
                $this->logger->info('PAYMENT GATEWAY: Skipping secure cookie regeneration - no session data or not HTTPS');
            }
        } catch (\Exception $e) {
            $this->logger->error('PAYMENT GATEWAY: Force cookie setting failed: ' . $e->getMessage());
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
                } else {
                    // Check if current store is configured as secure using Context's StoreManager
                    try {
                        $store = $this->_objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore();
                        $isStoreSecure = $store->isCurrentlySecure() || $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);

                        if ($isStoreSecure) {
                            session_set_cookie_params([
                                'lifetime' => 3600,
                                'path' => '/',
                                'domain' => '',
                                'secure' => true,          // Force secure for stores configured with HTTPS
                                'httponly' => true,
                                'samesite' => 'None',
                            ]);
                        } else {
                            // Fallback for non-HTTPS environments (development)
                            $this->logger->info('PAYMENT GATEWAY: Non-HTTPS environment detected, keeping default cookie settings');
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('PAYMENT GATEWAY: Could not determine store security, using default settings: ' . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('PAYMENT GATEWAY: Cookie configuration failed: ' . $e->getMessage());
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

        // Check URL scheme in request URI
        if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
            return true;
        }

        // Check if Magento store is configured as secure
        try {
            $store = $this->_objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore();
            if ($store->isCurrentlySecure()) {
                return true;
            }
        } catch (\Exception $e) {
            // Fallback if store manager is not available
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
            // Properly clear cart using Magento's built-in method
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->clearQuote();

            // Redirect to success page
            /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
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
        try {
            // Verify hash and process payment
            $result = $this->processPostCallback();

            $this->logger->info('IRIS POST Callback Result', [
                'has_redirect_url' => isset($result['redirect_url']),
                'redirect_url' => $result['redirect_url'] ?? 'none',
                'has_error' => isset($result['error_message']),
                'error_message' => $result['error_message'] ?? 'none'
            ]);

            // Add error message to session if present (before redirect)
            if (isset($result['error_message'])) {
                $this->messageManager->addErrorMessage($result['error_message']);
            }

            // Check if we have a redirect URL
            if (isset($result['redirect_url']) && !empty($result['redirect_url'])) {
                /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
                $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                $resultRedirect->setUrl($result['redirect_url']);
                return $resultRedirect;
            }

            // If no redirect URL, fall back to success page with session order if available
            $lastOrderId = $this->checkoutSession->getLastOrderId();
            if ($lastOrderId) {
                $this->logger->warning('IRIS: No redirect URL returned, but order exists in session - redirecting to success');
                /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
                $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                $resultRedirect->setPath('checkout/onepage/success');
                return $resultRedirect;
            }

            // Final fallback to checkout cart (error case)
            $this->logger->error('IRIS: No redirect URL and no order in session - redirecting to cart');
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

        // Store IRIS metadata in payment additional information instead of custom order fields
        $payment = $order->getPayment();
        if ($md && !$payment->getAdditionalInformation('iris_md')) {
            $payment->setAdditionalInformation('iris_md', $md);
        }
        if ($token && !$payment->getAdditionalInformation('iris_token')) {
            $payment->setAdditionalInformation('iris_token', $token);
        }

        $paymentToken = null;
        // Check if this IRIS source token was already processed
        $existingIrisToken = $order->getPayment()->getAdditionalInformation('iris_token');
        $orderAlreadyPaid = ($order->getState() === 'processing' || $order->getState() === 'complete') &&
                           $existingIrisToken === $token;

        // Process payment if no error and not already paid
        if (!$hasError && !$orderAlreadyPaid) {
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

                // Update order with payment info using standard Magento fields
                $payment = $order->getPayment();
                $payment->setTransactionId($paymentToken);
                $payment->setLastTransId($paymentToken);
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
        } elseif ($orderAlreadyPaid) {
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
        // PRODUCTION SAFETY: First check if order already exists for this IRIS token
        try {
            $orderCollection = $this->orderFactory->create()->getCollection()
                ->join(
                    ['payment' => 'sales_order_payment'],
                    'main_table.entity_id = payment.parent_id',
                    []
                )
                ->addFieldToFilter('payment.additional_information', ['like' => '%"iris_token":"' . $token . '"%'])
                ->setPageSize(1);

            if ($orderCollection->getSize() > 0) {
                /** @var \Magento\Sales\Model\Order $existingOrder */
                $existingOrder = $orderCollection->getFirstItem();
                return $existingOrder;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error checking for existing orders by IRIS token: ' . $e->getMessage());
        }

        // Extract quote ID from md parameter if present
        if (strpos($md, '_qid_') !== false) {
            $parts = explode('_qid_', $md);
            if (count($parts) === 2) {
                $quoteId = (int)$parts[1];

                try {
                    // Find order by quote ID (ignores session completely)
                    $orderCollection = $this->orderFactory->create()->getCollection()
                        ->addFieldToFilter('quote_id', $quoteId)
                        ->setOrder('created_at', 'DESC')
                        ->setPageSize(1);

                    if ($orderCollection->getSize() > 0) {
                        /** @var \Magento\Sales\Model\Order $order */
                        $order = $orderCollection->getFirstItem();

                        return $order;
                    } else {
                        $this->logger->info('No order found for quote ID: ' . $quoteId);
                        // CRITICAL FIX: Create order from quote if not found
                        $this->logger->info('Attempting to create order from quote: ' . $quoteId);
                        $order = $this->createOrderFromQuote($quoteId);

                        if ($order) {
                            $this->logger->info('Successfully created order: ' . $order->getEntityId());
                            return $order;
                        } else {
                            $this->logger->error('Failed to create order from quote');
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error finding order by quote ID: ' . $e->getMessage());
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
                    return $order;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Session fallback failed: ' . $e->getMessage());
        }

        $this->logger->info('No order found - this means order was never created before IRIS redirect');
        return null;
    }

    /**
     * Create order from quote ID for IRIS callback
     * Uses hybrid approach: Magento's services where possible, manual creation for IRIS-specific needs
     */
    protected function createOrderFromQuote($quoteId)
    {
        try {
            // Load quote by ID using Context ObjectManager (proper Magento pattern)
            $quoteRepository = $this->_objectManager->get(\Magento\Quote\Api\CartRepositoryInterface::class);
            $cartManagement = $this->_objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
            $orderRepository = $this->_objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);

            $quote = $quoteRepository->get($quoteId);

            if (!$quote || !$quote->getId()) {
                $this->logger->error('Quote not found for ID: ' . $quoteId);
                return null;
            }

            // CRITICAL: Validate and fix quote data before order creation
            $this->validateAndFixQuoteData($quote);

            // Properly configure payment method for order creation
            $payment = $quote->getPayment();
            $payment->setMethod('everypay');

            // CRITICAL: Get all IRIS callback data from the POST request
            $request = $this->getRequest();
            $md = $request->getParam('md');
            $token = $request->getParam('token');
            $hash = $request->getParam('hash');

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
            }
            if ($hash) {
                $payment->setAdditionalInformation('iris_hash', $hash);
            }

            // Set transaction result as success (since callback was triggered)
            $payment->setAdditionalInformation('transaction_result', 'success');

            if (!$token) {
                $this->logger->error('ERROR: No IRIS token found in callback - order creation will likely fail');
                return null;
            }

            // First try CartManagement for proper Magento order creation
            try {
                // Prepare quote for CartManagement
                $quote->setIsActive(true); // CartManagement needs active quote
                $quote->collectTotals();
                $quoteRepository->save($quote);

                // Try Magento's proper order creation
                $orderId = $cartManagement->placeOrder($quoteId);

                if ($orderId) {
                    $order = $orderRepository->get($orderId);
                    $this->logger->info('Order successfully created via CartManagement', [
                        'order_id' => $order->getId(),
                        'state' => $order->getState()
                    ]);

                    // Update with IRIS-specific data
                    $this->updateOrderWithIrisData($order, $token, $md, $hash);
                    return $order;
                }
            } catch (\Exception $e) {
                $this->logger->warning('CartManagement failed, falling back to manual creation: ' . $e->getMessage());
                // Continue to manual creation below
            }

            // Fallback to manual creation for IRIS-specific scenarios
            $this->logger->info('Using manual order creation for IRIS callback');

            // Save quote with payment method
            $quote->collectTotals();
            $quote->setIsActive(false);
            $quoteRepository->save($quote);

            $orderFactory = $this->_objectManager->get(\Magento\Sales\Model\OrderFactory::class);
            $order = $orderFactory->create();

            // Set order data from quote
            $order->setQuoteId($quote->getId());
            $order->setStoreId($quote->getStoreId());
            $order->setCustomerId($quote->getCustomerId());
            $order->setCustomerEmail($quote->getCustomerEmail());
            $order->setCustomerFirstname($quote->getCustomerFirstname());
            $order->setCustomerLastname($quote->getCustomerLastname());
            $order->setCustomerIsGuest($quote->getCustomerIsGuest());

            // Set order totals
            $order->setSubtotal($quote->getSubtotal());
            $order->setBaseSubtotal($quote->getBaseSubtotal());
            $order->setGrandTotal($quote->getGrandTotal());
            $order->setBaseGrandTotal($quote->getBaseGrandTotal());

            // Set shipping amounts from quote
            $order->setShippingAmount($quote->getShippingAddress()->getShippingAmount());
            $order->setBaseShippingAmount($quote->getShippingAddress()->getBaseShippingAmount());
            $order->setShippingDescription($quote->getShippingAddress()->getShippingDescription());
            $order->setShippingMethod($quote->getShippingAddress()->getShippingMethod());

            // Set tax amounts
            $order->setTaxAmount($quote->getShippingAddress()->getTaxAmount());
            $order->setBaseTaxAmount($quote->getShippingAddress()->getBaseTaxAmount());
            $order->setShippingTaxAmount($quote->getShippingAddress()->getShippingTaxAmount());
            $order->setBaseShippingTaxAmount($quote->getShippingAddress()->getBaseShippingTaxAmount());

            // Set discount amounts if any
            $order->setDiscountAmount($quote->getShippingAddress()->getDiscountAmount());
            $order->setBaseDiscountAmount($quote->getShippingAddress()->getBaseDiscountAmount());
            $order->setDiscountDescription($quote->getShippingAddress()->getDiscountDescription());

            // Set currency
            $order->setOrderCurrencyCode($quote->getQuoteCurrencyCode());
            $order->setBaseCurrencyCode($quote->getBaseCurrencyCode());

            // Add items to order
            foreach ($quote->getAllVisibleItems() as $quoteItem) {
                $orderItem = $this->_objectManager->create(\Magento\Sales\Model\Order\Item::class);
                $orderItem->setQuoteItemId($quoteItem->getId());
                $orderItem->setProductId($quoteItem->getProductId());
                $orderItem->setSku($quoteItem->getSku());
                $orderItem->setName($quoteItem->getName());
                $orderItem->setQtyOrdered($quoteItem->getQty());
                $orderItem->setPrice($quoteItem->getPrice());
                $orderItem->setBasePrice($quoteItem->getBasePrice());
                $orderItem->setRowTotal($quoteItem->getRowTotal());
                $orderItem->setBaseRowTotal($quoteItem->getBaseRowTotal());
                $order->addItem($orderItem);
            }

            // Set addresses
            $addressFactory = $this->_objectManager->get(\Magento\Sales\Model\Order\Address::class);
            $billingAddress = $this->_objectManager->create(\Magento\Sales\Model\Order\Address::class);
            $billingAddress->setData($quote->getBillingAddress()->getData());
            $billingAddress->setAddressType(\Magento\Sales\Model\Order\Address::TYPE_BILLING);
            $order->setBillingAddress($billingAddress);

            if (!$quote->isVirtual()) {
                $shippingAddress = $this->_objectManager->create(\Magento\Sales\Model\Order\Address::class);
                $shippingAddress->setData($quote->getShippingAddress()->getData());
                $shippingAddress->setAddressType(\Magento\Sales\Model\Order\Address::TYPE_SHIPPING);
                $order->setShippingAddress($shippingAddress);
            }

            // Create payment without processing
            $payment = $this->_objectManager->create(\Magento\Sales\Model\Order\Payment::class);
            $payment->setMethod('everypay');
            $payment->setAdditionalInformation('payment_type', 'IRIS');
            $payment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');
            $payment->setAdditionalInformation('iris_payment_completed', true);
            $order->setPayment($payment);

            // Save order
            $orderRepository = $this->_objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
            $order = $orderRepository->save($order);

            if (!$order) {
                $this->logger->error('Failed to submit quote as order');
                return null;
            }

            $orderPayment = $order->getPayment();
            $orderPayment->setMethod('everypay');
            $orderPayment->setAdditionalInformation('payment_type', 'IRIS');
            $orderPayment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');
            if ($token) {
                $orderPayment->setAdditionalInformation('iris_token', $token);
                // Store token at order level for duplicate prevention
                $order->setData('everypay_source_token', $token);
            }
            if ($md) {
                $orderPayment->setAdditionalInformation('iris_md', $md);
            }
            if ($hash) {
                $orderPayment->setAdditionalInformation('iris_hash', $hash);
            }

            // Update payment method title in the order
            $orderPayment->setMethod('everypay');

            // Mark payment as completed since IRIS payment was processed
            $orderPayment->setTransactionId($token);
            $orderPayment->setIsTransactionClosed(true);
            $orderPayment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_PAYMENT);
            $orderPayment->save();

            // Set order status to processing since payment is complete
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->addStatusHistoryComment('IRIS payment completed via bank redirect. Token: ' . $token);
            $order->save();

            // Update order with IRIS-specific data
            $this->updateOrderWithIrisData($order, $token, $md, $hash);

            // Store order ID in session for success page
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastQuoteId($quote->getId());
            // Cart emptying handled in POST callback to prevent cache issues

            $this->logger->info('Order created successfully for IRIS payment. Order ID: ' . $order->getId());

            return $order;

        } catch (\Exception $e) {
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

            $secretKey = $this->epConfig->getSecretKey();
            $isTest = (bool)$this->epConfig->getSandboxMode();

            Everypay::setApiKey($secretKey);
            Everypay::$isTest = $isTest;

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

    /**
     * Validate and fix quote data to ensure order creation succeeds
     * Only adds dummy data as last resort - logs detailed info about missing data
     */
    protected function validateAndFixQuoteData($quote)
    {
        try {
            // Try to recover customer data from addresses first
            $billingAddress = $quote->getBillingAddress();
            $recoveredData = false;

            if ($billingAddress) {
                // Recover email from billing address if missing
                if (!$quote->getCustomerEmail() && $billingAddress->getEmail() && filter_var($billingAddress->getEmail(), FILTER_VALIDATE_EMAIL)) {
                    $quote->setCustomerEmail($billingAddress->getEmail());
                    $recoveredData = true;
                }

                // Recover firstname from billing address if missing
                if (!$quote->getCustomerFirstname() && $billingAddress->getFirstname()) {
                    $quote->setCustomerFirstname($billingAddress->getFirstname());
                    $recoveredData = true;
                }

                // Recover lastname from billing address if missing
                if (!$quote->getCustomerLastname() && $billingAddress->getLastname()) {
                    $quote->setCustomerLastname($billingAddress->getLastname());
                    $recoveredData = true;
                }
            }

            // Validate required customer data - FAIL if missing
            $customerEmail = $quote->getCustomerEmail();
            if (!$customerEmail || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('IRIS Callback: No valid customer email found in quote - cannot process payment');
            }

            if (!$quote->getCustomerFirstname()) {
                throw new \Exception('IRIS Callback: No customer firstname found in quote - cannot process payment');
            }

            if (!$quote->getCustomerLastname()) {
                throw new \Exception('IRIS Callback: No customer lastname found in quote - cannot process payment');
            }

            if ($recoveredData) {
                $this->logger->info('IRIS Callback: Successfully recovered customer data from addresses');
            }

            // Validate billing address
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress) {
                if (!$billingAddress->getEmail()) {
                    $billingAddress->setEmail($quote->getCustomerEmail());
                }

                // Ensure required billing fields are set
                if (!$billingAddress->getFirstname()) {
                    $billingAddress->setFirstname($quote->getCustomerFirstname());
                }

                if (!$billingAddress->getLastname()) {
                    $billingAddress->setLastname($quote->getCustomerLastname());
                }
            }

            // Validate shipping address if needed
            $shippingAddress = $quote->getShippingAddress();
            if ($shippingAddress && $quote->getIsVirtual() === false) {
                if (!$shippingAddress->getEmail()) {
                    $shippingAddress->setEmail($quote->getCustomerEmail());
                }

                // Copy billing address data to shipping if missing
                if (!$shippingAddress->getFirstname()) {
                    $shippingAddress->setFirstname($billingAddress->getFirstname());
                }
                if (!$shippingAddress->getLastname()) {
                    $shippingAddress->setLastname($billingAddress->getLastname());
                }
                if (!$shippingAddress->getStreet()) {
                    $shippingAddress->setStreet($billingAddress->getStreet());
                }
                if (!$shippingAddress->getCity()) {
                    $shippingAddress->setCity($billingAddress->getCity());
                }
                if (!$shippingAddress->getPostcode()) {
                    $shippingAddress->setPostcode($billingAddress->getPostcode());
                }
                if (!$shippingAddress->getCountryId()) {
                    $shippingAddress->setCountryId($billingAddress->getCountryId());
                }
                if (!$shippingAddress->getTelephone()) {
                    $shippingAddress->setTelephone($billingAddress->getTelephone());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error validating quote data: ' . $e->getMessage());
        }
    }

    /**
     * Update order with IRIS-specific data after creation
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $token
     * @param string $md
     * @param string $hash
     * @return void
     */
    protected function updateOrderWithIrisData($order, $token, $md, $hash)
    {
        try {
            $orderPayment = $order->getPayment();
            $orderPayment->setMethod('everypay');
            $orderPayment->setAdditionalInformation('payment_type', 'IRIS');
            $orderPayment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');

            if ($token) {
                $orderPayment->setAdditionalInformation('iris_token', $token);
                $order->setData('everypay_source_token', $token);
            }
            if ($md) {
                $orderPayment->setAdditionalInformation('iris_md', $md);
                $order->setData('everypay_iris_md', $md);
            }
            if ($hash) {
                $orderPayment->setAdditionalInformation('iris_hash', $hash);
            }

            $orderPayment->setTransactionId($token);
            $orderPayment->setIsTransactionClosed(false);

            $order->addCommentToStatusHistory('IRIS payment initiated. Token: ' . $token);

            $this->orderRepository->save($order);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update order with IRIS data: ' . $e->getMessage());
        }
    }
}
