<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper;

use Adyen\Payment\Model\Config\Source\CcType;
use Adyen\Payment\Model\Ui\AdyenPayByLinkConfigProvider;
use Adyen\Util\Uuid;
use Magento\Framework\App\Helper\AbstractHelper;

class Requests extends AbstractHelper
{
    const MERCHANT_ACCOUNT = 'merchantAccount';
    const SHOPPER_REFERENCE = 'shopperReference';
    const RECURRING_DETAIL_REFERENCE = 'recurringDetailReference';
    const DONATION_PAYMENT_METHOD_CODE_MAPPING = [
        'ideal' => 'sepadirectdebit',
        'storedPaymentMethods' => 'scheme',
        'googlepay' => 'scheme',
        'paywithgoogle' => 'scheme',
    ];
    const SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';

    /**
     * @var Data
     */
    private $adyenHelper;

    /**
     * @var Config
     */
    private $adyenConfig;

    /**
     * @var Address
     */
    private $addressHelper;

    /**
     * @var StateData
     */
    private $stateData;

    /**
     * Requests constructor.
     *
     * @param Data $adyenHelper
     * @param Config $adyenConfig
     * @param Address $addressHelper
     * @param StateData $stateData
     */
    public function __construct(
        Data $adyenHelper,
        Config $adyenConfig,
        Address $addressHelper,
        StateData $stateData
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->adyenConfig = $adyenConfig;
        $this->addressHelper = $addressHelper;
        $this->stateData = $stateData;
    }

    /**
     * @param $request
     * @param $paymentMethod
     * @param $storeId
     * @return mixed
     */
    public function buildMerchantAccountData($paymentMethod, $storeId, $request = [])
    {
        // Retrieve merchant account
        $merchantAccount = $this->adyenHelper->getAdyenMerchantAccount($paymentMethod, $storeId);

        // Assign merchant account to request object
        $request[self::MERCHANT_ACCOUNT] = $merchantAccount;

        return $request;
    }

    /**
     * @param $motoMerchantAccount
     * @return array
     */
    public function buildMotoMerchantAccountData($motoMerchantAccount)
    {
        // Assign merchant account to request object
        return [
            self::MERCHANT_ACCOUNT => $motoMerchantAccount
        ];
    }

    /**
     * @param int $customerId
     * @param $billingAddress
     * @param $storeId
     * @param \Magento\Sales\Model\Order\Payment\|null $payment
     * @param null $additionalData
     * @param array $request
     * @return array
     * @return array
     */
    public function buildCustomerData(
        $billingAddress,
        $storeId,
        $customerId = 0,
        $payment = null,
        $additionalData = null,
        $request = []
    ) {
        $request['shopperReference'] = $this->getShopperReference($customerId, $payment->getOrder()->getIncrementId());

        // In case of virtual product and guest checkout there is a workaround to get the guest's email address
        if (!empty($additionalData['guestEmail'])) {
            $request['shopperEmail'] = $additionalData['guestEmail'];
        }

        if (!empty($billingAddress)) {
            if ($customerEmail = $billingAddress->getEmail()) {
                $request['shopperEmail'] = $customerEmail;
            }

            // /paymentLinks is not accepting "telephoneNumber" - FOC-47179
            if (
                $payment->getMethodInstance()->getCode() != AdyenPayByLinkConfigProvider::CODE &&
                !is_null($billingAddress->getTelephone())
            ) {
                $request['telephoneNumber'] = trim($billingAddress->getTelephone());
            }

            if ($firstName = $billingAddress->getFirstname()) {
                $request['shopperName']['firstName'] = $firstName;
            }

            if ($lastName = $billingAddress->getLastname()) {
                $request['shopperName']['lastName'] = $lastName;
            }

            if ($countryId = $billingAddress->getCountryId()) {
                $request['countryCode'] = $this->addressHelper->getAdyenCountryCode($countryId);
            }

            $request['shopperLocale'] = $this->adyenHelper->getStoreLocale($storeId);
        }

        return $request;
    }

    /**
     * @param $request
     * @param $ipAddress
     * @return mixed
     */
    public function buildCustomerIpData($ipAddress, $request = [])
    {
        $request['shopperIP'] = $ipAddress;

        return $request;
    }

    /**
     * @param $request
     * @param $billingAddress
     * @param $shippingAddress
     * @return mixed
     */
    public function buildAddressData($billingAddress, $shippingAddress, $storeId, $request = [])
    {
        if ($billingAddress) {
            // Billing address defaults
            $requestBillingDefaults = [
                "street" => "N/A",
                "postalCode" => '',
                "city" => "N/A",
                "houseNumberOrName" => '',
                "country" => "ZZ"
            ];

            // Save the defaults for later to compare if anything has changed
            $requestBilling = $requestBillingDefaults;

            $houseNumberStreetLine = $this->adyenHelper->getAdyenAbstractConfigData(
                Config::XML_HOUSE_NUMBER_STREET_LINE,
                $storeId
            );

            $customerStreetLinesEnabled = $this->adyenHelper->getCustomerStreetLinesEnabled($storeId);

            $address = $this->addressHelper->getStreetAndHouseNumberFromAddress(
                $billingAddress,
                $houseNumberStreetLine,
                $customerStreetLinesEnabled
            );

            if (!empty($address["name"])) {
                $requestBilling["street"] = $address["name"];
            }

            if (!empty($address["house_number"])) {
                $requestBilling["houseNumberOrName"] = $address["house_number"];
            }

            if (!empty($billingAddress->getCity())) {
                $requestBilling["city"] = $billingAddress->getCity();
            }

            if (!empty($billingAddress->getRegionCode())) {
                $requestBilling["stateOrProvince"] = $billingAddress->getRegionCode();
            }

            if (!empty($billingAddress->getCountryId())) {
                $requestBilling["country"] = $this->addressHelper->getAdyenCountryCode(
                    $billingAddress->getCountryId()
                );
            }

            if (!empty($billingAddress->getPostcode())) {
                $requestBilling["postalCode"] = $billingAddress->getPostcode();
                if ($billingAddress->getCountryId() == "BR") {
                    $requestBilling["postalCode"] = preg_replace(
                        '/[^\d]/',
                        '',
                        $requestBilling["postalCode"]
                    );
                }
            }

            // If nothing is changed which means delivery address is not filled
            if ($requestBilling !== $requestBillingDefaults) {
                $request['billingAddress'] = $requestBilling;
            }
        }

        if ($shippingAddress) {
            // Delivery address defaults
            $requestDeliveryDefaults = [
                "street" => "N/A",
                "postalCode" => '',
                "city" => "N/A",
                "houseNumberOrName" => '',
                "country" => "ZZ"
            ];

            // Save the defaults for later to compare if anything has changed
            $requestDelivery = $requestDeliveryDefaults;

            // Parse address into street and house number where possible
            $address = $this->addressHelper->getStreetAndHouseNumberFromAddress(
                $shippingAddress,
                $houseNumberStreetLine,
                $customerStreetLinesEnabled
            );

            if (!empty($address['name'])) {
                $requestDelivery["street"] = $address["name"];
            }

            if (!empty($address["house_number"])) {
                $requestDelivery["houseNumberOrName"] = $address["house_number"];
            }

            if (!empty($shippingAddress->getCity())) {
                $requestDelivery["city"] = $shippingAddress->getCity();
            }

            if (!empty($shippingAddress->getRegionCode())) {
                $requestDelivery["stateOrProvince"] = $shippingAddress->getRegionCode();
            }

            if (!empty($shippingAddress->getCountryId())) {
                $requestDelivery["country"] = $this->addressHelper->getAdyenCountryCode(
                    $shippingAddress->getCountryId()
                );
            }

            if (!empty($shippingAddress->getPostcode())) {
                $requestDelivery["postalCode"] = $shippingAddress->getPostcode();
                if ($shippingAddress->getCountryId() == "BR") {
                    $requestDelivery["postalCode"] = preg_replace(
                        '/[^\d]/',
                        '',
                        $requestDelivery["postalCode"]
                    );
                }
            }

            // If nothing is changed which means delivery address is not filled
            if ($requestDelivery !== $requestDeliveryDefaults) {
                $request['deliveryAddress'] = $requestDelivery;
            }
        }

        return $request;
    }

    /**
     * @param array $request
     * @param $amount
     * @param $currencyCode
     * @param $reference
     * @return array
     */
    public function buildPaymentData($amount, $currencyCode, $reference, array $request = [])
    {
        $request['amount'] = [
            'currency' => $currencyCode,
            'value' => $this->adyenHelper->formatAmount($amount, $currencyCode)
        ];

        $request["reference"] = $reference;

        return $request;
    }

    /**
     * @param array $request
     * @return array
     */
    public function buildBrowserData($request = [])
    {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $request['browserInfo']['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        if (!empty($_SERVER['HTTP_ACCEPT'])) {
            $request['browserInfo']['acceptHeader'] = $_SERVER['HTTP_ACCEPT'];
        }

        return $request;
    }

    /**
     * Build the recurring data when payment is done using a card
     *
     * @param int $storeId
     * @param $payment
     * @return array
     */
    public function buildCardRecurringData(int $storeId, $payment): array
    {
        $request = [];

        if (!$this->adyenConfig->getCardRecurringActive($storeId)) {
            return $request;
        }

        $storePaymentMethod = false;
        $order = $payment->getOrder();

        // Initialize the request body with the current state data
        // Multishipping checkout uses the cc_number field for state data
        $stateData = $order->getQuoteId() ? $this->stateData->getStateData((int)$order->getQuoteId()) : [];
        if (!$stateData) {
            $stateData = json_decode($payment->getCcNumber(), true) ?: [];
        }

        // If PayByLink
        // Else, if option to store token exists, get the value from the checkbox
        if ($payment->getMethod() === AdyenPayByLinkConfigProvider::CODE) {
            $request['storePaymentMethodMode'] = 'askForConsent';
        } elseif (array_key_exists('storePaymentMethod', $stateData)) {
            $storePaymentMethod = $stateData['storePaymentMethod'];
            $request['storePaymentMethod'] = $storePaymentMethod;
        }

        if ($storePaymentMethod) {
            $recurringProcessingModel = $payment->getAdditionalInformation('recurringProcessingModel');

            if (isset($recurringProcessingModel)) {
                $request['recurringProcessingModel'] = $recurringProcessingModel;
            } else {
                $request['recurringProcessingModel'] = $this->adyenConfig->getCardRecurringType($storeId);
            }
        }

        return $request;
    }

    /**
     * Build the recurring data to be sent in case of an Adyen Tokenized payment.
     * Model will be fetched according to the type (card/other pm) of the original payment
     *
     * @param int $storeId
     * @param $payment
     * @return array
     */
    public function buildAdyenTokenizedPaymentRecurringData(int $storeId, $payment): array
    {
        $request = [];

        $recurringProcessingModel = $payment->getAdditionalInformation('recurringProcessingModel');

        if (isset($recurringProcessingModel)) {
            $request['recurringProcessingModel'] = $recurringProcessingModel;
        } else {
            if (in_array($payment->getAdditionalInformation('cc_type'), CcType::ALLOWED_TYPES)) {
                $recurringProcessingModel = $this->adyenConfig->getCardRecurringType($storeId);
                $request['recurringProcessingModel'] = $recurringProcessingModel;
            } else {
                $request['recurringProcessingModel'] =
                    $this->adyenConfig->getAlternativePaymentMethodTokenType($storeId);
            }
        }

        return $request;
    }

    public function buildDonationData($buildSubject, $storeId): array
    {
        $paymentMethodCode = $buildSubject['paymentMethod'];

        if (isset(self::DONATION_PAYMENT_METHOD_CODE_MAPPING[$paymentMethodCode])) {
            $paymentMethodCode = self::DONATION_PAYMENT_METHOD_CODE_MAPPING[$paymentMethodCode];
        }

        return [
            'amount' => $buildSubject['amount'],
            'reference' => Uuid::generateV4(),
            'shopperReference' => $buildSubject['shopperReference'],
            'paymentMethod' => [
                'type' => $paymentMethodCode
            ],
            'donationToken' => $buildSubject['donationToken'],
            'donationOriginalPspReference' => $buildSubject['donationOriginalPspReference'],
            'donationAccount' => $this->adyenConfig->getCharityMerchantAccount($storeId),
            'returnUrl' => $buildSubject['returnUrl'],
            'merchantAccount' => $this->adyenHelper->getAdyenMerchantAccount('adyen_giving', $storeId),
            'shopperInteraction' => self::SHOPPER_INTERACTION_CONTAUTH
        ];
    }

    /**
     * @param string|null $customerId
     * @param string $orderIncrementId
     * @return string
     */
    public function getShopperReference($customerId, $orderIncrementId): string
    {
        if ($customerId) {
            $shopperReference = $this->adyenHelper->padShopperReference($customerId);
        } else {
            $uuid = Uuid::generateV4();
            $guestCustomerId = $orderIncrementId . $uuid;
            $shopperReference = $guestCustomerId;
        }

        return $shopperReference;
    }
}
