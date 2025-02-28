define([
    'ko',
    'jquery'
], function (ko, $) {
    'use strict';

    return {
        /**
         * Formats and filters installment options based on the order total.
         * @param {Object} installmentsConfig - Installment configuration received from backend.
         * @param {Object} ccAvailableTypesByAlt - Mapping of card types to alternative codes.
         * @param {number} grandTotal - The total order amount.
         * @returns {Object} - Filtered and formatted installment configuration.
         */
        formatInstallmentsConfig: function (installmentsConfig, ccAvailableTypesByAlt, quoteAmount) {
            if (!installmentsConfig || Object.keys(installmentsConfig).length === 0) {
                return '{}';
            }

            let formattedConfig = {};

            $.each(installmentsConfig, function (card, cardInstallments) {
                let values = [1]; // Always allow full payment

                $.each(cardInstallments, function (minimumAmount, installments) {
                    minimumAmount = parseFloat(minimumAmount); // Ensure numeric comparison

                    if (quoteAmount >= minimumAmount) {
                        installments.forEach(function (installment) {
                            values.push(parseInt(installment, 10));
                        });
                    }
                });

                // Ensure unique values and map to alternative card codes
                if (ccAvailableTypesByAlt[card] && ccAvailableTypesByAlt[card]['code_alt']) {
                    formattedConfig[ccAvailableTypesByAlt[card]['code_alt']] = {
                        values: Array.from(new Set(values))
                    };
                }
            });

            return formattedConfig;
        },

        /**
         * Gets installment options with calculated prices.
         * @param {Object} allInstallments - Preformatted installment options.
         * @param {number} grandTotal - Total order amount.
         * @param {number} precision - Number precision.
         * @param {string} currencyCode - Currency code.
         * @returns {Array} - List of available installments with formatted prices.
         */
        getInstallmentsWithPrices: function (allInstallments, grandTotal, precision, currencyCode) {
            let numberOfInstallments = [];
            let dividedAmount = 0;
            let dividedString = "";
            //amount is a minimum amount
            $.each(allInstallments, function (amount, installmentOptions) {
                $.each(installmentOptions, function (key, installment) {
                    if (grandTotal >= amount) {
                        dividedAmount = (grandTotal / installment).toFixed(precision);
                        dividedString = installment + " x " + dividedAmount + " " + currencyCode;
                        numberOfInstallments.push({
                            key: [dividedString],
                            value: installment
                        });
                    }
                });
            });

            return numberOfInstallments;
        }
    };
});
