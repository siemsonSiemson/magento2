var config = {
    config: {
        mixins: {
            'Magento_Braintree/js/view/payment/validator-handler': {
                'Riskified_Decider/js/view/payment/braintree/validator-handler-mixin': true
            },
        }
    }
};