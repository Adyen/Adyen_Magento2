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

namespace Adyen\Payment\Model\Ui;

use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Helper\Vault;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Source;
use Magento\Payment\Model\CcConfig;
use Magento\Store\Model\StoreManagerInterface;

class AdyenCcConfigProvider implements ConfigProviderInterface
{
    const CODE = 'adyen_cc';
    const CC_VAULT_CODE = 'adyen_cc_vault';

    protected Data $adyenHelper;
    protected Source $assetSource;
    protected RequestInterface $request;
    protected UrlInterface $urlBuilder;
    private CcConfig $ccConfig;
    private StoreManagerInterface $storeManager;
    private SerializerInterface $serializer;
    private Config $configHelper;
    private PaymentMethods $paymentMethodsHelper;
    private Vault $vaultHelper;
    private Http $httpRequest;

    public function __construct(
        Data $adyenHelper,
        RequestInterface $request,
        UrlInterface $urlBuilder,
        Source $assetSource,
        StoreManagerInterface $storeManager,
        CcConfig $ccConfig,
        SerializerInterface $serializer,
        Config $configHelper,
        PaymentMethods $paymentMethodsHelper,
        Vault $vaultHelper,
        Http $httpRequest
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->request = $request;
        $this->urlBuilder = $urlBuilder;
        $this->assetSource = $assetSource;
        $this->ccConfig = $ccConfig;
        $this->storeManager = $storeManager;
        $this->serializer = $serializer;
        $this->configHelper = $configHelper;
        $this->paymentMethodsHelper = $paymentMethodsHelper;
        $this->vaultHelper = $vaultHelper;
        $this->httpRequest = $httpRequest;
    }

    public function getConfig(): array
    {
        // set to active
        $config = [
            'payment' => [
                self::CODE => [
                    'vaultCode' => self::CC_VAULT_CODE,
                    'isActive' => true,
                    'successPage' => $this->urlBuilder->getUrl(
                        'checkout/onepage/success',
                        ['_secure' => $this->_getRequest()->isSecure()]
                    )
                ]
            ]
        ];

        $methodCode = self::CODE;

        $config = array_merge_recursive(
            $config,
            [
                'payment' => [
                    'ccform' => [
                        'availableTypes' => [
                            $methodCode => $this->paymentMethodsHelper->getCcAvailableTypes()
                        ],
                        'availableTypesByAlt' => [
                            $methodCode => $this->paymentMethodsHelper->getCcAvailableTypesByAlt()
                        ],
                        'months' => [$methodCode => $this->getCcMonths()],
                        'years' => [$methodCode => $this->getCcYears()],
                        'hasVerification' => [$methodCode => $this->hasVerification()],
                        'cvvImageUrl' => [$methodCode => $this->getCvvImageUrl()]
                    ]
                ]
            ]
        );
        $types = $this->adyenHelper->getAdyenCcTypes();
        $storeId = $this->storeManager->getStore()->getId();
        $cardRecurringEnabled = $this->vaultHelper->getPaymentMethodRecurringActive(self::CODE, $storeId);
        $methodTitle =   $this->configHelper->getConfigData('title', Config::XML_ADYEN_CC, $storeId);

        $config['payment']['adyenCc']['adyenCcTypes'] = $types;
        $config['payment']['adyenCc']['methodCode'] = self::CODE;
        $config['payment']['adyenCc']['title'] = __($methodTitle);
        $config['payment']['adyenCc']['locale'] = $this->adyenHelper->getStoreLocale($storeId);
        $config['payment']['adyenCc']['isCardRecurringEnabled'] = $cardRecurringEnabled;
        $config['payment']['adyenCc']['icons'] = $this->getIcons();
        $config['payment']['adyenCc']['isClickToPayEnabled'] = $this->configHelper->isClickToPayEnabled($storeId);
        $config['payment']['adyenCc']['controllerName'] = $this->httpRequest->getControllerName();

        // has installments by default false
        $config['payment']['adyenCc']['hasInstallments'] = false;

        // get Installments
        $installmentsEnabled = $this->configHelper->getAdyenCcConfigData('enable_installments');
        $installments = $this->configHelper->getAdyenCcConfigData('installments');

        if ($installmentsEnabled && $installments) {
            $config['payment']['adyenCc']['installments'] = $this->serializer->unserialize($installments);
            $config['payment']['adyenCc']['hasInstallments'] = true;
        } else {
            $config['payment']['adyenCc']['installments'] = [];
        }

        // check if cvc is required
        $config['payment']['adyenCc']['requireCvc'] =
            $this->configHelper->getIsCvcRequiredForRecurringCardPayments($storeId);

        return $config;
    }

    /**
     * Get icons for available payment methods
     *
     * @return array
     */
    protected function getIcons()
    {
        $icons = [];
        $types = $this->adyenHelper->getAdyenCcTypes();
        foreach (array_keys($types) as $code) {
            if (!array_key_exists($code, $icons)) {
                $asset = $this->ccConfig->createAsset('Magento_Payment::images/cc/' . strtolower($code) . '.png');
                $placeholder = $this->assetSource->findSource($asset);
                if ($placeholder) {
                    list($width, $height) = getimagesize($asset->getSourceFile());
                    $icons[$code] = [
                        'url' => $asset->getUrl(),
                        'width' => $width,
                        'height' => $height
                    ];
                }
            }
        }
        return $icons;
    }

    /**
     * Retrieve credit card expire months
     *
     * @return array
     */
    protected function getCcMonths()
    {
        return $this->ccConfig->getCcMonths();
    }

    /**
     * Retrieve credit card expire years
     *
     * @return array
     */
    protected function getCcYears()
    {
        return $this->ccConfig->getCcYears();
    }

    /**
     * Has verification is always true
     *
     * @return bool
     */
    protected function hasVerification()
    {
        return $this->configHelper->getAdyenCcConfigData('useccv');
    }

    /**
     * Retrieve CVV tooltip image url
     *
     * @return string
     */
    protected function getCvvImageUrl()
    {
        return $this->ccConfig->getCvvImageUrl();
    }

    /**
     * Retrieve request object
     *
     * @return RequestInterface
     */
    protected function _getRequest()
    {
        return $this->request;
    }
}
