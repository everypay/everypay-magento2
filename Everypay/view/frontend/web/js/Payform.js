define([
    'https://sandbox-js.everypay.gr/v3'
], function(){
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
           return payload;
        },



}
});
