<?php
/**
 * Created by PhpStorm.
 * User: sp
 * Date: 8/1/2019
 * Time: 11:28
 */

namespace Everypay\Everypay\Setup;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Eav\Model\Config;
use Magento\Customer\Model\Customer;

class InstallData implements InstallDataInterface
{
    private $eavSetupFactory;
    private $eavConfig;

    public function __construct(EavSetupFactory $eavSetupFactory, Config $eavConfig)
    {
        $this->eavSetupFactory = $eavSetupFactory;
        $this->eavConfig       = $eavConfig;
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

        $eavSetup->addAttribute(Customer::ENTITY, 'everypay_vault', [
            'type' => 'text',
            'label' => 'Everypay Vault',
            'input' => 'textarea',
            'source' => '',
            'required' => false,
            'visible' => true,
            'position' => 0,
            'backend' => '',
            'is_used_in_grid' => 1,
            'is_visible_in_grid' => 1,
            'system' => 0

        ]);

        $attribute = $this->eavConfig->getAttribute(Customer::ENTITY, 'everypay_vault');
        $attribute->setData('used_in_forms',[
                'adminhtml_customer',
                'customer_account_create',
                'customer_account_edit'
            ]
            );
        $attribute->save();
    }
}