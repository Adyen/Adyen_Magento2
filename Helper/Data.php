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

namespace Adyen\Payment\Helper;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Environment;
use Adyen\Model\Checkout\ApplicationInfo;
use Adyen\Model\Checkout\CommonField;
use Adyen\Model\Checkout\UtilityRequest;
use Adyen\Payment\Gateway\Request\Header\HeaderDataBuilderInterface;
use Adyen\Payment\Helper\Config as ConfigHelper;
use Adyen\Service\Checkout;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Config\Source\RenderMode;
use Adyen\Payment\Model\RecurringType;
use Adyen\Payment\Model\ResourceModel\Notification\CollectionFactory as NotificationCollectionFactory;
use Adyen\Payment\Observer\AdyenPaymentMethodDataAssignObserver;
use Adyen\Service\Checkout\ModificationsApi;
use Adyen\Service\Checkout\OrdersApi;
use Adyen\Service\Checkout\PaymentLinksApi;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Service\Checkout\UtilityApi;
use Adyen\Service\PosPayment;
use Adyen\Service\Recurring;
use Adyen\Service\RecurringApi;
use DateTime;
use Exception;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Config\DataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\File;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Asset\Source;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Service\OrderService;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;
use Magento\Framework\App\Request\Http;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Data extends AbstractHelper
{
    const MODULE_NAME = 'adyen-magento2';
    const APPLICATION_NAME = 'Magento 2 plugin';
    const TEST = 'test';
    const LIVE = 'live';
    const LIVE_AU = 'live-au';
    const LIVE_US = 'live-us';
    const LIVE_IN = 'live-in';
    const PSP_REFERENCE_REGEX = '/(?P<pspReference>[0-9.A-Z]{16})(?P<suffix>[a-z\-]*)/';
    const AFTERPAY = 'afterpay';
    const AFTERPAY_TOUCH = 'afterpaytouch';
    const KLARNA = 'klarna';
    const RATEPAY = 'ratepay';
    const FACILYPAY = 'facilypay_';
    const AFFIRM = 'affirm';
    const CLEARPAY = 'clearpay';
    const ZIP = 'zip';
    const PAYBRIGHT = 'paybright';
    const SEPA = 'sepadirectdebit';
    const MOLPAY = 'molpay_';
    const ATOME = 'atome';
    const WALLEYB2B = 'walley_b2b';
    const WALLEY = 'walley';


    /**
     * @var EncryptorInterface
     */
    protected $_encryptor;

    /**
     * @var DataInterface
     */
    protected $_dataStorage;

    /**
     * @var Country
     */
    protected $_country;

    /**
     * @var ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @var Repository
     */
    protected $_assetRepo;

    /**
     * @var Source
     */
    protected $_assetSource;

    /**
     * @var NotificationCollectionFactory
     */
    protected $_notificationFactory;

    /**
     * @var Config
     */
    protected $_taxConfig;

    /**
     * @var Calculation
     */
    protected $_taxCalculation;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var AdyenLogger
     */
    protected $adyenLogger;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var ResolverInterface
     */
    private $localeResolver;

    /**
     * @var ScopeConfigInterface
     */
    private $config;

    /**
     * @var ComponentRegistrarInterface
     */
    private $componentRegistrar;

    /**
     * @var Locale;
     */
    private $localeHelper;

    /**
     * @var OrderService
     */
    private $orderManagement;

    /**
     * @var HistoryFactory
     */
    private $orderStatusHistoryFactory;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var BackendHelper
     */
    private $backendHelper;

    /**
     * @var Http
     */
    private Http $request;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        DataInterface $dataStorage,
        Country $country,
        ModuleListInterface $moduleList,
        Repository $assetRepo,
        Source $assetSource,
        NotificationCollectionFactory $notificationFactory,
        Config $taxConfig,
        Calculation $taxCalculation,
        BackendHelper $backendHelper,
        ProductMetadataInterface $productMetadata,
        AdyenLogger $adyenLogger,
        StoreManagerInterface $storeManager,
        CacheInterface $cache,
        ResolverInterface $localeResolver,
        ScopeConfigInterface $config,
        ComponentRegistrarInterface $componentRegistrar,
        Locale $localeHelper,
        OrderManagementInterface $orderManagement,
        HistoryFactory $orderStatusHistoryFactory,
        ConfigHelper $configHelper,
        HTTP $request
    ) {
        parent::__construct($context);
        $this->_encryptor = $encryptor;
        $this->_dataStorage = $dataStorage;
        $this->_country = $country;
        $this->_moduleList = $moduleList;
        $this->_assetRepo = $assetRepo;
        $this->_assetSource = $assetSource;
        $this->_notificationFactory = $notificationFactory;
        $this->_taxConfig = $taxConfig;
        $this->_taxCalculation = $taxCalculation;
        $this->backendHelper = $backendHelper;
        $this->productMetadata = $productMetadata;
        $this->adyenLogger = $adyenLogger;
        $this->storeManager = $storeManager;
        $this->cache = $cache;
        $this->localeResolver = $localeResolver;
        $this->config = $config;
        $this->componentRegistrar = $componentRegistrar;
        $this->localeHelper = $localeHelper;
        $this->orderManagement = $orderManagement;
        $this->orderStatusHistoryFactory = $orderStatusHistoryFactory;
        $this->configHelper = $configHelper;
        $this->request = $request;
    }

    /**
     * return recurring types for configuration setting
     *
     * @return array
     */
    public function getRecurringTypes()
    {
        return [
            RecurringType::ONECLICK => 'ONECLICK',
            RecurringType::ONECLICK_RECURRING => 'ONECLICK,RECURRING',
            RecurringType::RECURRING => 'RECURRING'
        ];
    }

    /**
     * return Checkout frontend regions for configuration setting
     *
     * @return array
     */
    public function getCheckoutFrontendRegions()
    {
        return [
            'eu' => 'Default (EU - Europe)',
            'au' => 'AU - Australasia',
            'us' => 'US - United States',
            'in' => 'IN - India',
            'apse' => 'APSE - Asia Pacific Southeast'
        ];
    }

    /**
     * return capture modes for configuration setting
     *
     * @return array
     */
    public function getCaptureModes()
    {
        return [
            'auto' => 'Immediate',
            'manual' => 'Manual'
        ];
    }

    public function getOpenInvoiceCaptureModes()
    {
        return [
            'auto' => 'Immediate',
            'manual' => 'Manual',
            'onshipment' => 'On shipment'
        ];
    }

    /**
     * return payment routines for configuration setting
     *
     * @return array
     */
    public function getPaymentRoutines()
    {
        return [
            'single' => 'Single Page Payment Routine',
            'multi' => 'Multi-page Payment Routine'
        ];
    }

    /**
     * Return the number of decimals for the specified currency
     *
     * @param $currency
     * @return int
     */
    public function decimalNumbers($currency)
    {
        switch ($currency) {
            case "CVE":
            case "DJF":
            case "GNF":
            case "IDR":
            case "JPY":
            case "KMF":
            case "KRW":
            case "PYG":
            case "RWF":
            case "UGX":
            case "VND":
            case "VUV":
            case "XAF":
            case "XOF":
            case "XPF":
                $format = 0;
                break;
            case "BHD":
            case "IQD":
            case "JOD":
            case "KWD":
            case "LYD":
            case "OMR":
            case "TND":
                $format = 3;
                break;
            default:
                $format = 2;
        }
        return $format;
    }

    /**
     * Return the formatted amount. Adyen accepts the currency in multiple formats.
     *
     * @param $amount
     * @param $currency
     * @return int
     */
    public function formatAmount($amount, $currency)
    {
        if ($amount === null) {
            // PHP 8 does not accept first param to be NULL
            $amount = 0;
        }
        return (int)number_format($amount, $this->decimalNumbers($currency), '', '');
    }

    /**
     * Tax Percentage needs to be in minor units for Adyen
     *
     * @param float $taxPercent
     * @return int
     */
    public function getMinorUnitTaxPercent($taxPercent)
    {
        $taxPercent = $taxPercent * 100;
        return (int)$taxPercent;
    }

    /**
     * @param $amount
     * @param $currency
     * @return float
     */
    public function originalAmount($amount, $currency)
    {
        // check the format
        switch ($currency) {
            case "JPY":
            case "IDR":
            case "KRW":
            case "BYR":
            case "VND":
            case "CVE":
            case "DJF":
            case "GNF":
            case "PYG":
            case "RWF":
            case "UGX":
            case "VUV":
            case "XAF":
            case "XOF":
            case "XPF":
            case "GHC":
            case "KMF":
                $format = 1;
                break;
            case "MRO":
                $format = 10;
                break;
            case "BHD":
            case "JOD":
            case "KWD":
            case "OMR":
            case "LYD":
            case "TND":
                $format = 1000;
                break;
            default:
                $format = 100;
                break;
        }

        return ($amount / $format);
    }

    /**
     * Retrieve decrypted hmac key
     *
     * @return string
     */
    public function getHmac($storeId = null)
    {
        switch ($this->configHelper->isDemoMode($storeId)) {
            case true:
                $hmacTest = $this->configHelper->getAdyenHppConfigData('hmac_test', $storeId);
                if (is_null($hmacTest)) {
                    return null;
                }
                $secretWord = $this->_encryptor->decrypt(trim((string) $hmacTest));
                break;
            default:
                $hmacLive = $this->configHelper->getAdyenHppConfigData('hmac_live', $storeId);
                if (is_null($hmacLive)) {
                    return null;
                }
                $secretWord = $this->_encryptor->decrypt(trim((string) $hmacLive));
                break;
        }
        return $secretWord;
    }

    /**
     * Check if configuration is set to demo mode
     *
     * @deprecated Use \Adyen\Payment\Helper\Config::isDemoMode instead
     * @param null|int|string $storeId
     * @return mixed
     */
    public function isDemoMode($storeId = null)
    {
        return $this->configHelper->getAdyenAbstractConfigDataFlag('demo_mode', $storeId);
    }

    public function isMotoDemoMode(array $motoMerchantAccountProperties): bool
    {
        return $motoMerchantAccountProperties['demo_mode'] === '1';
    }

    /**
     * Retrieve the API key
     * @deprecated Use Adyen\Payment\Helper\Config::getApiKey instead
     *
     * @param null|int|string $storeId
     * @return string
     */
    public function getAPIKey($storeId = null)
    {
        if ($this->configHelper->isDemoMode($storeId)) {
            $encryptedApiKeyTest = $this->configHelper->getAdyenAbstractConfigData('api_key_test', $storeId);
            if (is_null($encryptedApiKeyTest)) {
                return null;
            }
            $apiKey = $this->_encryptor->decrypt(trim((string) $encryptedApiKeyTest));
        } else {
            $encryptedApiKeyLive = $this->configHelper->getAdyenAbstractConfigData('api_key_live', $storeId);
            if (is_null($encryptedApiKeyLive)) {
                return null;
            }
            $apiKey = $this->_encryptor->decrypt(trim((string) $encryptedApiKeyLive));
        }
        return $apiKey;
    }

    /**
     * Retrieve the Client key
     *
     * @param null|int|string $storeId
     * @return string
     */
    public function getClientKey($storeId = null)
    {
        $clientKey = $this->configHelper->getAdyenAbstractConfigData(
            $this->configHelper->isDemoMode($storeId) ? 'client_key_test' : 'client_key_live',
            $storeId
        );

        if (is_null($clientKey)) {
            return null;
        }

        return trim((string) $clientKey);
    }

    /**
     * Retrieve the webserver username
     *
     * @param null|int|string $storeId
     * @return string
     */
    public function getWsUsername($storeId = null)
    {
        if ($this->configHelper->isDemoMode($storeId)) {
            $wsUsernameTest = $this->configHelper->getAdyenAbstractConfigData('ws_username_test', $storeId);
            if (is_null($wsUsernameTest)) {
                return null;
            }
            $wsUsername = trim((string) $wsUsernameTest);
        } else {
            $wsUsernameLive = $this->configHelper->getAdyenAbstractConfigData('ws_username_live', $storeId);
            if (is_null($wsUsernameLive)) {
                return null;
            }
            $wsUsername = trim((string) $wsUsernameLive);
        }
        return $wsUsername;
    }

    /**
     * Retrieve the Live endpoint prefix key
     *
     * @param null|int|string $storeId
     * @return string
     */
    public function getLiveEndpointPrefix($storeId = null)
    {
        $prefix = $this->configHelper->getAdyenAbstractConfigData('live_endpoint_url_prefix', $storeId);

        if (is_null($prefix)) {
            return null;
        }

        return trim((string) $prefix);
    }

    /**
     * Cancels the order
     *
     * @param $order
     */
    public function cancelOrder($order)
    {
        $orderStatus = $this->configHelper->getAdyenAbstractConfigData('payment_cancelled');
        $order->setActionFlag($orderStatus, true);

        switch ($orderStatus) {
            case Order::STATE_HOLDED:
                if ($order->canHold()) {
                    $order->hold()->save();
                }
                break;
            default:
                if ($order->canCancel()) {
                    if ($this->orderManagement->cancel($order->getEntityId())) { //new canceling process
                        try {
                            $orderStatusHistory = $this->orderStatusHistoryFactory->create()
                                ->setParentId($order->getEntityId())
                                ->setEntityName('order')
                                ->setStatus(Order::STATE_CANCELED)
                                ->setComment(__('Order has been cancelled by "%1" payment response.', $order->getPayment()->getMethod()));
                            $this->orderManagement->addComment($order->getEntityId(), $orderStatusHistory);
                        } catch (Exception $e) {
                            $this->adyenLogger->addAdyenDebug(
                                __('Order cancel history comment error: %1', $e->getMessage()),
                                $this->adyenLogger->getOrderContext($order)
                            );
                        }
                    } else { //previous canceling process
                        $this->adyenLogger->addAdyenDebug(
                            'Unsuccessful order canceling attempt by orderManagement service, use legacy process',
                            $this->adyenLogger->getOrderContext($order)
                        );
                        $order->cancel();
                        $order->save();
                    }
                } else {
                    $this->adyenLogger->addAdyenDebug(
                        'Order can not be canceled',
                        $this->adyenLogger->getOrderContext($order)
                    );
                }
                break;
        }
    }

    /**
     * Creditcard type that is selected is different from creditcard type that we get back from the request this
     * function get the magento creditcard type this is needed for getting settings like installments
     *
     * @param $ccType
     * @return mixed
     */
    public function getMagentoCreditCartType($ccType)
    {
        $ccTypesMapper = $this->getCcTypesAltData();

        if (isset($ccTypesMapper[$ccType])) {
            $ccType = $ccTypesMapper[$ccType]['code'];
        }

        return $ccType;
    }

    /**
     * @return array
     */
    public function getCcTypesAltData()
    {
        $adyenCcTypes = $this->getAdyenCcTypes();
        $types = [];
        foreach ($adyenCcTypes as $key => $data) {
            $types[$data['code_alt']] = $data;
            $types[$data['code_alt']]['code'] = $key;
        }
        return $types;
    }

    /**
     * @return mixed
     */
    public function getAdyenCcTypes()
    {
        return $this->_dataStorage->get('adyen_credit_cards');
    }

    /**
     * Get adyen magento module's name sent to Adyen
     *
     * @return string
     */
    public function getModuleName()
    {
        return (string)self::MODULE_NAME;
    }

    /**
     * Get adyen magento module's version from composer.json
     *
     * @return string
     */
    public function getModuleVersion()
    {
        $moduleDir = $this->componentRegistrar->getPath(
            ComponentRegistrar::MODULE,
            'Adyen_Payment'
        );

        $composerJson = file_get_contents($moduleDir . '/composer.json');
        $composerJson = json_decode($composerJson, true);

        if (empty($composerJson['version'])) {
            return "Version is not available in composer.json";
        }

        return $composerJson['version'];
    }

    /**
     * @deprecated Use Adyen\Payment\Helper\PaymentMethods::isOpenInvoice() instead.
     *
     * @param $paymentMethod
     * @return bool
     */
    public function isPaymentMethodOpenInvoiceMethod($paymentMethod)
    {
        if (is_null($paymentMethod)) {
            return false;
        }

        // Those open invoice methods support auto capture.
        if (strpos($paymentMethod, self::AFTERPAY) !== false ||
            strpos($paymentMethod, self::KLARNA) !== false ||
            strpos($paymentMethod, self::RATEPAY) !== false ||
            strpos($paymentMethod, self::FACILYPAY) !== false ||
            strpos($paymentMethod, self::AFFIRM) !== false ||
            strpos($paymentMethod, self::CLEARPAY) !== false ||
            strpos($paymentMethod, self::ZIP) !== false ||
            strpos($paymentMethod, self::PAYBRIGHT) !== false ||
            strpos($paymentMethod, self::ATOME) !== false ||
            strpos($paymentMethod, self::WALLEY) !== false ||
            strpos($paymentMethod, self::WALLEYB2B) !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * This function should be removed once we add specific classes for payment methods
     */
    public function isPaymentMethodOfType(string $paymentMethod, string $type): bool
    {
        return strpos($paymentMethod, $type) !== false;
    }

    /**
     * For Klarna And AfterPay use VatCategory High others use none
     *
     * @param $paymentMethod
     * @return bool
     */
    public function isVatCategoryHigh($paymentMethod)
    {
        if ($paymentMethod == self::KLARNA ||
            strlen((string) $paymentMethod) >= 9 && substr((string) $paymentMethod, 0, 9) == 'afterpay_'
        ) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public function showLogos()
    {
        $showLogos = $this->configHelper->getAdyenAbstractConfigData('title_renderer');
        if ($showLogos == RenderMode::MODE_TITLE_IMAGE) {
            return true;
        }
        return false;
    }

    /**
     * Create a file asset that's subject of fallback system
     *
     * @param string $fileId
     * @param array $params
     * @return File
     */
    public function createAsset($fileId, array $params = [])
    {
        $params = array_merge(['_secure' => $this->_request->isSecure()], $params);
        return $this->_assetRepo->createAsset($fileId, $params);
    }

    public function getStoreLocale($storeId)
    {
        $path = \Magento\Directory\Helper\Data::XML_PATH_DEFAULT_LOCALE;
        $storeLocale = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return $this->localeHelper->mapLocaleCode($storeLocale);
    }

    public function getCustomerStreetLinesEnabled($storeId)
    {
        $path = 'customer/address/street_lines';
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Format Magento locale codes with undersocre to ISO locale codes with dash
     *
     * @param $localeCode
     */
    public function formatLocaleCode($localeCode)
    {
        return str_replace("_", "-", (string) $localeCode);
    }

    public function getUnprocessedNotifications()
    {
        $notifications = $this->_notificationFactory->create();
        $notifications->unprocessedNotificationsFilter();
        return $notifications->getSize();
    }

    /**
     * @param $formFields
     * @param $count
     * @param $name
     * @param $price
     * @param $currency
     * @param $taxAmount
     * @param $priceInclTax
     * @param $taxPercent
     * @param $numberOfItems
     * @param $payment
     * @param null $itemId
     * @return mixed
     */
    public function createOpenInvoiceLineItem(
        $formFields,
        $count,
        $name,
        $price,
        $currency,
        $taxAmount,
        $priceInclTax,
        $taxPercent,
        $numberOfItems,
        $payment,
        $itemId = null
    ) {
        $description = str_replace("\n", '', trim((string) $name));
        $itemAmount = $this->formatAmount($price, $currency);

        $itemVatAmount = $this->getItemVatAmount(
            $taxAmount,
            $priceInclTax,
            $price,
            $currency
        );

        // Calculate vat percentage
        $itemVatPercentage = $this->getMinorUnitTaxPercent($taxPercent);

        return $this->getOpenInvoiceLineData(
            $formFields,
            $count,
            $currency,
            $description,
            $itemAmount,
            $itemVatAmount,
            $itemVatPercentage,
            $numberOfItems,
            $payment,
            $itemId
        );
    }

    /**
     * @param $formFields
     * @param $count
     * @param $order
     * @param $shippingAmount
     * @param $shippingTaxAmount
     * @param $currency
     * @param $payment
     * @return mixed
     */
    public function createOpenInvoiceLineShipping(
        $formFields,
        $count,
        $order,
        $shippingAmount,
        $shippingTaxAmount,
        $currency,
        $payment
    ) {
        $description = $order->getShippingDescription();
        $itemAmount = $this->formatAmount($shippingAmount, $currency);
        $itemVatAmount = $this->formatAmount($shippingTaxAmount, $currency);

        // Create RateRequest to calculate the Tax class rate for the shipping method
        $rateRequest = $this->_taxCalculation->getRateRequest(
            $order->getShippingAddress(),
            $order->getBillingAddress(),
            null,
            $order->getStoreId(),
            $order->getCustomerId()
        );

        $taxClassId = $this->_taxConfig->getShippingTaxClass($order->getStoreId());
        $rateRequest->setProductClassId($taxClassId);
        $rate = $this->_taxCalculation->getRate($rateRequest);

        $itemVatPercentage = $this->getMinorUnitTaxPercent($rate);
        $numberOfItems = 1;

        return $this->getOpenInvoiceLineData(
            $formFields,
            $count,
            $currency,
            $description,
            $itemAmount,
            $itemVatAmount,
            $itemVatPercentage,
            $numberOfItems,
            $payment,
            "shippingCost"
        );
    }

    /**
     * Add a line to the openinvoice data containing the details regarding an adjustment in the refund
     *
     * @param $formFields
     * @param $count
     * @param $description
     * @param $adjustmentAmount
     * @param $currency
     * @param $payment
     * @return mixed
     */
    public function createOpenInvoiceLineAdjustment(
        $formFields,
        $count,
        $description,
        $adjustmentAmount,
        $currency,
        $payment
    ) {
        $itemAmount = $this->formatAmount($adjustmentAmount, $currency);
        $itemVatAmount = 0;
        $itemVatPercentage = 0;
        $numberOfItems = 1;

        return $this->getOpenInvoiceLineData(
            $formFields,
            $count,
            $currency,
            $description,
            $itemAmount,
            $itemVatAmount,
            $itemVatPercentage,
            $numberOfItems,
            $payment,
            "adjustment"
        );
    }

    /**
     * @param $taxAmount
     * @param $priceInclTax
     * @param $price
     * @param $currency
     * @return string
     */
    public function getItemVatAmount(
        $taxAmount,
        $priceInclTax,
        $price,
        $currency
    ) {
        if ($taxAmount > 0 && $priceInclTax > 0) {
            return $this->formatAmount($priceInclTax, $currency) - $this->formatAmount($price, $currency);
        }
        return $this->formatAmount($taxAmount, $currency);
    }

    /**
     * Set the openinvoice line
     *
     * @param $formFields
     * @param $count
     * @param $currencyCode
     * @param $description
     * @param $itemAmount
     * @param $itemVatAmount
     * @param $itemVatPercentage
     * @param $numberOfItems
     * @param $payment
     * @param null|int $itemId optional
     * @return mixed
     */
    public function getOpenInvoiceLineData(
        $formFields,
        $count,
        $currencyCode,
        $description,
        $itemAmount,
        $itemVatAmount,
        $itemVatPercentage,
        $numberOfItems,
        $payment,
        $itemId = null
    ) {
        $linename = "line" . $count;

        // item id is optional
        if ($itemId) {
            $formFields['openinvoicedata.' . $linename . '.itemId'] = $itemId;
        }

        $formFields['openinvoicedata.' . $linename . '.currencyCode'] = $currencyCode;
        $formFields['openinvoicedata.' . $linename . '.description'] = $description;
        $formFields['openinvoicedata.' . $linename . '.itemAmount'] = $itemAmount;
        $formFields['openinvoicedata.' . $linename . '.itemVatAmount'] = $itemVatAmount;
        $formFields['openinvoicedata.' . $linename . '.itemVatPercentage'] = $itemVatPercentage;
        $formFields['openinvoicedata.' . $linename . '.numberOfItems'] = $numberOfItems;

        if ($this->isVatCategoryHigh(
            $payment->getAdditionalInformation(
                AdyenPaymentMethodDataAssignObserver::BRAND_CODE
            )
        )
        ) {
            $formFields['openinvoicedata.' . $linename . '.vatCategory'] = "High";
        } else {
            $formFields['openinvoicedata.' . $linename . '.vatCategory'] = "None";
        }
        return $formFields;
    }

    /**
     * @param null|int|string $storeId
     * @return string the X API Key for the specified or current store
     */
    public function getPosApiKey($storeId = null)
    {
        if ($this->configHelper->isDemoMode($storeId)) {
            $encryptedApiKeyTest = $this->configHelper->getAdyenPosCloudConfigData('api_key_test', $storeId);
            if (is_null($encryptedApiKeyTest)) {
                return null;
            }

            $apiKey = $this->_encryptor->decrypt(trim((string) $encryptedApiKeyTest));
        } else {
            $encryptedApiKeyLive = $this->configHelper->getAdyenPosCloudConfigData('api_key_live', $storeId);
            if (is_null($encryptedApiKeyLive)) {
                return null;
            }

            $apiKey = $this->_encryptor->decrypt(trim((string) $encryptedApiKeyLive));
        }
        return $apiKey;
    }

    /**
     * Return the Store ID for the current store/mode
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function getPosStoreId($storeId = null)
    {
        return $this->configHelper->getAdyenPosCloudConfigData('pos_store_id', $storeId);
    }

    /**
     * Return the merchant account name configured for the proper payment method.
     * If it is not configured for the specific payment method,
     * return the merchant account name defined in required settings.
     *
     * @param $paymentMethod
     * @param null|int|string $storeId
     * @return string
     */
    public function getAdyenMerchantAccount($paymentMethod, $storeId = null)
    {
        if (!$storeId) {
            $storeId = $this->storeManager->getStore()->getId();
        }

        $merchantAccount = $this->configHelper->getAdyenAbstractConfigData("merchant_account", $storeId);
        $merchantAccountPos = $this->configHelper->getAdyenPosCloudConfigData('pos_merchant_account', $storeId);

        if ($paymentMethod == 'adyen_pos_cloud' && !empty($merchantAccountPos)) {
            return $merchantAccountPos;
        }
        return $merchantAccount;
    }

    /**
     * Format the Receipt sent in the Terminal API response in HTML
     * so that it can be easily shown to the shopper
     *
     * @param $paymentReceipt
     * @return string
     */
    public function formatTerminalAPIReceipt($paymentReceipt)
    {
        $formattedHtml = "<table class='terminal-api-receipt'>";
        foreach ($paymentReceipt as $receipt) {
            if ($receipt['DocumentQualifier'] == "CustomerReceipt") {
                foreach ($receipt['OutputContent']['OutputText'] as $item) {
                    parse_str((string) $item['Text'], $textParts);
                    $formattedHtml .= "<tr class='terminal-api-receipt'>";
                    if (!empty($textParts['name'])) {
                        $formattedHtml .= "<td class='terminal-api-receipt-name'>" . $textParts['name'] . "</td>";
                    } else {
                        $formattedHtml .= "<td class='terminal-api-receipt-name'>&nbsp;</td>";
                    }
                    if (!empty($textParts['value'])) {
                        $formattedHtml .= "<td class='terminal-api-receipt-value' align='right'>"
                            . $textParts['value'] . "</td>";
                    } else {
                        $formattedHtml .= "<td class='terminal-api-receipt-value' align='right'>&nbsp;</td>";
                    }
                    $formattedHtml .= "</tr>";
                }
            }
        }
        $formattedHtml .= "</table>";
        return $formattedHtml;
    }

    /**
     * Initializes and returns Adyen Client and sets the required parameters of it
     *
     * @param null|int|string $storeId
     * @param string|null $apiKey
     * @param string|null $motoMerchantAccount
     * @param bool|null $demoMode
     * @return Client
     * @throws AdyenException
     * @throws NoSuchEntityException
     */
    public function initializeAdyenClient(
        $storeId = null,
        $apiKey = null,
        $motoMerchantAccount = null,
        ?bool $demoMode = null
    ): Client {
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }

        $isDemo = is_null($demoMode) ? $this->configHelper->isDemoMode($storeId) : $demoMode;
        $mode = $isDemo ? 'test' : 'live';
        if (empty($apiKey)) {
            $apiKey = $this->configHelper->getAPIKey($mode, $storeId);
        }

        if (!is_null($motoMerchantAccount)) {
            try {
                $motoMerchantAccountProperties = $this->configHelper->getMotoMerchantAccountProperties(
                    $motoMerchantAccount,
                    $storeId
                );
            } catch (AdyenException $e) {
                $this->adyenLogger->addAdyenDebug($e->getMessage());
                throw $e;
            }

            // Override the x-api-key and demo mode setting if MOTO merchant account is set.
            $apiKey = $this->_encryptor->decrypt($motoMerchantAccountProperties['apikey']);
            $isDemo = $this->isMotoDemoMode($motoMerchantAccountProperties);
        }

        $client = $this->createAdyenClient();
        $client->setApplicationName(self::APPLICATION_NAME);
        $client->setXApiKey($apiKey);

        $checkoutFrontendRegion = $this->configHelper->getCheckoutFrontendRegion($storeId);
        if (isset($checkoutFrontendRegion)) {
            $client->setRegion($checkoutFrontendRegion);
        }

        $client->setMerchantApplication($this->getModuleName(), $this->getModuleVersion());
        $platformData = $this->getMagentoDetails();
        $client->setExternalPlatform($platformData['name'], $platformData['version'], 'Adyen');
        if ($isDemo) {
            $client->setEnvironment(Environment::TEST);
        } else {
            $client->setEnvironment(Environment::LIVE, $this->configHelper->getLiveEndpointPrefix($storeId));
        }

        return $client;
    }

    public function getMagentoDetails()
    {
        return [
            'name' => $this->productMetadata->getName(),
            'version' => $this->productMetadata->getVersion(),
            'edition' => $this->productMetadata->getEdition(),
        ];
    }

    public function buildRequestHeaders($payment = null)
    {
        $magentoDetails = $this->getMagentoDetails();
        $headers = [
            HeaderDataBuilderInterface::EXTERNAL_PLATFORM_NAME => $magentoDetails['name'],
            HeaderDataBuilderInterface::EXTERNAL_PLATFORM_VERSION => $magentoDetails['version'],
            HeaderDataBuilderInterface::EXTERNAL_PLATFORM_EDITION => $magentoDetails['edition'],
            HeaderDataBuilderInterface::MERCHANT_APPLICATION_NAME => $this->getModuleName(),
            HeaderDataBuilderInterface::MERCHANT_APPLICATION_VERSION  => $this->getModuleVersion()
        ];

        if (isset($payment)) {
            $frontendType = $payment->getAdditionalInformation(HeaderDataBuilderInterface::ADDITIONAL_DATA_FRONTEND_TYPE_KEY);
            if (is_null($frontendType)) {
                // Check the request URI
                $requestPath = $this->request->getOriginalPathInfo();
                $requestMethod = $this->request->getMethod();
                if ($requestPath === '/graphql' && $requestMethod === 'POST') {
                    $frontendType = 'headless-graphql';
                } else {
                    $frontendType = 'headless-rest';
                }
            }
            $headers[HeaderDataBuilderInterface::EXTERNAL_PLATFORM_FRONTEND_TYPE] = $frontendType;
        }

        return $headers;
    }

    public function buildApplicationInfo(Client $client) :ApplicationInfo
    {
        $applicationInfo =  new ApplicationInfo();

        $adyenLibrary['name'] = $client->getLibraryName(); // deprecated but no alternative was given.
        $adyenLibrary['version'] = $client->getLibraryVersion(); // deprecated but no alternative was given.

        $applicationInfo->setAdyenLibrary(new CommonField($adyenLibrary));

        if ($adyenPaymentSource = $client->getConfig()->getAdyenPaymentSource()) {
           $applicationInfo->setAdyenPaymentSource(new CommonField($adyenPaymentSource));
        }

        if ($externalPlatform = $client->getConfig()->getExternalPlatform()) {
            $applicationInfo->setExternalPlatform($externalPlatform);
        }

        if ($merchantApplication = $client->getConfig()->getMerchantApplication()) {
            $applicationInfo->setMerchantApplication(new CommonField($merchantApplication));
        }

        return $applicationInfo;
    }

    /**
     * @throws AdyenException
     * @throws NoSuchEntityException
     */
    public function initializeAdyenClientWithClientConfig(array $clientConfig): Client
    {
        $storeId = $clientConfig['storeId'];
        $motoMerchantAccount = null;

        if (isset($clientConfig['isMotoTransaction']) && $clientConfig['isMotoTransaction'] === true) {
            $motoMerchantAccount = $clientConfig['motoMerchantAccount'];
        }

        return $this->initializeAdyenClient($storeId, null, $motoMerchantAccount);
    }

    public function initializePaymentsApi(Client $client):PaymentsApi
    {
        return new PaymentsApi($client);
    }

    public function initializeModificationsApi(Client $client):ModificationsApi
    {
        return new ModificationsApi($client);
    }

    public function initializeRecurringApi(Client $client):RecurringApi
    {
        return new RecurringApi($client);
    }

    public function initializeOrdersApi(Client $client): OrdersApi
    {
        return new OrdersApi($client);
    }

    public function initializePaymentLinksApi(Client $client):PaymentLinksApi
    {
        return new PaymentLinksApi($client);
    }

    /**
     * @param Client $client
     * @return PosPayment
     * @throws AdyenException
     */
    public function createAdyenPosPaymentService($client)
    {
        return new PosPayment($client);
    }

    /**
     * @return Client
     * @throws AdyenException
     */
    private function createAdyenClient()
    {
        return new Client();
    }

    /**
     * @deprecated
     * @param null|int|string $storeId
     * @return string
     */
    public function getOrigin($storeId)
    {
        if ($paymentOriginUrl = $this->configHelper->getAdyenAbstractConfigData("payment_origin_url", $storeId) ) {
            return $paymentOriginUrl;
        }
        $objectManager = ObjectManager::getInstance();
        $state = $objectManager->get(State::class);
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        if ('adminhtml' === $state->getAreaCode()) {
            $baseUrl = $this->backendHelper->getHomePageUrl();
        }
        $parsed = parse_url((string) $baseUrl);
        $origin = $parsed['scheme'] . "://" . $parsed['host'];
        if (!empty($parsed['port'])) {
            $origin .= ":" . $parsed['port'];
        }
        return $origin;
    }

    /**
     * Retrieve origin keys for platform's base url
     *
     * @return string
     * @throws AdyenException
     * @deprecared please use getClientKey instead
     */
    public function getOriginKeyForBaseUrl()
    {
        $storeId = $this->storeManager->getStore()->getId();
        $origin = $this->getOrigin($storeId);
        $cacheKey = 'Adyen_origin_key_for_' . $origin . '_' . $storeId;

        if (!$originKey = $this->cache->load($cacheKey)) {
            if ($originKey = $this->getOriginKeyForOrigin($origin, $storeId)) {
                $this->cache->save($originKey, $cacheKey, [ConfigCache::CACHE_TAG], 60 * 60 * 24);
            }
        }

        return $originKey;
    }

    /**
     * Get origin key for a specific origin using the adyen api library client
     *
     * @param $origin
     * @param null|int|string $storeId
     * @return string
     * @throws AdyenException
     */
    private function getOriginKeyForOrigin($origin, $storeId = null)
    {
        $params = [
            "originDomains" => [
                $origin
            ]
        ];

        $client = $this->initializeAdyenClient($storeId);

        try {
            $service = new UtilityApi($client);
            $responseObj = $service->originKeys(new UtilityRequest($params));
            $response = json_decode(json_encode($responseObj->jsonSerialize()), true);
        } catch (Exception $e) {
            $this->adyenLogger->error($e->getMessage());
        }

        $originKey = "";

        if (!empty($response['originKeys'][$origin])) {
            $originKey = $response['originKeys'][$origin];
        }

        return $originKey;
    }

    /**
     * @param null|int|string $storeId
     * @return string
     */
    public function getCheckoutEnvironment($storeId = null)
    {
        if ($this->configHelper->isDemoMode($storeId)) {
            return self::TEST;
        }

        switch ($this->configHelper->getCheckoutFrontendRegion($storeId)) {
            case "au":
                return self::LIVE_AU;
            case "us":
                return self::LIVE_US;
            case "in":
                return self::LIVE_IN;
            default:
                return self::LIVE;
        }
    }

    /**
     * Method can be used by interceptors to provide the customer ID in a different way.
     *
     * @param Order $order
     * @return int|null
     */
    public function getCustomerId(Order $order)
    {
        return $order->getCustomerId();
    }

    /**
     * Get icon from variant
     *
     * @param $variant
     * @return array
     */
    public function getVariantIcon($variant)
    {
        $asset = $this->createAsset(sprintf("Adyen_Payment::images/logos/%s_small.png", $variant));

        if($this->_assetSource->findSource($asset)) {
            list($width, $height) = getimagesize($asset->getSourceFile());
            $icon = ['url' => $asset->getUrl(), 'width' => $width, 'height' => $height];
        } else {
            $url = "https://checkoutshopper-test.adyen.com/checkoutshopper/images/logos/$variant.svg";
            $icon = ['url' => $url, 'width' => 77, 'height' => 50];
        }

        return $icon;
    }

    /**
     * Check if HPP vault is enabled
     *
     * @param null|int|string $storeId
     * @return mixed
     */
    public function isHppVaultEnabled($storeId = null)
    {
        return $this->configHelper->getAdyenHppVaultConfigDataFlag('active', $storeId);
    }

    /**
     * @param $client
     * @return Checkout
     * @throws AdyenException
     * @throws NoSuchEntityException
     * @deprecared use `initializePaymentsApi`, or `initializeModificationsApi` based on your case
     */
    public function createAdyenCheckoutService(Client $client = null): Checkout
    {
        if (!$client) {
            $client = $this->initializeAdyenClient();
        }

        return new Checkout($client);
    }

    /**
     * @param $client
     * @return Recurring
     * @throws AdyenException
     * @deprecared use `initializeRecurringApi()`
     */
    public function createAdyenRecurringService($client)
    {
        return new Recurring($client);
    }

    /**
     * @param string $date
     * @param string $format
     * @return mixed
     */
    public function formatDate($date = null, $format = 'Y-m-d H:i:s')
    {
        if (strlen($date) < 0) {
            $date = date('d-m-Y H:i:s');
        }
        $timeStamp = new DateTime($date);
        return $timeStamp->format($format);
    }

    /**
     * @param string|null $type
     * @param string|null $token
     * @return string
     */
    public function buildThreeDS2ProcessResponseJson($type = null, $token = null)
    {
        $response = ['threeDS2' => false];

        if (!empty($type)) {
            $response['type'] = $type;
        }

        if ($type && $token) {
            $response['threeDS2'] = true;
            $response['token'] = $token;
        }

        return json_encode($response);
    }

    /**
     * @param null|int|string $storeId
     * @return mixed|string
     */
    public function getCurrentLocaleCode($storeId)
    {
        $localeCode = $this->configHelper->getAdyenHppConfigData('shopper_locale', $storeId);
        if ($localeCode != "") {
            return $this->localeHelper->mapLocaleCode($localeCode);
        }

        $locale = $this->localeResolver->getLocale();
        if ($locale) {
            return $this->localeHelper->mapLocaleCode($locale);
        }

        // should have the value if not fall back to default
        $localeCode = $this->config->getValue(
            \Magento\Directory\Helper\Data::XML_PATH_DEFAULT_LOCALE,
            ScopeInterface::SCOPE_STORES,
            $this->storeManager->getStore($storeId)->getCode()
        );

        return $this->localeHelper->mapLocaleCode($localeCode);
    }

    /**
     * Get the Customer Area PSP Search URL with a preset PSP Reference
     *
     * @param string $pspReference
     * @param string $liveEnvironment
     * @return string
     */
    public function getPspReferenceSearchUrl($pspReference, $liveEnvironment): string
    {
        if ($liveEnvironment === "true") {
            $checkoutEnvironment = "live";
        } else {
            $checkoutEnvironment = "test";
        }
        return sprintf(
            "https://ca-%s.adyen.com/ca/ca/accounts/showTx.shtml?pspReference=%s",
            $checkoutEnvironment,
            $pspReference
        );
    }

    /**
     * @param $shopperReference
     * @return string
     */
    public function padShopperReference(string $shopperReference): string
    {
        return str_pad($shopperReference, 3, '0', STR_PAD_LEFT);
    }

    public function logRequest(array $request, $apiVersion, $endpoint)
    {
        $storeId = $this->storeManager->getStore()->getId();
        $isDemo = $this->configHelper->isDemoMode($storeId);
        $context = ['apiVersion' => $apiVersion];
        if ($isDemo) {
            $context['body'] = $request;
        } else {
            $context['livePrefix'] = $this->configHelper->getLiveEndpointPrefix($storeId);
            $context['body'] = $this->filterReferences($request);
        }

        $this->adyenLogger->info('Request to Adyen API ' . $endpoint, $context);
    }

    public function logResponse(array $response)
    {
        $storeId = $this->storeManager->getStore()->getId();
        $isDemo = $this->configHelper->isDemoMode($storeId);
        $context = [];
        if ($isDemo) {
            $context['body'] = $response;
        } else {
            $context['body'] = $this->filterReferences($response);
        }

        $this->adyenLogger->info('Response from Adyen API', $context);
    }

    public function logAdyenException(AdyenException $e)
    {
        $responseArray = [];
        $responseArray['error'] = $e->getMessage();
        $responseArray['errorCode'] = $e->getAdyenErrorCode();
        $this->logResponse($responseArray);
    }

    private function filterReferences(array $data): array
    {
        return array_filter($data, function($value, $key) {
            // Keep only reference keys, e.g. reference, pspReference, merchantReference etc.
            return false !== strpos(strtolower($key), 'reference');
        }, ARRAY_FILTER_USE_BOTH);
    }
}
