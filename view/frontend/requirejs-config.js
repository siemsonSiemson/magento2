var config = {
    config: {
        mixins: {
            'Magento_Braintree/js/view/payment/validator-handler': {
                'Riskified_Decider/js/view/payment/braintree/validator-handler-mixin': true
            },
        },
        map: {
            '*': {
                'Adyen_Payment/js/view/payment/method-renderer/adyen-cc-method':'Riskified_Decider/js/view/payment/method-renderer/adyen-cc-method',
                'Magento_Braintree/js/view/payment/method-renderer/paypal': 'Riskified_Decider/js/view/payment/braintree/method-renderer/paypal'
            }
        }
    }
};