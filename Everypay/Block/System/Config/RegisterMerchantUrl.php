<?php
namespace Everypay\Everypay\Block\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class RegisterMerchantUrl extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Everypay_Everypay::system/config/register_merchant_url.phtml';

    /**
     * Render button
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        // Remove scope info
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Get the button and scripts contents
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->addData([
            'button_label' => __('Register Apple Pay Merchant URL'),
            'html_id' => $element->getHtmlId(),
            'ajax_url' => $this->_urlBuilder->getUrl('everypay/system_config/registermerchanturl'),
        ]);

        return $this->_toHtml();
    }
}
