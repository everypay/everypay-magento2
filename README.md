# EveryPay Magento 2 payment gateway module 


## Installation Instructions

1. Download zip, extract it and rename the extracted folder to Everypay. 

2. Move folder to app/code folder (create it if it does not exist)

3. From magento document root run : bin/magento setup:upgrade

4. From magento document root run : composer require "everypay/everypay-php":"@stable"



## Configuration Instructions

**1. Title**

Set the title you wish to be displayed at the Payment Selection step of checkout

**2. Merchant Secret & Public Keys**  

You can find your keys in EveryPay's Dashboard at Settings > Api Keys section<br>
(be careful to use the correct keys when using Sandbox Mode and vice versa)
 
**3. Sandbox Mode**

Enable or disable Sandbox Mode for testing pursposes<br>
(be careful to use the correct keys when using Sandbox Mode)

**4. Installments Plan**

If your account supports Installments then you can create your plan according to the following pattern.

number_of_installments;up_to_amount,number_of_installments;up_to_amount,...,max_number_of_installments;999999

**NOTICE:**
 1. the values of each group are separated with a semicolon (;) and each group is separated with a comma (,)  
 
 2. the max_number_of_installments is based on your EveryPay Account setup
 
 3. The last entry (max_number_of_installments;999999) is required as the closing group of your installments plan.
