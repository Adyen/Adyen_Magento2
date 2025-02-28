/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */
define(
    [
        'ko',
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Adyen_Payment/js/model/adyen-payment-service',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/error-processor',
        'Adyen_Payment/js/model/adyen-configuration',
        'Adyen_Payment/js/model/adyen-payment-modal',
        'Adyen_Payment/js/model/adyen-checkout',
        'Adyen_Payment/js/model/payment-component-states',
    ],
    function(
        ko,
        $,
        Component,
        quote,
        additionalValidators,
        adyenPaymentService,
        fullScreenLoader,
        placeOrderAction,
        errorProcessor,
        adyenConfiguration,
        adyenPaymentModal,
        adyenCheckout,
        paymentComponentStates
    ) {
        'use strict';
        let popupModal;

        return Component.extend({
            placeOrderButtonVisible: true,
            checkoutComponent: null,
            paymentComponent: null,
            defaults: {
                template: 'Adyen_Payment/payment/pm-form',
                orderId: null,
                modalLabel: 'hpp_actionModal'
            },

            initObservable: function() {
                this._super().observe([
                    'paymentMethod',
                    'paymentMethodsExtraInfo',
                    'adyenPaymentMethod',
                    'isPlaceOrderActionAllowed',
                    'placeOrderAllowed'
                ]);

                return this;
            },

            initialize: function() {
                this._super();
                let self = this;

                let paymentMethodsObserver = adyenPaymentService.getPaymentMethods();
                paymentMethodsObserver.subscribe(
                    function(paymentMethodsResponse) {
                        self.enablePaymentMethod(paymentMethodsResponse);
                    }
                );

                if(!!paymentMethodsObserver()) {
                    self.enablePaymentMethod(paymentMethodsObserver());
                }

                paymentComponentStates().initializeState(this.getMethodCode());
            },

            enablePaymentMethod: function (paymentMethodsResponse) {
                if (this.checkBrowserCompatibility() && !!paymentMethodsResponse.paymentMethodsResponse) {
                    this.paymentMethod(
                        adyenPaymentService.getPaymentMethodFromResponse(
                            this.getTxVariant(),
                            paymentMethodsResponse.paymentMethodsResponse.paymentMethods
                        )
                    );

                    if (!!this.paymentMethod()) {
                        this.paymentMethodsExtraInfo(paymentMethodsResponse.paymentMethodsExtraDetails);
                        // Setting the icon and method txvariant as an accessible field if it is available
                        this.adyenPaymentMethod({
                            icon: !!paymentMethodsResponse.paymentMethodsExtraDetails[this.getTxVariant()]
                                ? paymentMethodsResponse.paymentMethodsExtraDetails[this.getTxVariant()].icon
                                : undefined,
                            method: this.getTxVariant()
                        });
                    }

                    fullScreenLoader.stopLoader();
                }
            },

            /*
             * Create generic AdyenCheckout library and mount payment method component
             * after selecting the payment method via overriding parent `selectPaymentMethod()` function.
             */
            selectPaymentMethod: function () {
                this._super();
                this.createCheckoutComponent();
                return true;
            },

            /*
             * Pre-selected payment methods don't trigger parent's `selectPaymentMethod()` function.
             *
             * This function is triggered via `afterRender` attribute of the html template
             * and creates checkout component for pre-selected payment method.
             */
            renderPreSelected: async function () {
                if (this.isChecked() === this.getCode()) {
                    await this.createCheckoutComponent();
                }
            },

            // Build AdyenCheckout library and creates the payment method component
            createCheckoutComponent: async function(forceCreate = false) {
                if (!this.checkoutComponent || forceCreate) {
                    const paymentMethodsResponse = adyenPaymentService.getPaymentMethods();
                    const countryCode = quote.billingAddress().countryId;

                    this.checkoutComponent = await adyenCheckout.buildCheckoutComponent(
                        paymentMethodsResponse(),
                        countryCode,
                        this.handleOnAdditionalDetails.bind(this),
                        this.handleOnCancel.bind(this),
                        this.handleOnSubmit.bind(this),
                        this.handleOnError.bind(this)
                    );

                    this.renderCheckoutComponent();
                }
            },

            handleOnError: function (error, component) {
                this.handleOnFailure(error, component);
            },

            handleOnFailure: function(error, component) {

                paymentComponentStates().setIsPlaceOrderAllowed(this.getMethodCode(), true);

                fullScreenLoader.stopLoader();
                errorProcessor.process(error, this.currentMessageContainer);
            },

            renderCheckoutComponent: function() {
                let methodCode = this.getMethodCode();
                let configuration = this.buildComponentConfiguration(this.paymentMethod(), this.paymentMethodsExtraInfo());

                this.mountPaymentMethodComponent(this.paymentMethod(), configuration, methodCode);
            },

            buildComponentConfiguration: function (paymentMethod, paymentMethodsExtraInfo) {
                let self = this;
                let showPayButton = false;

                let formattedShippingAddress = {};
                let formattedBillingAddress = {};

                if (!quote.isVirtual() && !!quote.shippingAddress()) {
                    formattedShippingAddress = self.getFormattedAddress(quote.shippingAddress());
                }

                if (!!quote.billingAddress()) {
                    formattedBillingAddress = self.getFormattedAddress(quote.billingAddress());
                }
                /* Use the storedPaymentMethod object and the custom onChange function as the configuration object together */
                let configuration = Object.assign(paymentMethod,
                    {
                        showPayButton: showPayButton,
                        countryCode: formattedShippingAddress.country ? formattedShippingAddress.country : formattedBillingAddress.country, // Use shipping address details as default and fall back to billing address if missing
                        data: {},
                        onChange: function (state) {
                            paymentComponentStates().setIsPlaceOrderAllowed(self.getMethodCode(), state.isValid);
                        },
                    });

                return configuration;
            },

            mountPaymentMethodComponent: function(paymentMethod, configuration, methodCode) {
                try {
                    const containerId = '#' + paymentMethod.type + 'Container';

                    this.paymentComponent = adyenCheckout.mountPaymentMethodComponent(
                        this.checkoutComponent,
                        this.getTxVariant(),
                        configuration,
                        containerId
                    );

                    if (this.paymentComponent) {
                        paymentComponentStates().setIsPlaceOrderAllowed(
                            methodCode,
                            this.paymentComponent.isValid
                        );
                    }
                } catch (err) {
                    if ('test' === adyenConfiguration.getCheckoutEnvironment()) {
                        console.error(err);
                    }
                }
            },

            handleOnSubmit: async function(state, component) {
                if (this.validate()) {
                    let data = {};
                    data.method = this.getCode();

                    let additionalData = {};
                    let stateData = component.data;
                    additionalData.stateData = JSON.stringify(stateData);
                    data.additional_data = additionalData;

                    await this.placeRedirectOrder(data, component);
                }

                return false;
            },

            handleOnAdditionalDetails: function(state, component) {
                const self = this;
                // call endpoint with state.data if available
                let request = {};
                if (!!state.data) {
                    request = state.data;
                }

                adyenPaymentService.paymentDetails(request, self.orderId).done(function() {
                    $.mage.redirect(
                        window.checkoutConfig.payment.adyen.successPage,
                    );
                }).fail(function(response) {
                    fullScreenLoader.stopLoader();
                    if (self.popupModal) {
                        self.closeModal(self.popupModal);
                    }
                    errorProcessor.process(response,
                        self.currentMessageContainer);
                    paymentComponentStates().setIsPlaceOrderAllowed(self.getMethodCode(), true);
                });
            },

            handleOnCancel: function(state, component) {
                const self = this;

                // call endpoint with state.data if available
                let request = {};
                if (!!state.data) {
                    request = state.data;
                }

                adyenPaymentService.paymentDetails(request, self.orderId).done(function() {
                    $.mage.redirect(
                        window.checkoutConfig.payment.adyen.successPage
                    );
                }).fail(function(response) {
                    fullScreenLoader.stopLoader();
                    if (self.popupModal) {
                        self.closeModal(self.popupModal);
                    }
                    errorProcessor.process(response, self.currentMessageContainer);
                    paymentComponentStates().setIsPlaceOrderAllowed(self.getMethodCode(), true);
                    self.showErrorMessage(response);
                });
            },

            placeOrder: function() {
                let methodCode = this.getMethodCode();

                if (this.paymentComponent) {
                    this.paymentComponent.showValidation();
                }

                if (this.validate()) {
                    let data = {
                        'method': methodCode
                    };

                    let additionalData = {
                        frontendType: 'default'
                    };

                    let stateData;
                    if (this.paymentComponent) {
                        stateData = this.paymentComponent.data;
                    } else {
                        stateData = {
                            paymentMethod: {
                                type: this.paymentMethod().type,
                            },
                        };
                    }

                    additionalData.stateData = JSON.stringify(stateData);
                    data.additional_data = additionalData;

                    this.placeRedirectOrder(data, this.paymentComponent);
                } else {
                    paymentComponentStates().setIsPlaceOrderAllowed(methodCode, true);
                }

                return false;
            },

            placeRedirectOrder: async function(data, component) {
                const self = this;

                fullScreenLoader.startLoader();
                $('.hpp-message').slideUp();
                paymentComponentStates().setIsPlaceOrderAllowed(this.getMethodCode(), false);

                try {
                    const orderId = await placeOrderAction(data, self.currentMessageContainer);
                    const responseJSON = await adyenPaymentService.getOrderPaymentStatus(orderId);
                    self.validateActionOrPlaceOrder(responseJSON, orderId, component);
                } catch (response) {
                    self.handleOnFailure(response, component);
                }
            },

            getTxVariant: function () {
                return window.checkoutConfig.payment.adyen.txVariants[this.getCode()];
            },

            getMethodCode: function () {
                return this.item.method;
            },

            validate: function() {
                const form = '#adyen-' + this.getTxVariant() + '-form';
                const validate = $(form).validation() && $(form).validation('isValid');
                return validate && additionalValidators.validate();
            },

            showErrorMessage: function(message) {
                messageList.addErrorMessage({
                    message: message
                });
            },

            showPlaceOrderButton: function() {
                return this.placeOrderButtonVisible;
            },

            /**
             * This method is a workaround to close the modal in the right way and reconstruct the ActionModal.
             * This will solve issues when you cancel the 3DS2 challenge and retry the payment
             */
            closeModal: function(popupModal) {
                adyenPaymentModal.closeModal(popupModal, this.modalLabel)
            },

            /**
             * Based on the response we can start a action component or redirect
             * @param responseJSON
             */
            validateActionOrPlaceOrder: function(responseJSON, orderId, component) {
                let self = this;
                let response = JSON.parse(responseJSON);

                if (!!response.isFinal) {
                    // Status is final redirect to the success page
                    $.mage.redirect(
                        window.checkoutConfig.payment.adyen.successPage
                    );
                } else {
                    // render component
                    self.orderId = orderId;
                    self.renderActionComponent(response.resultCode, response.action, component);
                }
            },

            renderActionComponent: function(resultCode, action, component) {
                let self = this;
                let actionNode = document.getElementById(this.modalLabel + 'Content');
                fullScreenLoader.stopLoader();

                if (resultCode !== 'RedirectShopper') {
                    self.popupModal = adyenPaymentModal.showModal(adyenPaymentService, fullScreenLoader, this.messageContainer, this.orderId, this.modalLabel, this.isPlaceOrderActionAllowed)
                    $("." + this.modalLabel + " .action-close").hide();
                }

                self.actionComponent = self.checkoutComponent.createFromAction(action).mount(actionNode);
            },


            isButtonActive: function() {
                return paymentComponentStates().getIsPlaceOrderAllowed(this.getMethodCode());
            },

            /**
             * @param address
             * @returns {{country: (string|*), firstName: (string|*), lastName: (string|*), city: (*|string), street: *, postalCode: (*|string), houseNumber: string, telephone: (string|*)}}
             */
            getFormattedAddress: function(address) {
                function getStreetAndHouseNumberFromAddress(address, houseNumberStreetLine, customerStreetLinesEnabled) {
                    let street = address.street.slice(0, customerStreetLinesEnabled);
                    let drawHouseNumberWithRegex = parseInt(houseNumberStreetLine) === 0 || // Config is disabled
                        houseNumberStreetLine > customerStreetLinesEnabled || // Not enough street lines enabled
                        houseNumberStreetLine > street.length; // House number field is empty

                    let addressArray;
                    if (drawHouseNumberWithRegex) {
                        addressArray = getStreetAndHouseNumberWithRegex(street.join(' ').trim());
                    } else {
                        let houseNumber = street.splice(houseNumberStreetLine - 1, 1);
                        addressArray = {
                            streetName: street.join(' ').trim(),
                            houseNumber: houseNumber.join(' ').trim()
                        }
                    }
                    return addressArray;
                }

                function getStreetAndHouseNumberWithRegex(addressString) {
                    // Match addresses where the street name comes first, e.g. John-Paul's Ave. 1 B
                    const streetFirstRegex = /^(?<streetName>[a-zA-Z0-9.'\- ]{1,100})\s+(?<houseNumber>\d{1,10}((\s)?\w{1,3})?)$/;

                    // Match addresses where the house number comes first, e.g. 10 D John-Paul's Ave.
                    const numberFirstRegex = /^(?<houseNumber>\d{1,10}((\s)?\w{1,3})?)\s+(?<streetName>[a-zA-Z0-9.'\- ]{1,100})$/;

                    const streetFirstAddress = addressString.match(streetFirstRegex);
                    const numberFirstAddress = addressString.match(numberFirstRegex);

                    if (streetFirstAddress) {
                        return streetFirstAddress.groups;
                    } else if (numberFirstAddress) {
                        return numberFirstAddress.groups;
                    }

                    return {
                        streetName: addressString,
                        houseNumber: 'N/A'
                    };
                }

                let street = getStreetAndHouseNumberFromAddress(
                    address,
                    adyenConfiguration.getHouseNumberStreetLine(),
                    adyenConfiguration.getCustomerStreetLinesEnabled()
                );

                return {
                    city: address.city,
                    country: address.countryId,
                    postalCode: address.postcode,
                    street: street.streetName,
                    houseNumber: street.houseNumber,
                    firstName: address.firstname,
                    lastName: address.lastname,
                    telephone: address.telephone
                };
            },

            checkBrowserCompatibility: function () {
                return true;
            },

            getPaymentMethodComponent: function () {
                return this.paymentComponent;
            }
        });
    },
);
