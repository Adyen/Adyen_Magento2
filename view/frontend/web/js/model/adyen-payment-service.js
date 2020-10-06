/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
define(
    [
        'underscore',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage'
    ],
    function (_, quote, customer, urlBuilder, storage) {
        'use strict';

        return {
            /**
             * Retrieve the list of available payment methods from the server
             */
            retrieveAvailablePaymentMethods: function (callback) {
                var self = this;

                // retrieve payment methods
                var serviceUrl,
                    payload;
                if (customer.isLoggedIn()) {
                    serviceUrl = urlBuilder.createUrl('/carts/mine/retrieve-adyen-payment-methods', {});
                } else {
                    serviceUrl = urlBuilder.createUrl('/guest-carts/:cartId/retrieve-adyen-payment-methods', {
                        cartId: quote.getQuoteId()
                    });
                }

                payload = {
                    cartId: quote.getQuoteId(),
                    shippingAddress: quote.shippingAddress()
                };

                storage.post(
                    serviceUrl,
                    JSON.stringify(payload)
                ).done(
                    function (response) {
                        self.setPaymentMethods(response);
                        if (callback) {
                            callback();
                        }
                    }
                ).fail(
                    function () {
                        self.setPaymentMethods([]);
                    }
                )
            },
            getOrderPaymentStatus: function (orderId) {
                var serviceUrl = urlBuilder.createUrl('/adyen/orders/:orderId/payment-status', {
                    orderId: orderId
                });

                return storage.get(serviceUrl);
            }
        };
    }
);
