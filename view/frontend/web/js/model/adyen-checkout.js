/**
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */
define(
    [
        'jquery',
        'Adyen_Payment/js/model/adyen-configuration',
        'Adyen_Payment/js/adyen'
    ],
    function(
        $,
        adyenConfiguration,
        AdyenCheckout,
    ) {
        'use strict';
        return {
            buildCheckoutComponent: function(paymentMethodsResponse, handleOnAdditionalDetails, handleOnCancel = undefined, handleOnSubmit = undefined) {
                if (!!paymentMethodsResponse.paymentMethodsResponse) {
                    return AdyenCheckout({
                            locale: adyenConfiguration.getLocale(),
                            clientKey: adyenConfiguration.getClientKey(),
                            environment: adyenConfiguration.getCheckoutEnvironment(),
                            paymentMethodsResponse: paymentMethodsResponse.paymentMethodsResponse,
                            onAdditionalDetails: handleOnAdditionalDetails,
                            onCancel: handleOnCancel,
                            onSubmit: handleOnSubmit
                        }
                    );
                } else {
                    return false
                }
            },
            mountPaymentMethodComponent(checkoutComponent, paymentMethodType, configuration, elementLabel, result = undefined) {
                if($(elementLabel).length) {
                    try {
                        const paymentMethodComponent = checkoutComponent.create(
                            paymentMethodType,
                            configuration
                        )

                        if ('isAvailable' in paymentMethodComponent) {
                            paymentMethodComponent.isAvailable().then(() => {
                                paymentMethodComponent.mount(elementLabel);
                            }).catch(e => {
                                if (!!result) {
                                    result.isAvailable(false); // Set observable to false, to match component availability
                                }
                            });
                        } else {
                            paymentMethodComponent.mount(elementLabel);
                        }

                        return paymentMethodComponent
                    } catch (err) {
                        console.log(err);
                    }
                }

                return false;
            }
        };
    }
);
