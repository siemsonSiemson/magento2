/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define([
    'jquery',
    'Magento_Braintree/js/view/payment/adapter',
    'Magento_Checkout/js/model/quote',
    'mage/translate'
], function ($, braintree, quote, $t) {
    'use strict';

        return function (braintreeThreedSecure) {

            braintreeThreedSecure.config = null;

            braintreeThreedSecure.setConfig = function(config) {
                this.config = config;
                this.config.thresholdAmount = parseFloat(config.thresholdAmount);
            };

            braintreeThreedSecure.getCode = function() {
                return 'three_d_secure';
            };

            braintreeThreedSecure.isAmountAvailable = function(amount) {
                amount = parseFloat(amount);

                return amount >= this.config.thresholdAmount;
            };

            braintreeThreedSecure.validate = function(context) {
                var client = braintree.getApiClient(),
                    state = $.Deferred(),
                    totalAmount = quote.totals()['base_grand_total'],
                    billingAddress = quote.billingAddress();

                if (!this.isAmountAvailable(totalAmount) || !this.isCountryAvailable(billingAddress.countryId)) {
                    state.resolve();

                    return state.promise();
                }

                client.verify3DS({
                    amount: totalAmount,
                    creditCard: context.paymentMethodNonce
                }, function (error, response) {
                    var liability;

                    if (error) {
                        state.reject(error.message);

                        return;
                    }

                    liability = {
                        shifted: response.verificationDetails.liabilityShifted,
                        shiftPossible: response.verificationDetails.liabilityShiftPossible
                    };

                    if (liability.shifted || !liability.shifted && !liability.shiftPossible) {
                        context.paymentMethodNonce = response.nonce;
                        state.resolve();
                    } else {
                        //saving 3D Secure Refuse reason in db.
                        var serviceUrl = window.location.origin + "/decider/order/deny",
                            payload = {
                                mode: 'braintree-3DS-deny',
                                gateway: "braintree_cc",
                                quote_id: quote.getQuoteId(),
                                nonce: response.nonce,
                                liabilityShiftPossible: response.verificationDetails.liabilityShiftPossible,
                                liabilityShifted: response.verificationDetails.liabilityShifted
                            };
                        $.ajax({
                            method: "POST",
                            async: false,
                            url: serviceUrl,
                            data: payload
                        });
                        state.reject($t('Please try again with another form of payment.'));
                    }
                });

                return state.promise();
            };

            braintreeThreedSecure.isCountryAvailable = function(countryId) {
                var key,
                    specificCountries = this.config.specificCountries;

                // all countries are available
                if (!specificCountries.length) {
                    return true;
                }

                for (key in specificCountries) {
                    if (countryId === specificCountries[key]) {
                        return true;
                    }
                }

                return false;
            };

            return braintreeThreedSecure;
        };
});