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


    }
});
