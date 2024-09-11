/**
 * Copyright © 2021 Everypay. All rights reserved.
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
                this.setShippingData();
                this.EverypayModal = new EverypayModal();
                return this;
            },

            setPayload: function () {
                let installments  = this.getInstallments();
                this.amount = this.getTotal().total;
                this.payload = Payform.createPayload(this.amount, installments, this.billingData, this.shippingData);
            },

            payWithSavedCard: function () {
                this.EverypayTokenizationModal = new EverypayModal();
                let installments  = this.getInstallments();
                let amount = this.getTotal().total;
                let payload = Payform.createTokenizationPayload(amount, installments, this.billingData, this.shippingData);
                Payform.tokenize(payload, this.EverypayTokenizationModal);
            },

            enableLoadingScreen: function () {
                let loadingText = 'Επεξεργασία παραγγελίας. Παρακαλώ περιμένετε...';
                let locale = window.checkoutConfig.payment.everypay.locale;
                if (typeof locale != 'undefined' && locale != 'el') {
                    loadingText = 'Please wait...';
                }
                $('body').prepend(`<div class="loader-everypay" style="position: fixed;height: 100%;width: 100%;background: #f2f2f2;z-index: 100000;top: 0;left: 0;opacity: 0.93;">\n\
                <center style="width: 100%;position: fixed;clear: both;font-size: 1.3em;top: 40%;margin: 0 auto;"><svg style="max-width: 64px; min-width: 64px; max-height: 64px; min-height: 64px;" id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 94.1 94.1"><defs><style>.cls-1{fill:url(#linear-gradient);}.cls-2{fill:#21409a;}.cls-3{fill:#39b54a;}</style><linearGradient id="linear-gradient" x1="47.05" y1="-260.26" x2="47.05" y2="-166.16" gradientTransform="matrix(1, 0, 0, -1, 0, -166.16)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#39b54a"/><stop offset="1" stop-color="#21409a"/></linearGradient></defs><path class="cls-1" d="M94.1,47.05a47.05,47.05,0,1,1-47-47A47,47,0,0,1,94.1,47.05ZM47,8.45A38.69,38.69,0,1,0,85.73,47.14,38.69,38.69,0,0,0,47,8.45Z"><animateTransform attributeType="xml" attributeName="transform" type="rotate" from="0 47.05 47.10" to="360 47.05 47.10" dur="1s" additive="sum" repeatCount="indefinite" /></path><path class="cls-2" d="M66.62,24.83c7.73.84,6.38,8.53,6.38,8.53L66.14,51.3H30l-3.43,9.25C19,59.59,21,52.38,21,52.38L31.38,24.67ZM36.71,33.5l-3.57,9.29H60.65l2.51-6.45a2.52,2.52,0,0,0-1.93-2.84Z"/><path class="cls-3" d="M26.8,61S24.74,68,32.05,69.6H60.68s2.06-7.13-5.25-8.52Z"/></svg><br /><br />\n\
                 ${loadingText}</center></div>`);
            },

            loadPayform: function () {
                this.setPayload();
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

                try {
                    let magentoBillingData = quote.billingAddress();

                    if (!magentoBillingData) {
                        return;
                    }

                    if (magentoBillingData.street && magentoBillingData.street[0]) {
                        this.billingData.address = magentoBillingData.street[0];
                    }

                    if (magentoBillingData.city) {
                        this.billingData.city = magentoBillingData.city;
                    }

                    if (magentoBillingData.postcode) {
                        this.billingData.postalCode = magentoBillingData.postcode;
                    }

                    if (magentoBillingData.countryId) {
                        this.billingData.country = magentoBillingData.countryId;
                    }
                } catch (e) {
                    console.log(e)
                }

            },

            setShippingData: function () {

                this.shippingData = {
                    email: quote.guestEmail,
                    phone: null
                };

                try {
                    let magentoShippingData = quote.shippingAddress();

                    if (!magentoShippingData) {
                        return;
                    }

                    if (magentoShippingData.email) {
                        this.shippingData.email = magentoShippingData.email;
                    }

                    if (magentoShippingData.telephone) {
                        this.shippingData.phone = magentoShippingData.telephone;
                    }
                } catch (e) {
                    console.log(e)
                }

            },

            getCode: function() {
                return 'everypay';
            },

            getTotals: function() {
                return totals.totals();
            },

            getTotal: function() {
                var data = {
                    'total' : parseInt((this.getTotals().base_grand_total * 100).toFixed(0))
                }
                return data;

            },


            getInstallments: function(){

                let max_installments = 0;
                let total = this.getTotals().grand_total;
                let plan = window.checkoutConfig.payment.everypay.installments;
                if (plan.length>0){
                    $.each(plan, function(i,v){
                        if (total >= parseFloat(v[1])){
                            max_installments = parseInt(v[0]);
                        }
                    })
                }
                let payform_installments = [];

                if ( max_installments > 0){
                    window.checkoutConfig.payment.everypay.maxInstallments = max_installments;
                    for (let i = 2; i <= max_installments; i++) {
                        if (i >= 36) {
                            continue;
                        }
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

                if (!window.checkoutConfig.isCustomerLoggedIn) {
                    return false;
                }

                if (typeof(window.checkoutConfig.customerData.custom_attributes) == 'undefined'){
                    return false;
                }

                var cards = window.checkoutConfig.customerData.custom_attributes.everypay_vault.value;
                if (cards.length > 0) {
                    return true;
                } else {
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
                if (confirm("Are you sure you want to remove this stored card?")){

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

            clickEverypayButton: function() {

                if (typeof $("input[name='card']:checked").val() != 'undefined'){
                    this.payWithSavedCard();
                    return;
                }

                this.loadPayform();
            },
        });
    }
);
