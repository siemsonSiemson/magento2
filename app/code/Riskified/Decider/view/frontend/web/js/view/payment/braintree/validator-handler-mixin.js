define([
    'jquery',
    'mage/utils/wrapper',
    'mage/storage',
    'Magento_Braintree/js/view/payment/3d-secure',
    'Magento_Checkout/js/model/quote',
    'Magento_Braintree/js/view/payment/adapter'
], function ($, wrapper, storage, verify3DSecure, quote, paymentAdapter) {
    'use strict';


    function getPaymentMethod()
    {
        let choosenPaymentMethod = $(".payment-method-title").find('input[type="radio"]:checked');

        return choosenPaymentMethod.attr('id');
    }

    return function (braintreeValidatorHandler) {
        braintreeValidatorHandler.validate = function(context, callback) {
            var self = this,
                config = this.getConfig(),
                deferred;

            // no available validators
            if (!self.validators.length) {
                var serviceUrl = window.location.origin + "/decider/advice/call",
                    payload = {
                        mode: 'braintree-3DS-passed',
                        quote_id: quote.getQuoteId(),
                        email : quote.guestEmail,
                        gateway: "braintree_cc"
                    },
                    adviceStatus = false;

                $.ajax({
                    method: "POST",
                    async: false,
                    url: serviceUrl,
                    data: payload
                }).done(function( status ){
                    var riskifiedMessage = status.message;
                    adviceStatus = status.advice_status;
                });

                if(adviceStatus === true){
                    callback();
                    return;
                } else {
                    if (adviceStatus == 3){
                        self.showError(riskifiedMessage);
                        return;
                    } else if (adviceStatus == 'disabled'){
                        callback();

                        return;
                    } else {
                        verify3DSecure.setConfig(config[verify3DSecure.getCode()]);
                        self.add(verify3DSecure);
                    }
                }
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