define([
    'jquery',
    'mage/utils/wrapper',
    'mage/storage',
    'Magento_Braintree/js/view/payment/3d-secure',
    'Magento_Checkout/js/model/quote'
], function ($, wrapper, storage, verify3DSecure, quote) {
    'use strict';

    function getPaymentMethod()
    {
        let paymentMethodName = "";
        $(".payment-method-title").each(function( index, value ) {
            var currentRadio = $(this).find('input[type="radio"]:checked');
            if(currentRadio.length == 1){
                paymentMethodName = currentRadio.attr('id');
            }else{
                paymentMethodName = "undefined";
            }
        });

        return paymentMethodName;
    }

    return function (braintreeValidatorHandler) {
        braintreeValidatorHandler.validate = function(context, callback) {
            var self = this,
                config = this.getConfig(),
                deferred;

            // no available validators
            if (!self.validators.length) {

                let serviceUrl = "http://riskified2.local/riskified2/decider/advice/call",
                    payload = { quote_id: quote.getQuoteId(), payment_method: getPaymentMethod() },
                    adviceStatus = false;

                $.ajax({
                    method: "POST",
                    async: false,
                    url: serviceUrl,
                    data: payload
                }).done(function( status ){
                    adviceStatus = status;
                });

                callback();
                debugger;

                if(config[verify3DSecure.getCode()].enabled){
                    verify3DSecure.setConfig(config[verify3DSecure.getCode()]);
                    self.add(verify3DSecure);
                }

                return;
            }

            // get list of deferred validators
            deferred = $.map(self.validators, function (current) {
                return current.validate(context);
            });

            $.when.apply($, deferred)
                .done(function () {
                    callback();
                }).fail(function (error) {
                self.showError(error);
            });
        };
        braintreeValidatorHandler.initRiskifiedAdviceCall = function(serviceUrl, payload) {
            return storage.post(
                serviceUrl, payload
            ).fail(
                function (response) {
                    errorProcessor.process(response, messageContainer);
                }
            ).always(
                function () {
                    fullScreenLoader.stopLoader();
                }
            );
        };

        return braintreeValidatorHandler;
    };
});