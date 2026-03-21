let isSandboxMode = window.checkoutConfig.payment.everypay.sandboxMode;
let everypayUrl = 'https://js.everypay.gr/v3';
let iframeSource = "Magento 2 CMS";

if (isSandboxMode && isSandboxMode == 1) {
    console.log('Everypay sandbox mode enabled');
    everypayUrl = 'https://sandbox-js.everypay.gr/v3';
}

define([
    'EverypayHelpers',
    everypayUrl
], function(Helpers){

    return {

        load: (payload, modal, onLoadCallback) => {
            everypay.payform(payload, (response) => {
                let everypayModal = modal;

                if (onLoadCallback && response.onLoad) {
                    onLoadCallback(modal);
                }

                if (response.response == 'success') {
                    everypayModal.destroy();
                    checkoutConfig.payment.everypay.token = response.token;
                    document.getElementById('epPlaceOrder').click();
                }

                if (response.response == 'error' && response.error) {
                    console.error('Everypay payment error:', response.error);
                    
                    setTimeout(function () {
                        if (everypayModal) {
                            everypayModal.destroy();
                        }
                        
                        // Show error message
                        var errorMessage = response.error || 'Payment failed. Please try another payment method.';
                        var messageContainer = document.querySelector('.message.message-error.error');
                        if (!messageContainer) {
                            var checkoutPage = document.querySelector('.checkout-container');
                            if (checkoutPage) {
                                var errorDiv = document.createElement('div');
                                errorDiv.className = 'message message-error error';
                                errorDiv.innerHTML = '<div>' + errorMessage + '</div>';
                                checkoutPage.insertBefore(errorDiv, checkoutPage.firstChild);
                            }
                        }
                    }, 1000);
                }

            });
        },

        createPayload: (amount, installments, billingData, shippingData, otherPaymentMethods) => {
            let payload = {
                amount: amount,
                pk:  window.checkoutConfig.payment.everypay.publicKey,
                locale: window.checkoutConfig.payment.everypay.locale,
                iframeSource: iframeSource,
                data: {
                    billing: {
                        addressLine1: billingData.address,
                        postalCode: billingData.postalCode,
                        country: billingData.country,
                        city: billingData.city
                    },
                    phone: shippingData.phone,
                    email: shippingData.email,
                }
            };

            if (installments.payform) {
                payload.installments = installments.payform;
            }

            if (otherPaymentMethods) {
                // Add IRIS if enabled
                if (window.checkoutConfig.payment.everypay.isIrisEnabled && window.checkoutConfig.payment.everypay.iris) {
                    let irisConfig = window.checkoutConfig.payment.everypay.iris;

                    // Generate a unique md reference for this transaction
                    let md = 'magento_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15);
                    let callbackUrl = window.location.origin + '/everypay/iris/callback?md=' + encodeURIComponent(md);
                    
                    let iris = {
                        merchantName: irisConfig.merchantName,
                        country: irisConfig.country,
                        callbackUrl: callbackUrl,
                        md: md,
                    };

                    // Create IRIS session handler
                    Object.defineProperty(iris, 'sessionHandler', {
                        value: Helpers.createIrisSessionHandler({
                            ajaxUrl: window.location.origin + '/everypay/iris/createsession',
                            amount: amount,
                            currency: billingData.currency || 'EUR',
                            country: irisConfig.country,
                            md: md
                        }),
                        enumerable: false,
                        configurable: true,
                        writable: true
                    });

                    otherPaymentMethods = { ...otherPaymentMethods, iris: iris };
                }

                payload.otherPaymentMethods = otherPaymentMethods;
            }

            return payload;
        },

        tokenize: (payload, modal) => {

            let everypayModal = modal;

            everypay.tokenized(payload, (r) => {
                if (r.onLoad)
                    everypayModal.open();

                if (r.response == 'success') {
                    everypayModal.destroy();
                    checkoutConfig.payment.everypay.token = r.token;
                    document.getElementById('epPlaceOrder').click();
                }
            });

        },

        createTokenizationPayload: function (amount, installments, billingData, shippingData) {

            let cardDetails = Helpers.extractCardDetailsFromWindow();

            if (!cardDetails)
                cardDetails = Helpers.extractCardDetailsFromName();

            let payload = {
                pk: window.checkoutConfig.payment.everypay.publicKey,
                amount: amount,
                iframeSource: iframeSource,
                data: {
                    customerToken: cardDetails.customerToken,
                    cardType: cardDetails.cardType,
                    cardExpMonth: cardDetails.cardExpMonth,
                    cardExpYear: cardDetails.cardExpYear,
                    cardLastFour: cardDetails.cardLastFour,
                    cardHolderName: '',
                    billing: {
                        addressLine1: billingData.address,
                        postalCode: billingData.postalCode,
                        country: billingData.country,
                        city: billingData.city
                    },
                    phone: shippingData.phone,
                    email: shippingData.email,
                },
                display: {
                    cvvInput: false
                }
            };

            if (installments.payform)
                payload.installments = installments.payform;

            return payload;
        }

    }
});
