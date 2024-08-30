let isSandboxMode = window.checkoutConfig.payment.everypay.sandboxMode;
let everypayUrl = 'https://js.everypay.gr/v3';

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

            });
        },

        createPayload: (amount, installments, billingData, shippingData) => {
            let payload = {
                amount: amount,
                pk:  window.checkoutConfig.payment.everypay.publicKey,
                locale: window.checkoutConfig.payment.everypay.locale,
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

            if (installments.payform)
                payload.installments = installments.payform;

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
