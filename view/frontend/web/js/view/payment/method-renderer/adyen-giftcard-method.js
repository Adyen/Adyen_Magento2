/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2020 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */
define(
    [
        'jquery',
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/action/place-order',
        'Adyen_Payment/js/helper/currencyHelper',
        'Adyen_Payment/js/model/adyen-payment-service',
        'Adyen_Payment/js/model/adyen-configuration',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/model/error-processor',
        'mage/translate'
    ],
    function(
        $,
        ko,
        Component,
        fullScreenLoader,
        quote,
        placeOrderAction,
        currencyHelper,
        adyenPaymentService,
        adyenConfiguration,
        customerData,
        errorProcessor,
        $t
    ) {
        'use strict';
        const giftcardChangedEvent = 'Adyen_Payment_Event:giftcardChangedEvent';

        return Component.extend({
            defaults: {
                template: 'Adyen_Payment/payment/giftcard-form'
            },
            giftcardPaymentMethods: ko.observable(null),
            paymentMethodsResponse: ko.observable(null),
            selectedGiftcard: ko.observable(null),
            redeemedCards: ko.observableArray(),
            totalGiftcardBalance: ko.observable(),
            canAddNewGiftCard: ko.observable(true),
            showRemoveSingleGiftcardButton: ko.observable(false),
            showAvailableGiftcardPaymentMethods: ko.observable(false),
            showPlaceOrderButton: ko.observable(false),
            selectedGiftcardPaymentMethod: ko.observable(null),
            giftcardTitle: ko.observable(null),
            icon: ko.observable(window.checkoutConfig.payment.adyen.giftcard.icon),

            initialize: async function () {
                this._super();
                let self = this;

                let paymentMethodsObserver = adyenPaymentService.getPaymentMethods();
                paymentMethodsObserver.subscribe(
                    function(paymentMethodsResponse) {
                        self.paymentMethodsResponse(paymentMethodsResponse);
                        self.fetchGiftcardPaymentMethods(paymentMethodsResponse);
                        self.fetchRedeemedGiftcards();
                    }
                );

                if(!!paymentMethodsObserver()) {
                    this.paymentMethodsResponse(paymentMethodsObserver());
                    this.fetchGiftcardPaymentMethods(paymentMethodsObserver());
                    this.fetchRedeemedGiftcards();
                }
            },

            addNewGiftcard: function () {
                this.canAddNewGiftCard(false);
                this.showAvailableGiftcardPaymentMethods(true);
            },

            removeSingleGiftcard: function () {
                this.removeCheckoutComponent();
                this.canAddNewGiftCard(true);
                this.showPlaceOrderButton(false);
                this.showRemoveSingleGiftcardButton(false);
                this.giftcardTitle(null);
            },

            fetchGiftcardPaymentMethods: function (paymentMethodsResponse) {
                fullScreenLoader.startLoader();
                let giftcards = [];

                let paymentMethods = paymentMethodsResponse.paymentMethodsResponse?.paymentMethods;

                if (!!paymentMethods && paymentMethods.length > 0) {
                    giftcards.push({
                        key: $t('Please select a giftcard...'),
                        value: ''
                    });

                    $.each(paymentMethods, function (index, paymentMethod) {
                        if (paymentMethod.type === 'giftcard') {
                            giftcards.push({
                                key: paymentMethod.name,
                                value: paymentMethod.brand
                            });
                        }
                    });

                    this.giftcardPaymentMethods(giftcards);
                }

                fullScreenLoader.stopLoader();
            },

            giftcardOnSelect: async function (obj, event, test) {
                let selectedValue = event.target.value;

                if (selectedValue !== "") {
                    fullScreenLoader.startLoader();

                    this.showAvailableGiftcardPaymentMethods(false);
                    // add giftcard title to component wrapper
                    let selectedGiftcard = this.giftcardPaymentMethods().find(function (paymentMethod) {
                        return paymentMethod.value === selectedValue;
                    });

                    this.selectedGiftcard(selectedGiftcard);

                    if (typeof this.adyenCheckoutComponent === 'undefined') {
                        this.adyenCheckout = await this.initiateAdyenCheckout();
                    }

                    this.mountGiftcardComponent();
                    this.showRemoveSingleGiftcardButton(true);

                    $('#adyen_giftcard_giftcard_payment_methods').val('');

                    fullScreenLoader.stopLoader();
                }
            },

            initiateAdyenCheckout: function () {
                let formattedRemainingOrderAmount = this.getFormattedRemainingOrderAmount();
                const countryCode = quote.billingAddress().countryId;

                let adyenCheckoutConfiguration = {
                    locale: adyenConfiguration.getLocale(),
                    countryCode: countryCode,
                    clientKey: adyenConfiguration.getClientKey(),
                    environment: adyenConfiguration.getCheckoutEnvironment(),
                    amount: {
                        currency: window.checkoutConfig.payment.adyen.giftcard.currency,
                        value: (formattedRemainingOrderAmount < 0) ? 0 : formattedRemainingOrderAmount
                    }
                };

                return window.AdyenWeb.AdyenCheckout(adyenCheckoutConfiguration);
            },

            getGiftcardComponentConfiguration: function () {
                let self = this;
                return {
                    brand: this.selectedGiftcard().value,
                    showPayButton: true,
                    onBalanceCheck: function (resolve, reject, data) {
                        let formattedRemainingOrderAmount = self.getFormattedRemainingOrderAmount();
                        data.amount = {
                            currency: window.checkoutConfig.payment.adyen.giftcard.currency,
                            value: (formattedRemainingOrderAmount < 0) ? 0 : formattedRemainingOrderAmount
                        }
                        adyenPaymentService.paymentMethodsBalance(data)
                            .done(function (balanceResponse) {
                                let response = JSON.parse(balanceResponse);
                                self.handleBalanceResult(response, data, resolve);
                            })
                            .fail(function () {
                                reject();
                                console.log('Balance check failed!');
                            });
                    },
                    onSubmit: function (data, component, actions) {
                        self.placeOrder(data, actions);
                    }
                };
            },

            removeGiftcard: function (data, event) {
                let self = this;
                fullScreenLoader.startLoader();
                adyenPaymentService.removeStateData(data.stateDataId).done(function () {
                    self.fetchRedeemedGiftcards();
                    fullScreenLoader.stopLoader();
                }).fail(function(response) {
                    fullScreenLoader.stopLoader();
                    self.fetchRedeemedGiftcards();

                    errorProcessor.process(response, this.currentMessageContainer);
                });
            },

            getFormattedRemainingOrderAmount: function() {
                let rawRemainingOrderAmount = quote.totals().grand_total - this.totalGiftcardBalance();
                return currencyHelper.formatAmount(
                    rawRemainingOrderAmount,
                    window.checkoutConfig.payment.adyen.giftcard.currency
                );
            },

            handleBalanceResult: function (balanceResponse, stateData, resolve) {
                let self = this;

                let consumableBalance;
                if (balanceResponse.transactionLimit && balanceResponse.transactionLimit.value) {
                    if (balanceResponse.transactionLimit.value <= balanceResponse.balance.value) {
                        consumableBalance = balanceResponse.transactionLimit;
                    } else {
                        consumableBalance = balanceResponse.balance;
                    }
                } else {
                    consumableBalance = balanceResponse.balance;
                }

                let orderAmount = currencyHelper.formatAmount(
                    quote.totals().grand_total,
                    window.checkoutConfig.payment.adyen.giftcard.currency
                );

                if(this.totalGiftcardBalance() === 0 && consumableBalance.value >= orderAmount) {
                    resolve(balanceResponse);
                } else if (orderAmount > this.totalGiftcardBalance()) {
                    stateData.giftcard = {
                        balance: consumableBalance,
                        title: this.selectedGiftcard().key
                    }
                    adyenPaymentService.saveStateData(stateData).done(function () {
                        self.removeCheckoutComponent();
                        self.giftcardTitle(null);
                        self.showRemoveSingleGiftcardButton(false);
                        // Update the list of the redeemed giftcards and giftcard balance total
                        self.fetchRedeemedGiftcards();
                    });
                }
            },

            fetchRedeemedGiftcards: function () {
                let self = this;
                let orderAmount = currencyHelper.formatAmount(
                    quote.totals().grand_total,
                    window.checkoutConfig.payment.adyen.giftcard.currency
                );

                adyenPaymentService.fetchRedeemedGiftcards().done(function (response) {
                    response = JSON.parse(response);
                    customerData.set(giftcardChangedEvent, response);
                    let totalBalance = 0;

                    $.each(response.redeemedGiftcards, function (index, item) {
                        totalBalance += item.balance.value;
                        response.redeemedGiftcards[index].icon =
                            self.paymentMethodsResponse().paymentMethodsExtraDetails[item.brand].icon;
                    });

                    self.totalGiftcardBalance(totalBalance);
                    self.redeemedCards(response.redeemedGiftcards);

                    // Compare the new total giftcard balance with the order amount
                    if (orderAmount > self.totalGiftcardBalance()) {
                        self.canAddNewGiftCard(true);
                        self.showPlaceOrderButton(false);
                    } else {
                        // initiate place order button of magento checkout / not the pay button of the component
                        self.canAddNewGiftCard(false);
                        self.showPlaceOrderButton(true);
                    }
                });
            },

            removeCheckoutComponent: function () {
                if (typeof this.adyenCheckout !== 'undefined') {
                    this.adyenCheckout.remove(this.giftcardComponent);
                }
            },

            mountGiftcardComponent: function () {
                if (typeof this.giftcardComponent !== 'undefined') {
                    this.adyenCheckout.remove(this.giftcardComponent);
                }

                const giftcardConfiguration = this.getGiftcardComponentConfiguration();

                this.giftcardComponent = window.AdyenWeb.createComponent(
                    'giftcard',
                    this.adyenCheckout,
                    giftcardConfiguration
                ).mount('#giftcard-component-wrapper');

                this.giftcardTitle(this.selectedGiftcard().key);
            },

            placeOrder: async function(stateData, actions) {
                let self = this;

                let additionalData = {
                    frontendType: 'default'
                };

                if (!!stateData.data) {
                    additionalData.stateData = JSON.stringify(stateData.data);
                }

                let data = {
                    'method': this.item.method,
                    'additional_data': additionalData
                };

                await $.when(placeOrderAction(data, self.currentMessageContainer)).fail(
                    function(response) {
                        console.log(response);
                        actions.reject();
                    }
                ).done(
                    function(orderId) {
                        self.afterPlaceOrder();
                        adyenPaymentService.getOrderPaymentStatus(orderId).done(function(responseJSON) {
                            self.validateActionOrPlaceOrder(responseJSON, orderId, actions);
                        });
                    }
                );
            },

            validateActionOrPlaceOrder: function(responseJSON, orderId, actions) {
                let self = this;
                let response = JSON.parse(responseJSON);

                if (!!response.isFinal) {
                    // Status is final redirect to the success page
                    actions.resolve();
                    $.mage.redirect(
                        window.checkoutConfig.payment.adyen.successPage
                    );
                } else {
                    // render component
                    self.orderId = orderId;
                    console.log("action required!");
                }
            },

            isActive: function() {
                return false;
            },
        });
    }
);
