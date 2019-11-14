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
    'mage/translate',
    'braintreeThreeDSecure',
    'Magento_Checkout/js/model/full-screen-loader'
], function ($, braintree, quote, $t, threeDSecure, fullScreenLoader) {
    'use strict';

    var mixin = {

        /**
         * Validate Braintree payment nonce
         * @param {Object} context
         * @returns {Object}
         */
        validate: function (context) {
            var clientInstance = braintree.getApiClient(),
                state = $.Deferred(),
                totalAmount = quote.totals()['base_grand_total'],
                billingAddress = quote.billingAddress();

            // No 3d secure if using CVV verification on vaulted cards
            if (quote.paymentMethod().method.indexOf('braintree_cc_vault_') !== -1) {
                if (this.config.useCvvVault === true) {
                    state.resolve();
                    return state.promise();
                }
            }

            if (!this.isAmountAvailable(totalAmount) || !this.isCountryAvailable(billingAddress.countryId)) {
                state.resolve();
                return state.promise();
            }

            fullScreenLoader.startLoader();

            var setup3d = function(clientInstance) {
                threeDSecure.create({
                    client: clientInstance
                }, function (threeDSecureErr, threeDSecureInstance) {
                    if (threeDSecureErr) {
                        fullScreenLoader.stopLoader();
                        return state.reject($t('Please try again with another form of payment.'));
                    }

                    var threeDSContainer = document.createElement('div'),
                        tdmask = document.createElement('div'),
                        tdframe = document.createElement('div'),
                        tdbody = document.createElement('div');

                    threeDSContainer.id = 'braintree-three-d-modal';
                    tdmask.className ="bt-mask";
                    tdframe.className ="bt-modal-frame";
                    tdbody.className ="bt-modal-body";

                    tdframe.appendChild(tdbody);
                    threeDSContainer.appendChild(tdmask);
                    threeDSContainer.appendChild(tdframe);

                    threeDSecureInstance.verifyCard({
                        amount: totalAmount,
                        nonce: context.paymentMethodNonce,
                        addFrame: function (err, iframe) {
                            fullScreenLoader.stopLoader();

                            if (err) {
                                console.log("Unable to verify card over 3D Secure", err);
                                return state.reject($t('Please try again with another form of payment.'));
                            }

                            tdbody.appendChild(iframe);
                            document.body.appendChild(threeDSContainer);
                        },
                        removeFrame: function () {
                            fullScreenLoader.startLoader();
                            document.body.removeChild(threeDSContainer);
                        }
                    }, function (err, response) {
                        fullScreenLoader.stopLoader();

                        if (err) {
                            console.log("3dsecure validation failed", err);
                            return state.reject($t('Please try again with another form of payment.'));
                        }

                        var liability = {
                            shifted: response.liabilityShifted,
                            shiftPossible: response.liabilityShiftPossible
                        };

                        if (liability.shifted || !liability.shifted && !liability.shiftPossible) {
                            context.paymentMethodNonce = response.nonce;
                            state.resolve();
                        } else {
                            //saving 3D Secure Refuse reason in db.
                            var serviceUrl = window.location.origin + "/decider/advice/call",
                                payload = {
                                    mode: 'braintree-3DS-deny',
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
                });
            };

            if (!clientInstance) {
                require(['Magento_Braintree/js/view/payment/method-renderer/cc-form'], function(c) {
                    var config = c.extend({
                        defaults: {
                            clientConfig: {
                                onReady: function() {}
                            }
                        }
                    });
                    braintree.setConfig(config.defaults.clientConfig);
                    braintree.setup(setup3d);
                });
            } else {
                setup3d(clientInstance);
            }

            return state.promise();
        }
    };

    return function (target) {
        return target.extend(mixin);
    };
});