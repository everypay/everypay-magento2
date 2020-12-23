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

        $.migrateMute = true;

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
                this.setBillingData();
                this.setPayload();
                this.EverypayModal = new EverypayModal();
                this.preparePayform();
                return this;
            },

            setPayload: function () {
                let installments  = this.getInstallments();
                this.amount = this.getTotal().total;
                this.payload = Payform.createPayload(this.amount, installments, this.billingData);
            },

            payWithSavedCard: function () {
                this.EverypayTokenizationModal = new EverypayModal();
                let installments  = this.getInstallments();
                let amount = this.getTotal().total;
                let payload = Payform.createTokenizationPayload(amount, installments, this.billingData);
                Payform.tokenize(payload, this.EverypayTokenizationModal);
            },

            enableLoadingScreen: function () {
                var img_src = 'data:image/gif;base64,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';
                $('body').prepend(`<div class="loader-everypay" style="position: fixed;height: 100%;width: 100%;background: #f2f2f2;z-index: 100000;top: 0;left: 0;opacity: 0.93;">\n\
                <center style="width: 100%;position: fixed;clear: both;font-size: 1.3em;top: 40%;margin: 0 auto;">Επεξεργασία παραγγελίας. Παρακαλούμε περιμένετε..<br /><br />\n\
                <img src="${img_src}"></center></div>`);
            },

            preparePayform: function () {
                Payform.load(this.payload, this.EverypayModal);
            },

            loadPayform: function () {
                this.EverypayModal = new EverypayModal();
                this.enableLoadingScreen();
                Payform.load(this.payload, this.EverypayModal, function (modal) {
                    if (document.querySelector('.loader-everypay'))
                        document.querySelector('.loader-everypay').remove();

                    modal.open();
                });
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

            setBillingData: function () {

                this.billingData = {
                    address: null,
                    city: null,
                    postalCode: null,
                    country: null
                };

                let magentoBillingData = quote.billingAddress();

                if (magentoBillingData.street[0])
                    this.billingData.address = magentoBillingData.street[0];

                if (magentoBillingData.city)
                    this.billingData.city = magentoBillingData.city;

                if (magentoBillingData.postcode)
                    this.billingData.postalCode = magentoBillingData.postcode;

                if (magentoBillingData.countryId)
                    this.billingData.country = magentoBillingData.countryId;

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

                    if (!window.checkoutConfig.payment.everypay.customerCards)
                        window.checkoutConfig.payment.everypay.customerCards = jsonCards.cards;

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

                if (typeof $("input[name='card']:checked").val() != 'undefined'){
                    this.payWithSavedCard();
                }else{
                    if (!document.querySelector('#pay-form') || this.EverypayTokenizationModal)
                        this.loadPayform();
                    else
                        this.EverypayModal.open();


                }

            },
        });
    }
);
