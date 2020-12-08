define([
    'EverypayHelpers',
    'https://sandbox-js.everypay.gr/v3'
], function(Helpers){

    return {

        preload: (payload, modal) => {
            everypay.payform(payload, (response) => {
                let everypayModal = modal;

                if (response.response == 'success') {
                    everypayModal.destroy();
                    checkoutConfig.payment.everypay.token = response.token;
                    document.getElementById('epPlaceOrder').click();
                }

            });
        },

        createPayload: (amount, billingAddress, installments) => {
           let payload = {
                amount: amount,
                pk:  window.checkoutConfig.payment.everypay.publicKey,
                locale: window.checkoutConfig.payment.everypay.locale,
                data: {
                    billing: {
                        addressLine1: billingAddress,
                    },
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

        createTokenizationPayload: function (billingAddress, amount, installments) {

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
                    billing: { addressLine1: billingAddress }
                }
            };

            if (installments.payform)
                payload.installments = installments.payform;

            return payload;
        }



}
});
