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
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Adyen_Payment/js/model/installments',
        'mage/url',
        'Magento_Vault/js/view/payment/vault-enabler',
        'Magento_Checkout/js/model/url-builder',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/model/error-processor',
        'Adyen_Payment/js/model/adyen-payment-service',
        'Adyen_Payment/js/model/adyen-configuration',
        'Adyen_Payment/js/model/adyen-payment-modal',
        'Adyen_Payment/js/model/adyen-checkout'
    ],
    function(
        $,
        ko,
        Component,
        customer,
        additionalValidators,
        quote,
        installmentsHelper,
        url,
        VaultEnabler,
        urlBuilder,
        fullScreenLoader,
        errorProcessor,
        adyenPaymentService,
        adyenConfiguration,
        AdyenPaymentModal,
        adyenCheckout
    ) {
        'use strict';
        return Component.extend({
            // need to duplicate as without the button will never activate on first time page view
            isPlaceOrderActionAllowed: ko.observable(
                quote.billingAddress() != null),
            comboCardOption: ko.observable('credit'),

            defaults: {
                template: 'Adyen_Payment/payment/cc-form',
                installment: '', // keep it until the component implements installments
                orderId: 0, // TODO is this the best place to store it?
                storeCc: false,
                modalLabel: 'cc_actionModal'
            },
            initObservable: function() {
                this._super().observe([
                    'creditCardType',
                    'installment',
                    'installments',
                    'placeOrderAllowed',
                    'adyenCCMethod',
                    'logo'
                ]);

                return this;
            },
            /**
             * @returns {exports.initialize}
             */
            initialize: function () {
                this._super();
                this.vaultEnabler = new VaultEnabler();
                this.vaultEnabler.setPaymentCode(this.getVaultCode());
                this.vaultEnabler.isActivePaymentTokenEnabler(false);

                let self = this;

                let paymentMethodsObserver = adyenPaymentService.getPaymentMethods();
                paymentMethodsObserver.subscribe(
                    function (paymentMethodsResponse) {
                        self.loadCheckoutComponent(paymentMethodsResponse)
                    });

                self.loadCheckoutComponent(paymentMethodsObserver());
                return this;
            },
            loadCheckoutComponent: async function (paymentMethodsResponse) {
                let self = this;

                this.checkoutComponent = await adyenCheckout.buildCheckoutComponent(
                    paymentMethodsResponse,
                    this.handleOnAdditionalDetails.bind(this)
                )

                if (!!this.checkoutComponent) {
                    // Setting the icon as an accessible field if it is available
                    self.adyenCCMethod({
                            icon: !!paymentMethodsResponse.paymentMethodsExtraDetails.card
                                ? paymentMethodsResponse.paymentMethodsExtraDetails.card.icon
                                : undefined
                        })
                }
            },
            /**
             * Returns true if card details can be stored
             * @returns {*|boolean}
             */
            getEnableStoreDetails: function () {
                // TODO refactor the configuration for this
                return this.isOneClickEnabled() || this.isVaultEnabled();
            },
            /**
             * Renders the secure fields,
             * creates the card component,
             * sets up the callbacks for card components and
             * set up the installments
             */
            renderCCPaymentMethod: function() {
                var self = this;
                if (!self.getClientKey) {
                    return false;
                }

                self.installments(0);

                // installments
                let allInstallments = self.getAllInstallments();

                debugger;

                let componentConfig = {
                    enableStoreDetails: self.getEnableStoreDetails(),
                    brands: self.getBrands(),
                    hasHolderName: adyenConfiguration.getHasHolderName(),
                    holderNameRequired: adyenConfiguration.getHasHolderName() &&
                        adyenConfiguration.getHolderNameRequired(),
                    onChange: function(state, component) {
                        self.placeOrderAllowed(!!state.isValid);
                        self.storeCc = !!state.data.storePaymentMethod;
                    },
                    // Keep onBrand as is until checkout component supports installments
                    onBrand: function(state) {
                        // Define the card type
                        // translate adyen card type to magento card type
                        var creditCardType = self.getCcCodeByAltCode(
                            state.brand);
                        if (creditCardType) {
                            // If the credit card type is already set, check if it changed or not
                            if (!self.creditCardType() ||
                                self.creditCardType() &&
                                self.creditCardType() != creditCardType) {
                                var numberOfInstallments = [];

                                if (creditCardType in allInstallments) {
                                    // get for the creditcard the installments
                                    var installmentCreditcard = allInstallments[creditCardType];
                                    var grandTotal = self.grandTotal();
                                    var precision = quote.getPriceFormat().precision;
                                    var currencyCode = quote.totals().quote_currency_code;

                                    numberOfInstallments = installmentsHelper.getInstallmentsWithPrices(
                                        installmentCreditcard, grandTotal,
                                        precision, currencyCode);
                                }

                                if (numberOfInstallments) {
                                    self.installments(numberOfInstallments);
                                } else {
                                    self.installments(0);
                                }
                            }

                            self.creditCardType(creditCardType);
                        } else {
                            self.creditCardType('');
                            self.installments(0);
                        }
                    }
                }

                self.cardComponent = adyenCheckout.mountPaymentMethodComponent(
                    this.checkoutComponent,
                    'card',
                    componentConfig,
                    '#cardContainer'
                )

                return true
            },

            handleAction: function(action, orderId) {
                var self = this;
                let popupModal;

                fullScreenLoader.stopLoader();

                if (action.type === 'threeDS2' || action.type === 'await') {
                    popupModal = self.showModal();
                }

                try {
                    self.checkoutComponent.createFromAction(
                        action).mount('#' + this.modalLabel);
                } catch (e) {
                    console.log(e);
                    self.closeModal(popupModal);
                }
            },
            showModal: function() {
                let actionModal = AdyenPaymentModal.showModal(adyenPaymentService, fullScreenLoader, this.messageContainer, this.orderId, this.modalLabel, this.isPlaceOrderActionAllowed);
                $("." + this.modalLabel + " .action-close").hide();

                return actionModal;
            },
            /**
             * This method is a workaround to close the modal in the right way and reconstruct the threeDS2Modal.
             * This will solve issues when you cancel the 3DS2 challenge and retry the payment
             */
            closeModal: function(popupModal) {
                AdyenPaymentModal.closeModal(popupModal, this.modalLabel)
            },
            /**
             * Get data for place order
             * @returns {{method: *}}
             */
            getData: function() {
                let stateData = JSON.stringify(this.cardComponent.data);

                window.sessionStorage.setItem('adyen.stateData', stateData);
                return {
                    'method': this.item.method,
                    additional_data: {
                        'stateData': stateData,
                        'guestEmail': quote.guestEmail,
                        'cc_type': this.creditCardType(),
                        'combo_card_type': this.comboCardOption(),
                        //This is required by magento to store the token
                        'is_active_payment_token_enabler' : this.storeCc,
                        'number_of_installments': this.installment(),
                    },
                };
            },
            /**
             * Returns state of place order button
             * @returns {boolean}
             */
            isButtonActive: function() {
                // TODO check if isPlaceOrderActionAllowed and placeOrderAllowed are both needed
                return this.isActive() && this.getCode() == this.isChecked() &&
                    this.isPlaceOrderActionAllowed() &&
                    this.placeOrderAllowed();
            },
            /**
             * Custom place order function
             *
             * @override
             *
             * @param data
             * @param event
             * @returns {boolean}
             */
            placeOrder: function(data, event) {
                var self = this;

                if (event) {
                    event.preventDefault();
                }

                if (this.validate() && additionalValidators.validate()) {
                    fullScreenLoader.startLoader();
                    self.isPlaceOrderActionAllowed(false);

                    self.getPlaceOrderDeferredObject().fail(
                        function() {
                            fullScreenLoader.stopLoader();
                            self.isPlaceOrderActionAllowed(true);
                        }
                    ).done(
                        function(orderId) {
                            self.afterPlaceOrder();
                            self.orderId = orderId;
                            adyenPaymentService.getOrderPaymentStatus(orderId).
                                done(function(responseJSON) {
                                    self.handleAdyenResult(responseJSON,
                                        orderId);
                                });
                        }
                    );
                }
                return false;
            },
            /**
             * Based on the response we can start a 3DS2 validation or place the order
             * @param responseJSON
             */
            handleAdyenResult: function(responseJSON, orderId) {
                var self = this;
                var response = JSON.parse(responseJSON);

                if (!!response.isFinal) {
                    // Status is final redirect to the success page
                    window.location.replace(url.build(
                        window.checkoutConfig.payment[quote.paymentMethod().method].successPage
                    ));
                } else {
                    // Handle action
                    self.handleAction(response.action, orderId);
                }
            },
            handleOnAdditionalDetails: function(result) {
                var self = this;

                var request = result.data;
                request.orderId = self.orderId;

                fullScreenLoader.stopLoader();

                let popupModal = self.showModal();

                adyenPaymentService.paymentDetails(request).
                    done(function(responseJSON) {
                        self.handleAdyenResult(responseJSON, self.orderId);
                    }).
                    fail(function(response) {
                        self.closeModal(popupModal);
                        errorProcessor.process(response, self.messageContainer);
                        self.isPlaceOrderActionAllowed(true);
                        fullScreenLoader.stopLoader();
                    });
            },
            /**
             * Validates the payment date when clicking the pay button
             *
             * @returns {boolean}
             */
            validate: function() {
                var form = 'form[data-role=adyen-cc-form]';

                var validate = $(form).validation() &&
                    $(form).validation('isValid') &&
                    this.cardComponent.isValid;

                if (!validate) {
                    this.cardComponent.showValidation();
                    return false;
                }

                return true;
            },

            /**
             * Translates the card type alt code (used in Adyen) to card type code (used in Magento) if it's available
             *
             * @param altCode
             * @returns {*}
             */
            getCcCodeByAltCode: function(altCode) {
                var ccTypes = window.checkoutConfig.payment.ccform.availableTypesByAlt[this.getCode()];
                if (ccTypes.hasOwnProperty(altCode)) {
                    return ccTypes[altCode];
                }

                return '';
            },

            /**
             * Fetches the brands array of the credit cards
             *
             * @returns {array}
             */
            getBrands: function() {
                let emptyArr = [];
                let paymentMethods =
                    adyenPaymentService.getPaymentMethods()._latestValue.paymentMethodsResponse.paymentMethods;

                for (let i = 0; i < paymentMethods.length; i++) {
                    let paymentMethod = paymentMethods[i];
                    if (Object.values(paymentMethod).includes("Credit Card")) {
                        return paymentMethod.brands;
                    }
                }
            },
            /**
             * Return Payment method code
             *
             * @returns {*}
             */
            getCode: function() {
                return window.checkoutConfig.payment.adyenCc.methodCode;
            },
            isOneClickEnabled: function () {
                if (customer.isLoggedIn()) {
                    return window.checkoutConfig.payment.adyenCc.isOneClickEnabled;
                }

                return false;
            },
            getIcons: function(type) {
                return window.checkoutConfig.payment.adyenCc.icons.hasOwnProperty(
                    type)
                    ? window.checkoutConfig.payment.adyenCc.icons[type]
                    : false;
            },
            hasInstallments: function() {
                return this.comboCardOption() === 'credit' &&
                    window.checkoutConfig.payment.adyenCc.hasInstallments;
            },
            getAllInstallments: function() {
                return window.checkoutConfig.payment.adyenCc.installments;
            },
            areComboCardsEnabled: function() {
                if (quote.billingAddress() === null) {
                    return false;
                }
                var countryId = quote.billingAddress().countryId;
                var currencyCode = quote.totals().quote_currency_code;
                var allowedCurrenciesByCountry = {
                    'BR': 'BRL',
                    'MX': 'MXN',
                };
                return allowedCurrenciesByCountry[countryId] &&
                    currencyCode === allowedCurrenciesByCountry[countryId];
            },
            getClientKey: function() {
                return adyenConfiguration.getClientKey();
            },
            /**
             * @returns {Bool}
             */
            isVaultEnabled: function() {
                return this.vaultEnabler.isVaultEnabled();
            },
            /**
             * @returns {String}
             */
            getVaultCode: function() {
                return window.checkoutConfig.payment[this.getCode()].vaultCode;
            },

            // Default payment functions
            setPlaceOrderHandler: function(handler) {
                this.placeOrderHandler = handler;
            },
            setValidateHandler: function(handler) {
                this.validateHandler = handler;
            },
            context: function() {
                return this;
            },
            isShowLegend: function() {
                return true;
            },
            showLogo: function() {
                return adyenConfiguration.showLogo();
            },
            isActive: function() {
                return true;
            },
            getControllerName: function() {
                return window.checkoutConfig.payment.iframe.controllerName[this.getCode()];
            },
            getPlaceOrderUrl: function() {
                return window.checkoutConfig.payment.iframe.placeOrderUrl[this.getCode()];
            },
            grandTotal: function () {
                for (const totalsegment of quote.getTotals()()['total_segments']) {
                    if (totalsegment.code === 'grand_total') {
                        return totalsegment.value;
                    }
                }
                return quote.totals().grand_total;
            },
        });
    }
);
