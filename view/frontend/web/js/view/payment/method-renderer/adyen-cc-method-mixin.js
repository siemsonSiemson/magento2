define(
    [
        'jquery',
        'ko',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Customer/js/model/customer',
        'Magento_Payment/js/model/credit-card-validation/credit-card-data',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Adyen_Payment/js/model/installments',
        'mage/url',
        'Magento_Vault/js/view/payment/vault-enabler',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Paypal/js/action/set-payment-method',
        'Magento_Checkout/js/action/select-payment-method',
        'Adyen_Payment/js/threeds2-js-utils',
        'Adyen_Payment/js/model/threeds2'
    ],
    function ($, ko, Component, customer, creditCardData, additionalValidators, quote, installmentsHelper, url, VaultEnabler, urlBuilder, storage, fullScreenLoader, setPaymentMethodAction, selectPaymentMethodAction, threeDS2Utils, threeds2) {

        'use strict';

        var mixin = {

            /**
             * Based on the response we can start a 3DS2 validation or place the order
             * Extended by Riskified with 3D Secure enable after Riskified-Advise-Api-Call.
             * @param responseJSON
             */
            validateThreeDS2OrPlaceOrder: function (responseJSON) {
                var self = this;
                var response = JSON.parse(responseJSON),
                    threeDS2Status = response.threeDS2;

                //check Riskified-Api-Advise-Call response
                //var serviceUrl = window.location.origin + "/decider/advice/call",
                var serviceUrl = "http://riskified2test.local/riskified2Test/decider/advice/call",
                    params = { quote_id: quote.getQuoteId(), gateway: "adyen_cc" },
                    adviceStatus = false;

                $.ajax({
                    method: "POST",
                    async: false,
                    data: params,
                    url: serviceUrl
                }).done(function( status ){
                    //adjust status for 3D Secure validation
                    adviceStatus = status.advice_status;
                    if(adviceStatus == false){
                        threeDS2Status == true;
                    }else{
                        threeDS2Status == false;
                    }
                });

                if (threeDS2Status == false) {
                    fullScreenLoader.stopLoader();
                    self.isPlaceOrderActionAllowed(false);
                } else if(!!response.threeDS2) {
                    // render component
                    self.renderThreeDS2Component(response.type, response.token);
                } else {
                    self.getPlaceOrderDeferredObject()
                        .fail(
                            function () {
                                fullScreenLoader.stopLoader();
                                self.isPlaceOrderActionAllowed(false);
                            }
                        ).done(
                        function () {
                            self.afterPlaceOrder();

                            if (self.redirectAfterPlaceOrder) {
                                // use custom redirect Link for supporting 3D secure
                                window.location.replace(url.build(
                                    window.checkoutConfig.payment[quote.paymentMethod().method].redirectUrl)
                                );
                            }
                        }
                    );
                }
            }
        };

        return function (target) { // target == Result that Magento_Ui/.../columns returns.
            return target.extend(mixin); // new result that all other modules receive
        };
    }
);


