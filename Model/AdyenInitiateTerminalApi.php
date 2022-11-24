<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2018 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model;

use Adyen\Payment\Api\AdyenInitiateTerminalApiInterface;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Model\Ui\AdyenPosCloudConfigProvider;
use Magento\Quote\Model\Quote;

/** @deprecated v9: Identical functionality is in Helper\PointofSale and TransactionPosCloudSync */
class AdyenInitiateTerminalApi implements AdyenInitiateTerminalApiInterface
{
    /**
     * @var \Adyen\Payment\Helper\Data
     */
    private $adyenHelper;

    /**
     * @var \Adyen\Payment\Logger\AdyenLogger
     */
    private $adyenLogger;

    /**
     * @var \Adyen\Client
     */
    protected $client;

    /**
     * @var int
     */
    protected $storeId;

    /** @var int */
    protected $timeout;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var ChargedCurrency
     */
    private $chargedCurrency;

    /**
     * @var \Adyen\Payment\Helper\Config
     */
    private $configHelper;

    /**
     * AdyenInitiateTerminalApi constructor.
     *
     * @param \Adyen\Payment\Helper\Data $adyenHelper
     * @param \Adyen\Payment\Logger\AdyenLogger $adyenLogger
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     * @param array $data
     * @param ChargedCurrency $chargedCurrency
     * @throws \Adyen\AdyenException
     */
    public function __construct(
        \Adyen\Payment\Helper\Data $adyenHelper,
        \Adyen\Payment\Helper\Config $configHelper,
        \Adyen\Payment\Logger\AdyenLogger $adyenLogger,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        ChargedCurrency $chargedCurrency,
        array $data = []
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->configHelper = $configHelper;
        $this->adyenLogger = $adyenLogger;
        $this->checkoutSession = $checkoutSession;
        $this->productMetadata = $productMetadata;
        $this->chargedCurrency = $chargedCurrency;
        $this->storeId = $storeManager->getStore()->getId();

        // initialize client
        $apiKey = $this->adyenHelper->getPosApiKey($this->storeId);
        $client = $this->adyenHelper->initializeAdyenClient($this->storeId, $apiKey);

        //Set configurable option in M2
        $this->timeout = $this->configHelper->getAdyenPosCloudConfigData('total_timeout', $this->storeId);
        if (!empty($this->timeout)) {
            $client->setTimeout($this->timeout);
        }

        $this->client = $client;
    }

    /**
     * Trigger sync call on terminal
     *
     * @return mixed
     * @throws \Exception
     */
    public function initiate($payload)
    {
        // Decode payload from frontend
        $payload = json_decode($payload, true);

        // Validate JSON that has just been parsed if it was in a valid format
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Terminal API initiate request was not a valid JSON')
            );
        }

        if (empty($payload['terminal_id'])) {
            throw new \Adyen\AdyenException("Terminal ID is empty in initiate request");
        }

        $poiId = $payload['terminal_id'];

        $quote = $this->checkoutSession->getQuote();
        $payment = $quote->getPayment();
        $adyenAmountCurrency = $this->chargedCurrency->getQuoteAmountCurrency($quote);
        $payment->setMethod(AdyenPosCloudConfigProvider::CODE);
        $reference = $quote->reserveOrderId()->getReservedOrderId();

        $service = $this->adyenHelper->createAdyenPosPaymentService($this->client);
        $transactionType = \Adyen\TransactionType::NORMAL;

        $serviceID = date("dHis");
        $initiateDate = date("U");
        $timeStamper = date("Y-m-d") . "T" . date("H:i:s+00:00");

        $request = [
            'SaleToPOIRequest' =>
                [
                    'MessageHeader' =>
                        [
                            'MessageType' => 'Request',
                            'MessageClass' => 'Service',
                            'MessageCategory' => 'Payment',
                            'SaleID' => 'Magento2Cloud',
                            'POIID' => $poiId,
                            'ProtocolVersion' => '3.0',
                            'ServiceID' => $serviceID
                        ],
                    'PaymentRequest' =>
                        [
                            'SaleData' =>
                                [
                                    'TokenRequestedType' => 'Customer',
                                    'SaleTransactionID' =>
                                        [
                                            'TransactionID' => $reference,
                                            'TimeStamp' => $timeStamper
                                        ]
                                ],
                            'PaymentTransaction' =>
                                [
                                    'AmountsReq' =>
                                        [
                                            'Currency' => $adyenAmountCurrency->getCurrencyCode(),
                                            'RequestedAmount' => doubleval($adyenAmountCurrency->getAmount())
                                        ]
                                ]
                        ]
                ]
        ];

        if (!empty($payload['number_of_installments'])) {
            $request['SaleToPOIRequest']['PaymentRequest']['PaymentData'] = [
                "PaymentType" => "Instalment",
                "Instalment" => [
                    "InstalmentType" => "EqualInstalments",
                    "SequenceNumber" => 1,
                    "Period" => 1,
                    "PeriodUnit" => "Monthly",
                    "TotalNbOfPayments" => (int)$payload['number_of_installments']
                ]
            ];

            $request['SaleToPOIRequest']['PaymentRequest']['PaymentTransaction']['TransactionConditions'] = [
                "DebitPreferredFlag" => false
            ];
        } else {
            $request['SaleToPOIRequest']['PaymentData'] = [
                'PaymentType' => $transactionType,
            ];
        }

        $request = $this->addSaleToAcquirerData($request, $quote);

        $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation(
            'serviceID',
            $serviceID
        );
        $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation(
            'initiateDate',
            $initiateDate
        );

        try {
            $response = $service->runTenderSync($request);
        } catch (\Adyen\AdyenException $e) {
            //Not able to perform a payment
            $this->adyenLogger->addAdyenDebug($e->getMessage());
            $response['error'] = $e->getMessage();
        } catch (\Exception $e) {
            //Probably timeout
            $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation(
                'terminalResponse',
                null
            );
            $quote->save();
            $response['error'] = $e->getMessage();
            throw $e;
        }
        $quote->getPayment()->getMethodInstance()->getInfoInstance()->setAdditionalInformation(
            'terminalResponse',
            $response
        );

        $quote->save();
        return $response;
    }

    /**
     * This getter makes it possible to overwrite the customer id from other plugins
     * Use this function to get the customer id so we can keep using this plugin in the UCD
     *
     * @param Quote $quote
     * @return mixed
     */
    public function getCustomerId(Quote $quote)
    {
        return $quote->getCustomerId();
    }

    /**
     * Add SaleToAcquirerData for storing for recurring transactions and able to track platform and version
     * When upgrading to new version of library we can use the client methods
     *
     * @param $request
     * @param $quote
     * @return mixed
     */
    public function addSaleToAcquirerData($request, $quote)
    {
        $customerId = $this->getCustomerId($quote);

        $saleToAcquirerData = [];

        // If customer exists add it into the request to store request
        if (!empty($customerId)) {
            $shopperEmail = $quote->getCustomerEmail();
            $recurringContract = $this->configHelper->getAdyenPosCloudConfigData('recurring_type', $this->storeId);

            if (!empty($recurringContract) && !empty($shopperEmail)) {
                $saleToAcquirerData['shopperEmail'] = $shopperEmail;
                $saleToAcquirerData['shopperReference'] = $this->adyenHelper->padShopperReference($customerId);
                $saleToAcquirerData['recurringContract'] = $recurringContract;
            }
        }

        $saleToAcquirerData[ApplicationInfo::APPLICATION_INFO][ApplicationInfo::MERCHANT_APPLICATION]
        [ApplicationInfo::VERSION] = $this->adyenHelper->getModuleVersion();
        $saleToAcquirerData[ApplicationInfo::APPLICATION_INFO][ApplicationInfo::MERCHANT_APPLICATION]
        [ApplicationInfo::NAME] = $this->adyenHelper->getModuleName();
        $saleToAcquirerData[ApplicationInfo::APPLICATION_INFO][ApplicationInfo::EXTERNAL_PLATFORM]
        [ApplicationInfo::VERSION] = $this->productMetadata->getVersion();
        $saleToAcquirerData[ApplicationInfo::APPLICATION_INFO][ApplicationInfo::EXTERNAL_PLATFORM]
        [ApplicationInfo::NAME] = $this->productMetadata->getName();
        $saleToAcquirerDataBase64 = base64_encode(json_encode($saleToAcquirerData));
        $request['SaleToPOIRequest']['PaymentRequest']['SaleData']['SaleToAcquirerData'] = $saleToAcquirerDataBase64;
        return $request;
    }
}
