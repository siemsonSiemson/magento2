/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'Magento_Payment/js/view/payment/iframe',
    'mage/translate'
],
function ($, Component, $t) {
    'use strict';

    var mixin = {

        setPlaceOrderHandler: function (handler) {
            console.log('test');
            debugger;
            this.placeOrderHandler = handler;
        },
    };

    return function (target) {
        return target.extend(mixin);
    };
});
