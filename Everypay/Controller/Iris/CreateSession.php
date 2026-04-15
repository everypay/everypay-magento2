<?php
/**
 * Copyright © 2025 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Everypay\Everypay\Controller\Iris;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Everypay\Everypay\Model\Ui\EverypayConfig;
use Everypay\Everypay;
use Psr\Log\LoggerInterface;

class CreateSession extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var EverypayConfig
     */
    protected $epConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param CheckoutSession $checkoutSession
     * @param EverypayConfig $epConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        EverypayConfig $epConfig,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultFactory = $context->getResultFactory();
        $this->checkoutSession = $checkoutSession;
        $this->epConfig = $epConfig;
        $this->logger = $logger;
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
     * Execute IRIS session creation
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        // CRITICAL: Set up SameSite=None cookies BEFORE redirecting to payment gateway
        $this->setupSecureCookies();

        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            if (!$this->epConfig->getIsIrisEnabled()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('IRIS is not enabled.')
                ]);
            }

            $request = $this->getRequest();
            $uuid = $request->getParam('uuid');
            $md = $request->getParam('md');
            $amount = $request->getParam('amount');
            $currency = $request->getParam('currency');
            $country = $request->getParam('country');

            // Get quote from checkout session
            $quote = $this->checkoutSession->getQuote();

            if (!$quote || !$quote->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Unable to retrieve quote.')
                ]);
            }

            // Capture and persist customer data before redirect
            // This prevents data loss when IRIS callback URL returns
            $this->captureAndPersistCustomerData($quote, $request);

            // If amount not provided, use quote total
            if (empty($amount)) {
                $amount = (int)($quote->getGrandTotal() * 100);
            }

            if (empty($currency)) {
                $currency = $quote->getQuoteCurrencyCode();
            }

            if (empty($country)) {
                $country = $this->epConfig->getIrisCountry();
                if (empty($country)) {
                    $country = 'GR';
                }
            }

            $callbackUrl = $this->_url->getUrl('everypay/iris/callback', ['_secure' => true]);
            $webhookUrl = $this->_url->getUrl('everypay/iris/webhook', ['_secure' => true]);
            $callbackQuery = [];

            $params = [
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'country' => strtoupper($country),
                'callback_url' => $callbackUrl,
                'webhook_url' => $webhookUrl,
            ];

            // Store md reference and quote ID for callback lookup
            if (!empty($md)) {
                $params['md'] = $md;
                // Encode quote ID in md for callback lookup
                $mdWithQuote = $md . '_qid_' . $quote->getId();
                $params['md'] = $mdWithQuote;
                $quote->setData('everypay_iris_md', $mdWithQuote);
                $this->checkoutSession->setData('everypay_iris_last_md', $mdWithQuote);
                $this->checkoutSession->setData('everypay_iris_last_quote_id', $quote->getId());
                $callbackQuery['md'] = $mdWithQuote;
            }

            if (!empty($callbackQuery)) {
                $params['callback_url'] = $this->_url->getUrl('everypay/iris/callback', [
                    '_secure' => true,
                    '_query' => $callbackQuery,
                ]);
            }

            if (!empty($uuid)) {
                $params['uuid'] = $uuid;
            }

            // Configure Everypay SDK
            $secretKey = $this->epConfig->getSecretKey();
            if (empty($secretKey)) {
                return $result->setData([
                    'success' => false,
                    'message' => __('IRIS session failed: secret key is missing.')
                ]);
            }

            Everypay::setApiKey($secretKey);
            Everypay::$isTest = (bool)$this->epConfig->getSandboxMode();

            // Make API request to create IRIS session
            $response = $this->createIrisSession($params);

            $this->logger->debug('IRIS Session Request', ['params' => $this->getSafeSessionRequestLog($params)]);
            $this->logger->debug('IRIS Session Response', ['response' => $this->getSafeSessionResponseLog($response)]);

            if (!isset($response['signature'])) {
                $message = isset($response['error']['message'])
                    ? $response['error']['message']
                    : __('IRIS session failed.');

                return $result->setData([
                    'success' => false,
                    'message' => $message
                ]);
            }

            // Save the quote with IRIS data
            $quote->save();

            return $result->setData([
                'success' => true,
                'signature' => $response['signature'],
                'uuid' => $response['uuid'] ?? ($params['uuid'] ?? '')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('IRIS session error: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('IRIS session error: %1', $e->getMessage())
            ]);
        }
    }

    /**
     * Create IRIS session via API
     *
     * @param array $params
     * @return array
     */
    protected function createIrisSession($params)
    {
        $apiUrl = Everypay::$isTest
            ? 'https://sandbox-api.everypay.gr/iris/sessions'
            : 'https://api.everypay.gr/iris/sessions';

        $secretKey = $this->epConfig->getSecretKey();

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)) {
            return $decoded;
        }

        return [
            'error' => [
                'message' => isset($decoded['error']['message'])
                    ? $decoded['error']['message']
                    : 'IRIS session creation failed'
            ]
        ];
    }

    /**
     * Set up SameSite=None cookies for payment gateway compatibility
     * Called BEFORE redirecting to payment gateway to ensure cookies work on return
     */
    protected function setupSecureCookies()
    {
        try {
            if ($this->isHttpsRequest()) {

                // Force update the existing session cookie with SameSite=None manually
                // Since session is already active, we can't use session_set_cookie_params()
                $cookieName = session_name();
                $sessionId = session_id();

                if ($sessionId) {
                    // Get current cookie parameters
                    $params = session_get_cookie_params();

                    // Force set the cookie with SameSite=None;Secure
                    setcookie(
                        $cookieName,
                        $sessionId,
                        [
                            'expires' => time() + ($params['lifetime'] ?: 3600),
                            'path' => $params['path'] ?: '/',
                            'domain' => $params['domain'] ?: '',
                            'secure' => true,
                            'httponly' => $params['httponly'] !== false,
                            'samesite' => 'None'
                        ]
                    );

                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('PAYMENT GATEWAY SETUP: Cookie setting failed: ' . $e->getMessage());
        }
    }

    /**
     * Detect if request is over HTTPS (including proxy scenarios)
     */
    protected function isHttpsRequest()
    {
        // Check direct HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Check proxy headers (for Docker/load balancer scenarios)
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }

        // Check port 443
        if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443') {
            return true;
        }

        return false;
    }

    /**
     * Capture customer data from request and persist to quote
     * This prevents data loss during IRIS payment redirection flow with guests
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Magento\Framework\App\RequestInterface $request
     * @return void
     */
    protected function captureAndPersistCustomerData($quote, $request)
    {
        try {
            $this->logger->info('Forcing quote data persistence before IRIS redirect', [
                'quote_id' => $quote->getId(),
                'current_email' => $this->maskEmailValue($quote->getCustomerEmail()),
                'current_firstname' => $quote->getCustomerFirstname(),
                'current_lastname' => $quote->getCustomerLastname(),
                'is_guest' => $quote->getCustomerIsGuest()
            ]);

            // Ensure guest checkout flag is set for non-logged users
            if (!$quote->getCustomerId() && !$quote->getCustomerIsGuest()) {
                $quote->setCustomerIsGuest(true);
                $this->logger->info('Set guest checkout flag');
            }

            // Force collect totals and save quote
            $quote->collectTotals();
            $quote->setDataChanges(true);
            $quote->save();

            $this->logger->info('Quote data persisted successfully', [
                'quote_id' => $quote->getId(),
                'email' => $this->maskEmailValue($quote->getCustomerEmail()),
                'firstname' => $quote->getCustomerFirstname(),
                'lastname' => $quote->getCustomerLastname(),
                'billing_email' => $quote->getBillingAddress() ? $this->maskEmailValue($quote->getBillingAddress()->getEmail()) : null,
                'billing_firstname' => $quote->getBillingAddress() ? $quote->getBillingAddress()->getFirstname() : null,
                'billing_lastname' => $quote->getBillingAddress() ? $quote->getBillingAddress()->getLastname() : null
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to persist quote data: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    private function getSafeSessionRequestLog(array $params): array
    {
        return [
            'amount' => $params['amount'] ?? null,
            'currency' => $params['currency'] ?? null,
            'country' => $params['country'] ?? null,
            'callback_url' => $params['callback_url'] ?? null,
            'webhook_url' => $params['webhook_url'] ?? null,
            'md' => $params['md'] ?? null,
            'uuid' => $params['uuid'] ?? null,
        ];
    }

    private function getSafeSessionResponseLog(array $response): array
    {
        return [
            'signature' => $this->maskSensitiveValue($response['signature'] ?? null),
            'uuid' => $response['uuid'] ?? null,
            'md' => $response['md'] ?? null,
            'date_created' => $response['date_created'] ?? null,
            'expiration_date' => $response['expiration_date'] ?? null,
            'amount' => $response['amount'] ?? null,
            'status' => $response['status'] ?? null,
            'token' => $this->maskSensitiveValue($response['token'] ?? null),
            'error' => isset($response['error']) ? [
                'message' => $response['error']['message'] ?? null,
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
