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
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/totals',
        'Payform',
        'EverypayModal',
    ],
    function ($, ko, Component, quote, totals, Payform) {
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
                emptyVault: false,
                maxInstallments: ''
            },

            initialize: function () {
                this._super();
                this.setBillingAddress();
                this.EverypayModal = new EverypayModal();
                this.preparePayform();
                return this;
            },

            preparePayform: function () {
                let installments  = this.getInstallments();
                this.amount = this.getTotal().total;
                let payload = Payform.createPayload(this.amount, this.billingAddress, installments);

                Payform.preload(payload, this.EverypayModal, installments);

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
                        'emptyVault',
                        'maxInstallments'

                    ]);
                return this;
            },

            setBillingAddress: function () {
               let billingData = quote.billingAddress();

               if (billingData.street[0])
                   this.billingAddress = billingData.street[0];
               else
                   this.billingAddress = '';
            },

            getCode: function() {
                return 'everypay';
            },

            getTotals: function() {
                return totals.totals();
            },

            getTotal: function() {
                var data = {
                    'total' : this.getTotals().base_grand_total*100
                }
                return data;
            },


            getInstallments: function(){
                let max_installments = 0;
                let total = this.getTotals().grand_total;
                let plan = window.checkoutConfig.payment.everypay.installments;

                if (plan.length>0){
                    $.each(plan, function(i,v){
                        if (parseFloat(v[1])>=total){
                            max_installments = parseInt(v[0]);
                            return false;
                        }
                    })
                }

                let payform_installments = [];

                if ( max_installments > 0){

                    window.checkoutConfig.payment.everypay.maxInstallments = max_installments;
                    let y = 2;
                    for (let i = 2; i <= max_installments; i += y) {
                        if (i >= 12)
                            y = 12;

                        payform_installments.push(i);
                    }

                    return {
                        max_installments: max_installments,
                        payform: payform_installments
                    };
                }

                return false;
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
                        empty_vault: window.checkoutConfig.payment.everypay.emptyVault,
                        max_installments: window.checkoutConfig.payment.everypay.maxInstallments
                    }
                };
                data['additional_data'] = _.extend(data['additional_data'], this.additionalData);

                return data;
            },

            getFixedAmount: function() {
                return window.checkoutConfig.payment.everypay.fixedamount;
            },

            clickEverypayButton: function(){

                if ($("input[name='card']:checked").val()){
                    $('#epPlaceOrder').click();
                }else{
                    this.EverypayModal.open();
                }

            },
    });
    }
);
