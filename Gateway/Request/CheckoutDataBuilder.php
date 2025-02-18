<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\OpenInvoice;
use Adyen\Payment\Model\Config\Source\ThreeDSFlow;
use Adyen\Payment\Model\Ui\AdyenPayByLinkConfigProvider;
use Adyen\Payment\Observer\AdyenCcDataAssignObserver;
use Adyen\Payment\Observer\AdyenPaymentMethodDataAssignObserver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

class CheckoutDataBuilder implements BuilderInterface
{
    const ADYEN_BOLETO = 'adyen_boleto';
    const ORDER_EMAIL_REQUIRED_METHODS = [
        AdyenPayByLinkConfigProvider::CODE,
        self::ADYEN_BOLETO
    ];

    /**
     * CheckoutDataBuilder constructor.
     *
     * @param Data $adyenHelper
     * @param StateData $stateData
     * @param CartRepositoryInterface $cartRepository
     * @param ChargedCurrency $chargedCurrency
     * @param Config $configHelper
     * @param OpenInvoice $openInvoiceHelper
     */
    public function __construct(
        private readonly Data $adyenHelper,
        private readonly StateData $stateData,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ChargedCurrency $chargedCurrency,
        private readonly Config $configHelper,
        private readonly OpenInvoice $openInvoiceHelper,
        private readonly PaymentMethods $paymentMethodsHelper
    ) { }

    /**
     * @param array $buildSubject
     * @return array
     * @throws NoSuchEntityException|LocalizedException
     */
    public function build(array $buildSubject): array
    {
        /** @var PaymentDataObject $paymentDataObject */
        $paymentDataObject = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        $paymentMethodInstance = $payment->getMethodInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $storeId = $order->getStoreId();

        // Initialize the request body with the current state data
        // Multishipping checkout uses the cc_number field for state data
        $requestBody = $this->stateData->getStateData($order->getQuoteId());

        if (empty($requestBody) && !is_null($payment->getCcNumber())) {
            $requestBody = json_decode((string) $payment->getCcNumber(), true);
        }

        $order->setCanSendNewEmailFlag(in_array($payment->getMethod(), self::ORDER_EMAIL_REQUIRED_METHODS));

        // Additional data for ACH
        if ($payment->getAdditionalInformation("bankAccountNumber")) {
            $requestBody['bankAccount']['bankAccountNumber'] = $payment->getAdditionalInformation("bankAccountNumber");
        }

        if ($payment->getAdditionalInformation("bankLocationId")) {
            $requestBody['bankAccount']['bankLocationId'] = $payment->getAdditionalInformation("bankLocationId");
        }

        if ($payment->getAdditionalInformation("bankAccountOwnerName")) {
            $requestBody['bankAccount']['ownerName'] = $payment->getAdditionalInformation("bankAccountOwnerName");
        }

        $brandCode = $payment->getAdditionalInformation(AdyenPaymentMethodDataAssignObserver::BRAND_CODE);
        if (
            $this->paymentMethodsHelper->isOpenInvoice($paymentMethodInstance) ||
            $payment->getMethod() === AdyenPayByLinkConfigProvider::CODE
        ) {
            $openInvoiceFields = $this->openInvoiceHelper->getOpenInvoiceDataForOrder($order);
            $requestBody = array_merge($requestBody, $openInvoiceFields);

            if (isset($brandCode) &&
                $this->adyenHelper->isPaymentMethodOfType($brandCode, Data::KLARNA) &&
                $this->configHelper->getAutoCaptureOpenInvoice($storeId)) {
                $requestBody['captureDelayHours'] = 0;
            }

            if (
                (isset($brandCode) && $this->adyenHelper->isPaymentMethodOfType($brandCode, Data::KLARNA)) ||
                $payment->getMethod() === AdyenPayByLinkConfigProvider::CODE
            ) {
                $otherDeliveryInformation = $this->getOtherDeliveryInformation($order);
                if (isset($otherDeliveryInformation)) {
                    $requestBody['additionalData']['openinvoicedata.merchantData'] =
                        base64_encode(json_encode($otherDeliveryInformation));
                }
            }
        }

        // Ratepay specific Fingerprint
        $deviceFingerprint = $payment->getAdditionalInformation(AdyenPaymentMethodDataAssignObserver::DF_VALUE);
        if ($deviceFingerprint && $this->adyenHelper->isPaymentMethodOfType($brandCode, Data::RATEPAY)) {
            $requestBody['deviceFingerprint'] = $deviceFingerprint;
        }

        //Boleto data
        if ($payment->getAdditionalInformation("social_security_number")) {
            $requestBody['socialSecurityNumber'] = $payment->getAdditionalInformation("social_security_number");
        }

        if ($payment->getAdditionalInformation("firstname")) {
            $requestBody['shopperName']['firstName'] = $payment->getAdditionalInformation("firstname");
        }

        if ($payment->getAdditionalInformation("lastname")) {
            $requestBody['shopperName']['lastName'] = $payment->getAdditionalInformation("lastname");
        }

        if ($payment->getMethod() == self::ADYEN_BOLETO) {
            $deliveryDays = (int)$this->configHelper->getAdyenBoletoConfigData("delivery_days", $storeId);
            $deliveryDays = (!empty($deliveryDays)) ? $deliveryDays : 5;
            $deliveryDate = date(
                "Y-m-d\TH:i:s ",
                mktime(
                    date("H"),
                    date("i"),
                    date("s"),
                    date("m"),
                    date("j") + $deliveryDays,
                    date("Y")
                )
            );

            $requestBody['deliveryDate'] = $deliveryDate;
        }

        // if installments is set and card type is credit card add it into the request
        $numberOfInstallments = $payment->getAdditionalInformation(
            AdyenCcDataAssignObserver::NUMBER_OF_INSTALLMENTS
        ) ?: 0;
        $comboCardType = $payment->getAdditionalInformation(AdyenCcDataAssignObserver::COMBO_CARD_TYPE) ?: 'credit';
        if ($numberOfInstallments > 0) {
            $requestBody['installments']['value'] = $numberOfInstallments;
        }

        /*
         * if the combo card type is debit then add the funding source
         * and unset the installments & brand fields
         */
        if ($comboCardType == 'debit') {
            $requestBody['paymentMethod']['fundingSource'] = 'debit';
            unset($requestBody['paymentMethod']['brand']);
            unset($requestBody['installments']);
        }

        $threeDSFlow = $this->configHelper->getThreeDSFlow($order->getStoreId());
        $requestBody['authenticationData']['threeDSRequestData']['nativeThreeDS'] =
            $threeDSFlow === ThreeDSFlow::THREEDS_NATIVE ?
                ThreeDSFlow::THREEDS_PREFERRED :
                ThreeDSFlow::THREEDS_DISABLED;

        return [
            'body' => $requestBody
        ];
    }

    /**
     * @param Order $order
     * @return array|null
     */
    private function getOtherDeliveryInformation(Order $order): ?array
    {
        $shippingAddress = $order->getShippingAddress();

        if ($shippingAddress) {
            $otherDeliveryInformation = [
                "shipping_method" => $order->getShippingMethod(),
                "first_name" => $order->getCustomerFirstname(),
                "last_name" => $order->getCustomerLastname(),
                "street_address" => implode(' ', $shippingAddress->getStreet()),
                "postal_code" => $shippingAddress->getPostcode(),
                "city" => $shippingAddress->getCity(),
                "country" => $shippingAddress->getCountryId()
            ];
        }

        return $otherDeliveryInformation ?? null;
    }

    /**
     * @param string $item
     * @return string
     */
    protected function getImageUrl($item): string
    {
        $product = $item->getProduct();
        $imageUrl = "";

        if ($image = $product->getSmallImage()) {
            $imageUrl = $this->imageHelper->init($product, 'product_page_image_small')
                ->setImageFile($image)
                ->getUrl();
        }

        return $imageUrl;
    }

    /**
     * @deprecated Use Adyen\Payment\Helper\OpenInvoice::getOpenInvoiceDataForOrder() instead.
     *
     * @param Order $order
     *
     * @return array
     * @throws NoSuchEntityException
     *
     */
    protected function getOpenInvoiceData($order): array
    {
        $formFields = [
            'lineItems' => []
        ];

        /** @var Quote $cart */
        $cart = $this->cartRepository->get($order->getQuoteId());
        $amountCurrency = $this->chargedCurrency->getOrderAmountCurrency($order);
        $currency = $amountCurrency->getCurrencyCode();
        $discountAmount = 0;

        foreach ($cart->getAllVisibleItems() as $item) {
            $numberOfItems = (int)$item->getQty();

            $itemAmountCurrency = $this->chargedCurrency->getQuoteItemAmountCurrency($item);

            // Summarize the discount amount item by item
            $discountAmount += $itemAmountCurrency->getDiscountAmount();

            $formattedPriceExcludingTax = $this->adyenHelper->formatAmount(
                $itemAmountCurrency->getAmount(),
                $itemAmountCurrency->getCurrencyCode()
            );

            $formattedPriceIncludingTax = $this->adyenHelper->formatAmount(
                $itemAmountCurrency->getAmountIncludingTax(),
                $itemAmountCurrency->getCurrencyCode()
            );

            $formattedTaxAmount = $this->adyenHelper->formatAmount(
                $itemAmountCurrency->getTaxAmount(),
                $itemAmountCurrency->getCurrencyCode()
            );

            $formattedTaxPercentage = $this->adyenHelper->formatAmount($item->getTaxPercent(), $currency);

            $formFields['lineItems'][] = [
                'id' => $item->getId(),
                'amountExcludingTax' => $formattedPriceExcludingTax,
                'amountIncludingTax' => $formattedPriceIncludingTax,
                'taxAmount' => $formattedTaxAmount,
                'description' => $item->getName(),
                'quantity' => $numberOfItems,
                'taxPercentage' => $formattedTaxPercentage,
                'productUrl' => $item->getProduct()->getUrlModel()->getUrl($item->getProduct()),
                'imageUrl' => $this->getImageUrl($item)
            ];
        }

        // Discount cost
        if ($discountAmount != 0) {
            $description = __('Discount');
            $itemAmount = -$this->adyenHelper->formatAmount(
                $discountAmount + $cart->getShippingAddress()->getShippingDiscountAmount(),
                $itemAmountCurrency->getCurrencyCode()
            );
            $itemVatAmount = "0";
            $itemVatPercentage = "0";
            $numberOfItems = 1;

            $formFields['lineItems'][] = [
                'id' => 'Discount',
                'amountExcludingTax' => $itemAmount,
                'amountIncludingTax' => $itemAmount,
                'taxAmount' => $itemVatAmount,
                'description' => $description,
                'quantity' => $numberOfItems,
                'taxPercentage' => $itemVatPercentage
            ];
        }

        // Shipping cost
        if ($cart->getShippingAddress()->getShippingAmount() > 0 ||
            $cart->getShippingAddress()->getShippingTaxAmount() > 0
        ) {
            $shippingAmountCurrency = $this->chargedCurrency->getQuoteShippingAmountCurrency($cart);

            $formattedPriceExcludingTax = $this->adyenHelper->formatAmount(
                $shippingAmountCurrency->getAmount(),
                $currency
            );

            $formattedPriceIncludingTax = $this->adyenHelper->formatAmount(
                $shippingAmountCurrency->getAmountIncludingTax(),
                $currency
            );

            $formattedTaxAmount = $this->adyenHelper->formatAmount(
                $shippingAmountCurrency->getTaxAmount(),
                $currency
            );

            $formFields['lineItems'][] = [
                'id' => 'shippingCost',
                'amountExcludingTax' => $formattedPriceExcludingTax,
                'amountIncludingTax' => $formattedPriceIncludingTax,
                'taxAmount' => $formattedTaxAmount,
                'description' => $order->getShippingDescription(),
                'quantity' => 1,
                'taxPercentage' => (int) round(($formattedTaxAmount / $formattedPriceExcludingTax) * 100 * 100)
            ];
        }

        return $formFields;
    }
}
