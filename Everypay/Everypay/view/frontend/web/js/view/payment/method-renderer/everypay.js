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

        return Component.extend({
            defaults: {
                template: 'Everypay_Everypay/payment/form',
                transactionResult: '',
                token: 'xxx'
            },

            initObservable: function () {

                this._super()
                    .observe([
                        'transactionResult',
                        'token'
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

            getData: function() {
                var data = {
                    'method': this.getCode(),
                    'additional_data': {
                        transaction_result: this.transactionResult(),
                        token: window.checkoutConfig.payment.everypay.token
                    }
                };
                data['additional_data'] = _.extend(data['additional_data'], this.additionalData);

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

            getFixedAmount: function() {
                return window.checkoutConfig.payment.everypay.fixedamount;
            },

            makeButton: function(){
                var xdata = this.getTotal();
                var installments = this.getInstallments();
                console.log(window.checkoutConfig.payment.everypay);
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
                this.makeButton();

            }
        });
    }
);