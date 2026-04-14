<?php

/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Everypay\Everypay\Model;

use Everypay\Everypay;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Everypay\Payment;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class IrisNotificationProcessor
{
    private $orderFactory;
    private $orderRepository;
    private $quoteRepository;
    private $cartManagement;
    private $quoteManagement;
    private $epConfig;
    private $logger;
    private $objectManager;

    public function __construct(
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $quoteRepository,
        CartManagementInterface $cartManagement,
        QuoteManagement $quoteManagement,
        EverypayConfig $epConfig,
        LoggerInterface $logger,
        ObjectManagerInterface $objectManager
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->quoteRepository = $quoteRepository;
        $this->cartManagement = $cartManagement;
        $this->quoteManagement = $quoteManagement;
        $this->epConfig = $epConfig;
        $this->logger = $logger;
        $this->objectManager = $objectManager;
    }

    public function processNotification(RequestInterface $request, $source = 'callback')
    {
        $payloadResult = $this->extractPayload($request);
        if (!$payloadResult['success']) {
            return $payloadResult;
        }

        $token = $payloadResult['token'];
        $md = $payloadResult['md'];
        $hasError = $payloadResult['has_error'];
        $errorMessage = $payloadResult['error_message'];

        $order = $this->findOrderByIrisReference($token, $md, true);
        if (!$order || !$order->getEntityId()) {
            $this->logger->error('IRIS notification: order not found', [
                'source' => $source,
                'token' => $token,
                'md' => $md,
            ]);

            return [
                'success' => false,
                'error_message' => __('Your payment session has expired. Please try placing your order again.'),
                'order' => null,
            ];
        }

        $payment = $order->getPayment();
        $existingToken = (string) $payment->getAdditionalInformation('iris_token');
        $orderAlreadyPaid = $this->isSuccessfulOrder($order);

        if ($orderAlreadyPaid && $existingToken && $token && $existingToken !== $token) {
            $this->logger->error('IRIS notification token conflict', [
                'source' => $source,
                'order_id' => $order->getEntityId(),
                'existing_token' => $existingToken,
                'incoming_token' => $token,
            ]);

            return [
                'success' => false,
                'error_message' => __('IRIS payment token conflict. Please contact support.'),
                'order' => $order,
            ];
        }

        $this->applyIrisMetadata($order, $token, $md, $payloadResult['hash']);

        if ($orderAlreadyPaid && (!$token || !$existingToken || $existingToken === $token)) {
            $this->orderRepository->save($order);
            $this->logger->info('IRIS notification already processed', [
                'source' => $source,
                'order_id' => $order->getEntityId(),
                'token' => $token,
            ]);

            return [
                'success' => true,
                'already_processed' => true,
                'order' => $order,
            ];
        }

        if ($hasError) {
            $message = $errorMessage ?: __('IRIS payment failed. Please try another payment method.');
            $this->markOrderAsFailed($order, $message, $source);

            return [
                'success' => false,
                'error_message' => $message,
                'order' => $order,
            ];
        }

        $paymentToken = $this->createIrisPayment($order, $token);
        if (empty($paymentToken)) {
            $reloadedOrder = $this->reloadOrder($order);
            if ($reloadedOrder && $this->isIdempotentPaidOrder($reloadedOrder, $token)) {
                $this->logger->info('IRIS payment creation returned empty token after order was already paid', [
                    'source' => $source,
                    'order_id' => $reloadedOrder->getEntityId(),
                    'has_token' => $token !== '',
                ]);

                return [
                    'success' => true,
                    'already_processed' => true,
                    'order' => $reloadedOrder,
                ];
            }

            $message = __('IRIS payment failed. Please try another payment method.');
            $this->markOrderAsFailed($order, $message, $source);

            return [
                'success' => false,
                'error_message' => $message,
                'order' => $order,
            ];
        }

        $this->markOrderAsPaid($order, $paymentToken, $token, $source);

        return [
            'success' => true,
            'already_processed' => false,
            'order' => $order,
            'payment_token' => $paymentToken,
        ];
    }

    public function findOrderByIrisReference($token, $md, $allowCreateFromQuote = false)
    {
        if (!empty($token)) {
            try {
                $escapedToken = $this->escapeLikeValue($token);
                $orderCollection = $this->orderFactory->create()->getCollection()
                    ->join(
                        ['payment' => 'sales_order_payment'],
                        'main_table.entity_id = payment.parent_id',
                        []
                    )
                    ->addFieldToFilter('payment.additional_information', ['like' => '%"iris_token":"' . $escapedToken . '"%'])
                    ->setPageSize(1);

                if ($orderCollection->getSize() > 0) {
                    return $orderCollection->getFirstItem();
                }
            } catch (\Exception $e) {
                $this->logger->error('Error checking for existing orders by IRIS token: ' . $e->getMessage());
            }
        }

        if (strpos((string) $md, '_qid_') !== false) {
            $parts = explode('_qid_', $md);
            if (count($parts) === 2) {
                $quoteId = (int) $parts[1];

                try {
                    return $this->withQuoteOrderCreationLock($quoteId, function () use ($quoteId, $token, $md, $allowCreateFromQuote) {
                        $existingOrder = $this->findLatestOrderByQuoteId($quoteId);
                        if ($existingOrder && $existingOrder->getId()) {
                            return $existingOrder;
                        }

                        if (!empty($token) && $allowCreateFromQuote) {
                            return $this->createOrderFromQuote($quoteId, $token, $md, '');
                        }

                        $this->logger->warning('IRIS quote reference found without a verified payment token; skipping order creation', [
                            'quote_id' => $quoteId,
                            'token_present' => !empty($token),
                            'allow_create_from_quote' => (bool) $allowCreateFromQuote,
                        ]);

                        return null;
                    });
                } catch (\Exception $e) {
                    $this->logger->error('Error finding order by quote ID: ' . $e->getMessage());
                }
            }
        }

        $this->logger->info('No order found for IRIS notification', [
            'token' => $token,
            'md' => $md,
        ]);

        return null;
    }

    private function extractPayload(RequestInterface $request)
    {
        $requestPayload = $this->extractJsonPayload($request);
        $hash = (string) ($requestPayload['hash'] ?? $request->getParam('hash') ?? '');
        if (empty($hash)) {
            return [
                'success' => false,
                'error_message' => __('IRIS payment error. Missing hash.'),
            ];
        }

        $secretKey = $this->epConfig->getSecretKey();
        if (empty($secretKey)) {
            return [
                'success' => false,
                'error_message' => __('EveryPay is not configured properly. Please contact support.'),
            ];
        }

        $decoded = base64_decode($hash, true);
        if ($decoded === false || strpos($decoded, '|') === false) {
            return [
                'success' => false,
                'error_message' => __('IRIS payment error. Invalid hash payload.'),
            ];
        }

        list($providedHash, $payloadJson) = explode('|', $decoded, 2);
        $calculatedHash = hash_hmac('sha256', $payloadJson, $secretKey);
        if (!hash_equals($providedHash, $calculatedHash)) {
            return [
                'success' => false,
                'error_message' => __('IRIS payment error. Hash verification failed.'),
            ];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $token = (string) ($payload['token'] ?? $requestPayload['token'] ?? $request->getParam('token') ?? '');
        $md = (string) ($payload['md'] ?? $requestPayload['md'] ?? $request->getParam('md') ?? '');
        $errorStatus = (string) ($payload['error_status'] ?? $requestPayload['error_status'] ?? $request->getParam('error_status') ?? '');
        $errorMessage = (string) ($payload['error_message'] ?? $requestPayload['error_message'] ?? $request->getParam('error_message') ?? '');

        $this->logger->info('IRIS notification verified', [
            'md' => $md,
            'error_status' => $errorStatus,
            'has_token' => $token !== '',
            'has_error_message' => $errorMessage !== '',
        ]);

        return [
            'success' => true,
            'hash' => $hash,
            'token' => $token,
            'md' => $md,
            'error_message' => $errorMessage,
            'has_error' => !empty($errorStatus) || (!empty($errorMessage) && empty($token)),
        ];
    }

    private function extractJsonPayload(RequestInterface $request)
    {
        $rawBody = $this->getRawRequestBody($request);
        if ($rawBody === '') {
            return [];
        }

        $payload = json_decode($rawBody, true);

        return is_array($payload) ? $payload : [];
    }

    protected function getRawRequestBody(RequestInterface $request)
    {
        if (method_exists($request, 'getContent')) {
            return (string) $request->getContent();
        }

        $rawBody = file_get_contents('php://input');

        return is_string($rawBody) ? $rawBody : '';
    }

    private function applyIrisMetadata(Order $order, $token, $md, $hash)
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $payment->setMethod('everypay');
        $payment->setAdditionalInformation('payment_type', 'IRIS');
        $payment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');

        if (!empty($token)) {
            $payment->setAdditionalInformation('iris_token', $token);
            $order->setData('everypay_source_token', $token);
        }

        if (!empty($md)) {
            $payment->setAdditionalInformation('iris_md', $md);
            $order->setData('everypay_iris_md', $md);
        }

        if (!empty($hash)) {
            $payment->setAdditionalInformation('iris_hash', $hash);
        }
    }

    private function markOrderAsPaid(Order $order, $paymentToken, $sourceToken, $source)
    {
        $payment = $order->getPayment();
        $payment->setTransactionId($paymentToken);
        $payment->setLastTransId($paymentToken);
        $payment->setIsTransactionClosed(false);
        $payment->registerCaptureNotification($order->getGrandTotal());
        $payment->setAdditionalInformation('payment_type', 'IRIS');

        if (!empty($sourceToken)) {
            $payment->setAdditionalInformation('iris_token', $sourceToken);
        }

        $this->addCommentOnce($order, sprintf('EveryPay IRIS payment completed via %s.', $source));
        $this->orderRepository->save($order);
    }

    private function markOrderAsFailed(Order $order, $message, $source)
    {
        if (!in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            $order->setState(Order::STATE_CANCELED);
            $order->setStatus(Order::STATE_CANCELED);
        }

        $this->restoreQuoteForFailedOrder($order);
        $this->addCommentOnce($order, sprintf('EveryPay IRIS %s error: %s', $source, $message));
        $this->orderRepository->save($order);
    }

    private function restoreQuoteForFailedOrder(Order $order)
    {
        $quoteId = (int) $order->getQuoteId();
        if (!$quoteId) {
            return;
        }

        try {
            $quote = $this->quoteRepository->get($quoteId);
            if (!$quote || !$quote->getId()) {
                return;
            }

            $quote->setIsActive(true);
            $quote->setReservedOrderId(null);
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->warning('IRIS failed to restore quote for canceled order: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
                'quote_id' => $quoteId,
            ]);
        }
    }

    private function findLatestOrderByQuoteId($quoteId)
    {
        $orderCollection = $this->orderFactory->create()->getCollection()
            ->addFieldToFilter('quote_id', $quoteId)
            ->setOrder('created_at', 'DESC')
            ->setPageSize(1);

        if ($orderCollection->getSize() > 0) {
            return $orderCollection->getFirstItem();
        }

        return null;
    }

    private function withQuoteOrderCreationLock($quoteId, callable $callback)
    {
        $resource = $this->objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $connection = $resource->getConnection();
        $lockName = sprintf('everypay_iris_quote_%d', (int) $quoteId);
        $lockAcquired = false;

        try {
            $lockAcquired = (bool) $connection->fetchOne('SELECT GET_LOCK(?, 5)', array($lockName));
            if (!$lockAcquired) {
                $this->logger->warning('IRIS could not acquire quote creation lock', [
                    'quote_id' => $quoteId,
                ]);

                return $this->findLatestOrderByQuoteId($quoteId);
            }

            return call_user_func($callback);
        } catch (\Exception $e) {
            $this->logger->warning('IRIS quote creation lock failed: ' . $e->getMessage(), [
                'quote_id' => $quoteId,
            ]);

            return $this->findLatestOrderByQuoteId($quoteId);
        } finally {
            if ($lockAcquired) {
                try {
                    $connection->fetchOne('SELECT RELEASE_LOCK(?)', array($lockName));
                } catch (\Exception $e) {
                    $this->logger->warning('IRIS could not release quote creation lock: ' . $e->getMessage(), [
                        'quote_id' => $quoteId,
                    ]);
                }
            }
        }
    }

    private function addCommentOnce(Order $order, $comment)
    {
        foreach ($order->getStatusHistories() as $history) {
            if (trim((string) $history->getComment()) === trim($comment)) {
                return;
            }
        }

        $order->addCommentToStatusHistory($comment);
    }

    private function escapeLikeValue($value)
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            (string) $value
        );
    }

    private function createOrderFromQuote($quoteId, $token, $md, $hash)
    {
        try {
            $quote = $this->quoteRepository->get($quoteId);
            if (!$quote || !$quote->getId()) {
                return null;
            }

            $this->validateAndFixQuoteData($quote);

            $payment = $quote->getPayment();
            $payment->setMethod('everypay');
            $payment->setAdditionalInformation('payment_type', 'IRIS');
            $payment->setAdditionalInformation('method_title', 'Everypay IRIS Bank Payment');
            if ($md) {
                $payment->setAdditionalInformation('iris_md', $md);
            }
            if ($token) {
                $payment->setAdditionalInformation('iris_token', $token);
                $payment->setAdditionalInformation('token', $token);
            }
            if ($hash) {
                $payment->setAdditionalInformation('iris_hash', $hash);
            }

            try {
                $quote->setIsActive(true);
                $quote->collectTotals();
                $this->quoteRepository->save($quote);

                $orderId = $this->cartManagement->placeOrder($quoteId);
                if ($orderId) {
                    $order = $this->orderRepository->get($orderId);
                    $this->applyIrisMetadata($order, $token, $md, $hash);
                    $this->orderRepository->save($order);
                    return $order;
                }
            } catch (\Exception $e) {
                $this->logger->warning('CartManagement failed, using manual IRIS order creation: ' . $e->getMessage());
            }

            if (!$quote->getReservedOrderId()) {
                $quote->reserveOrderId();
            }

            $this->quoteRepository->save($quote);

            $order = $this->quoteManagement->submit($quote);
            if (!$order || !$order->getEntityId()) {
                throw new LocalizedException(__('IRIS quote submission failed.'));
            }

            $this->applyIrisMetadata($order, $token, $md, $hash);
            $this->orderRepository->save($order);

            return $order;
        } catch (\Exception $e) {
            $this->logger->error('IRIS: Failed to create order from quote', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function validateAndFixQuoteData($quote)
    {
        try {
            $billingAddress = $quote->getBillingAddress();

            if ($billingAddress) {
                if (!$quote->getCustomerEmail() && $billingAddress->getEmail() && filter_var($billingAddress->getEmail(), FILTER_VALIDATE_EMAIL)) {
                    $quote->setCustomerEmail($billingAddress->getEmail());
                }
                if (!$quote->getCustomerFirstname() && $billingAddress->getFirstname()) {
                    $quote->setCustomerFirstname($billingAddress->getFirstname());
                }
                if (!$quote->getCustomerLastname() && $billingAddress->getLastname()) {
                    $quote->setCustomerLastname($billingAddress->getLastname());
                }
            }

            if (!$quote->getCustomerEmail() || !filter_var($quote->getCustomerEmail(), FILTER_VALIDATE_EMAIL)) {
                throw new LocalizedException(__('IRIS callback: No valid customer email found in quote.'));
            }
            if (!$quote->getCustomerFirstname()) {
                throw new LocalizedException(__('IRIS callback: No customer firstname found in quote.'));
            }
            if (!$quote->getCustomerLastname()) {
                throw new LocalizedException(__('IRIS callback: No customer lastname found in quote.'));
            }

            if ($billingAddress) {
                if (!$billingAddress->getEmail()) {
                    $billingAddress->setEmail($quote->getCustomerEmail());
                }
                if (!$billingAddress->getFirstname()) {
                    $billingAddress->setFirstname($quote->getCustomerFirstname());
                }
                if (!$billingAddress->getLastname()) {
                    $billingAddress->setLastname($quote->getCustomerLastname());
                }
            }

            $shippingAddress = $quote->getShippingAddress();
            if ($shippingAddress && !$quote->isVirtual() && $billingAddress) {
                if (!$shippingAddress->getEmail()) {
                    $shippingAddress->setEmail($quote->getCustomerEmail());
                }
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
            throw $e;
        }
    }

    private function createIrisPayment(Order $order, $token)
    {
        try {
            $params = [
                'amount' => (int) round($order->getGrandTotal() * 100),
                'description' => $order->getStore()->getName() . ' / Order #' . $order->getIncrementId(),
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
            Everypay::$isTest = (bool) $this->epConfig->getSandboxMode();

            $response = Payment::create($params);

            $this->logger->debug('IRIS Payment Request', [
                'params' => $this->getSafeIrisPaymentRequestLog($params),
            ]);
            $this->logger->debug('IRIS Payment Response', [
                'response' => $this->getSafeIrisPaymentResponseLog($response),
            ]);

            if (isset($response->error)) {
                throw new \Exception($response->error->message ?? 'Payment creation failed');
            }

            return $response->token ?? null;
        } catch (\Exception $e) {
            $this->logger->error('IRIS payment creation error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function reloadOrder(Order $order)
    {
        try {
            return $this->orderRepository->get($order->getEntityId());
        } catch (\Exception $e) {
            $this->logger->warning('IRIS failed to reload order during idempotency check: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
            ]);
        }

        return null;
    }

    private function isSuccessfulOrder(Order $order)
    {
        return in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true);
    }

    private function isIdempotentPaidOrder(Order $order, $token)
    {
        if (!$this->isSuccessfulOrder($order)) {
            return false;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        $existingToken = (string) $payment->getAdditionalInformation('iris_token');

        return !$token || !$existingToken || $existingToken === $token;
    }

    private function getSafeIrisPaymentRequestLog(array $params)
    {
        return [
            'amount' => $params['amount'] ?? null,
            'description' => $params['description'] ?? null,
            'currency' => $params['currency'] ?? null,
            'country' => $params['country'] ?? null,
            'token' => $this->maskSensitiveValue($params['token'] ?? null),
            'payee_email' => $this->maskEmailValue($params['payee_email'] ?? null),
            'payee_phone' => $this->maskSensitiveValue($params['payee_phone'] ?? null),
        ];
    }

    private function getSafeIrisPaymentResponseLog($response)
    {
        return [
            'status' => $response->status ?? null,
            'payment_state' => $response->payment_state ?? null,
            'token' => $this->maskSensitiveValue($response->token ?? null),
            'error' => isset($response->error) ? [
                'code' => $response->error->code ?? null,
                'message' => $response->error->message ?? null,
            ] : null,
        ];
    }

    private function maskSensitiveValue($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (strlen($value) <= 10) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 6) . '***' . substr($value, -4);
    }

    private function maskEmailValue($value)
    {
        if (!is_string($value) || $value === '' || strpos($value, '@') === false) {
            return $this->maskSensitiveValue($value);
        }

        return preg_replace('/(^.).*(@.*$)/', '$1***$2', $value);
    }
}
