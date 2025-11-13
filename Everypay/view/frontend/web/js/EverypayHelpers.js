define([], function(){
    return {

        extractCardDetailsFromName: function () {
            try {
                let savedCard = document.querySelector('input[name="card"]:checked');

                var cardName = savedCard.getAttribute('cardname');

                var cardDetails = {
                    customerToken: savedCard.value,
                    cardType: cardName.match(/\w+.+?/)[0].toLowerCase(),
                    cardLastFour: cardName.match(/\s[0-9]{4}\s/)[0].trim(),
                    cardExpMonth: cardName.match(/\(([0-9]{2})\//)[1],
                    cardExpYear: cardName.match(/\/([0-9]{4})\)/)[1]
                }

            } catch (error) {
                return false;
            }

            return cardDetails;
        },

        extractCardDetailsFromWindow: function () {
            var cardDetails = {};

            let savedCard = document.querySelector('input[name="card"]:checked');

            try {
                var cardName = savedCard.getAttribute('cardname');

                var cards = window.checkoutConfig.payment.everypay.customerCards;

                cards.forEach(function (card, index) {

                    if (card.name != cardName)
                        return;

                    if (!card.cardExpirationMonth || !card.cardExpirationYear) {
                        cardDetails = false;
                        return;
                    }

                    cardDetails.customerToken = card.custToken;
                    cardDetails.cardType = card.cardType;
                    cardDetails.cardLastFour = card.cardLastFourDigits;
                    cardDetails.cardExpMonth = card.cardExpirationMonth;
                    cardDetails.cardExpYear = card.cardExpirationYear;

                });

            } catch (error) {
                return false;
            }

            return cardDetails;
        },

        createIrisSessionHandler: function (irisConfig) {
            return async function (sessionPayload) {
                var params = new URLSearchParams();

                if (sessionPayload && sessionPayload.uuid) {
                    params.append('uuid', sessionPayload.uuid);
                }
                if (sessionPayload && sessionPayload.md) {
                    params.append('md', sessionPayload.md);
                }

                if (irisConfig.amount) {
                    params.append('amount', irisConfig.amount);
                }

                if (irisConfig.currency) {
                    params.append('currency', irisConfig.currency);
                }

                if (irisConfig.md) {
                    params.append('md', irisConfig.md);
                }

                if (irisConfig.country) {
                    params.append('country', irisConfig.country);
                }

                try {
                    var response = await fetch(irisConfig.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: params.toString()
                    });

                    var json = await response.json();

                    if (!json || !json.success || !json.signature) {
                        var message = (json && json.message) ? json.message : 'Invalid IRIS session response';
                        throw new Error(message);
                    }

                    return json.signature;
                } catch (error) {
                    console.error('IRIS session creation failed', error);
                    throw error;
                }
            };
        }

    }
});
