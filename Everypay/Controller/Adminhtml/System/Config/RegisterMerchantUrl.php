<?php
namespace Everypay\Everypay\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Everypay\Everypay\Model\Ui\EverypayConfig;

class RegisterMerchantUrl extends Action
{
    protected $resultJsonFactory;
    protected $curl;
    protected $epConfig;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Curl $curl,
        EverypayConfig $epConfig,
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->curl = $curl;
        $this->epConfig = $epConfig;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        
        try {
            $merchantUrl = $this->epConfig->getApplePayMerchantUrl();
            $merchantDomain = parse_url($merchantUrl, PHP_URL_HOST);

            if (empty($merchantUrl) || empty($merchantDomain)) {
                return $result->setData([
                    'success' => false,
                    'message' => 'Invalid Merchant URL'
                ]);
            }

            $server = $this->epConfig->getSandboxMode()
                ? 'sandbox-api.everypay.gr'
                : 'api.everypay.gr';
            $apiUrl = "https://{$server}/applepay/domains";

            $this->curl->setCredentials($this->epConfig->getSecretKey(), '');
            $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            $postData = http_build_query([
                'domain_names' => [$merchantDomain]
            ]);

            $this->curl->post($apiUrl, $postData);
            
            $httpCode = $this->curl->getStatus();
            $response = $this->curl->getBody();
            
            if ($httpCode >= 200 && $httpCode <= 299) {
                return $result->setData([
                    'success' => true,
                    'message' => 'Merchant domain registered successfully!',
                    'response' => $response
                ]);
            }

            $respData = json_decode($response, true);

            $message = [
                'API call failed.',
                'HTTP Status: ' . $httpCode,
                'Error code: ' . ($respData['error']['code'] ?? 'N/A'),
                'Message: ' . ($respData['error']['message'] ?? 'N/A')
            ];
            
            return $result->setData([
                'success' => false,
                'message' => implode(' | ', $message)
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Payment::payment');
    }
}
