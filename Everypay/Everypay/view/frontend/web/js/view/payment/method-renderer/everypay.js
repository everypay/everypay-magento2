/**
 * Copyright © 2016 Everypay. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Everypay_Everypay/js/everypay',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/totals'
    ],
    function ($, ko, Component, quote, totals) {
        'use strict';
        var savedCards = ko.observableArray([]);



        self.setVault = function(){
            var vault_raw = $("input[name='card']:checked").val();
            var vault_tokens = vault_raw.split(';');
            window.checkoutConfig.payment.everypay.customerToken = vault_tokens[0];
            window.checkoutConfig.payment.everypay.cardToken = vault_tokens[1];
            $('#everypay-save-card').attr('checked',false);
            window.checkoutConfig.payment.everypay.saveCard = false;
            $('#everypay-save-card-container').css('display','none');
            return true;
        };



        return Component.extend({
            defaults: {
                template: 'Everypay_Everypay/payment/form',
                transactionResult: '',
                token: '',
                saveCard: false,
                customerToken: '',
                cardToken: '',
                everypayVault: '',
                savedCards: [],
                removedCards: '',
                emptyVault: false
            },

            initObservable: function () {

                this._super()
                    .observe([
                        'transactionResult',
                        'token',
                        'saveCard',
                        'customerToken',
                        'cardToken',
                        'everypayVault',
                        'savedCards',
                        'removedCards',
                        'emptyVault'

                    ]);
                return this;
            },

            getCode: function() {
                return 'everypay';
            },

            getTotals: function() {
                return totals.totals();
            },

            getTotal: function() {
                var data = {
                    'total' : this.getTotals().grand_total*100
                }
                return data;
            },


            getInstallments: function(){
                var installments=0;
                var total = this.getTotals().grand_total;
                var plan = window.checkoutConfig.payment.everypay.installments;

                if (plan.length>0){
                    $.each(plan, function(i,v){
                        if (parseFloat(v[1])>=total){
                            installments=parseInt(v[0]);
                            return false;

                        }
                    })
                }
                return installments;

            },

            getTransactionResults: function() {
                return _.map(window.checkoutConfig.payment.everypay.transactionResults, function(value, key) {
                    return {
                        'value': key,
                        'transaction_result': value
                    }
                });
            },

            everypayVaultExists: function() {
                if (window.checkoutConfig.isCustomerLoggedIn){

                    var vault_exists = false;
                    if (typeof(window.checkoutConfig.customerData.custom_attributes) !== 'undefined'){
                        vault_exists = true;
                    }

                    if (vault_exists) {
                        var cards = window.checkoutConfig.customerData.custom_attributes.everypay_vault.value;
                        if (cards.length > 0) {
                            return true;
                        } else {
                            return false;
                        }
                    }

                }else{

                    return false;

                }
            },

            customerLoggedIn: function() {
              return window.checkoutConfig.isCustomerLoggedIn;
            },

            clearRadios: function() {
              $('.everypay-vault-radio').attr('checked', false);
                window.checkoutConfig.payment.everypay.customerToken = '';
                window.checkoutConfig.payment.everypay.cardToken = '';
                $('#everypay-save-card-container').css('display','block');
                return true;
            },

            getSavedCards: function() {

                window.checkoutConfig.payment.everypay.removedCards = '';

                if (this.everypayVaultExists()) {
                    var cards = window.checkoutConfig.customerData.custom_attributes.everypay_vault.value;
                    var jsonCards = JSON.parse(cards);
                    savedCards(jsonCards.cards);
                    window.checkoutConfig.payment.everypay.everypayVault = savedCards;
                    return savedCards;
                }else{
                    savedCards(JSON.parse('[]'));
                    window.checkoutConfig.payment.everypay.everypayVault = savedCards;
                    return savedCards;
                }

            },

            removeCard: function() {
                if(confirm("Are you sure you want to remove this stored card?")){

                    $('.everypay-vault-radio').attr('checked', false);
                    window.checkoutConfig.payment.everypay.customerToken = '';
                    window.checkoutConfig.payment.everypay.cardToken = '';
                    $('#everypay-save-card-container').css('display','block');

                    var iremove =savedCards.indexOf(this);
                    var rcard = savedCards()[iremove];
                    var xremovedCards = window.checkoutConfig.payment.everypay.removedCards;
                    xremovedCards = xremovedCards + "," + rcard['custToken']+";"+rcard['crdToken'];
                    window.checkoutConfig.payment.everypay.removedCards = xremovedCards;

                    savedCards.remove(this);
                    if (savedCards().length > 0){
                        window.checkoutConfig.payment.everypay.everypayVault = savedCards;
                    }else{
                        $('#everypay-new-card').css('display','none');
                        window.checkoutConfig.payment.everypay.everypayVault = savedCards;
                        window.checkoutConfig.payment.everypay.emptyVault = true;
                    }
                    return true;
                }
            },

            setSaveCard: function() {
                window.checkoutConfig.payment.everypay.saveCard = $('#everypay-save-card').is(":checked");
            },


            vaultNotSelected: function() {
                if (!$("input[name='card']:checked").val()) {
                    return true;
                }else{
                    return false;
                }

            },

            getData: function() {
                var xvault = '';
                if (window.checkoutConfig.payment.everypay.everypayVault == null){
                    xvault = '{}';
                }else{
                    xvault = JSON.stringify(window.checkoutConfig.payment.everypay.everypayVault());
                }
                var data = {
                    'method': this.getCode(),
                    'additional_data': {
                        transaction_result: this.transactionResult(),
                        token: window.checkoutConfig.payment.everypay.token,
                        save_card: window.checkoutConfig.payment.everypay.saveCard,
                        customer_token: window.checkoutConfig.payment.everypay.customerToken,
                        card_token: window.checkoutConfig.payment.everypay.cardToken,
                        everypay_vault: xvault,
                        removed_cards: window.checkoutConfig.payment.everypay.removedCards,
                        empty_vault: window.checkoutConfig.payment.everypay.emptyVault
                    }
                };
                data['additional_data'] = _.extend(data['additional_data'], this.additionalData);

                return data;
            },

            getFixedAmount: function() {
                return window.checkoutConfig.payment.everypay.fixedamount;
            },


            makeButton: function(){
                var xdata = this.getTotal();
                var installments = this.getInstallments();
                    var EVERYPAY_DATA = {
                        amount: xdata.total,
                        key: window.checkoutConfig.payment.everypay.publicKey,
                        locale: window.checkoutConfig.payment.everypay.locale,
                        sandbox: window.checkoutConfig.payment.everypay.sandboxMode,
                        max_installments: installments,
                        callback: 'epcallback'
                    }
                    var loadButton = setInterval(function () {
                        try {
                            EverypayButton.jsonInit(EVERYPAY_DATA, $("#everypay-payment-form"));
                            clearInterval(loadButton);
                        } catch (err) { console.log(err) }
                        $('.everypay-button').click();
                    }, 100);
            },

            clickEverypayButton: function(){
                if ($("input[name='card']:checked").val()){
                    $('#epPlaceOrder').click();
                }else{
                    this.makeButton();
                }

            },
    });
    }
);