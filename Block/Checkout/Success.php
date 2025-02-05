<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2021 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Block\Checkout;

use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Model\Ui\AdyenCheckoutSuccessConfigProvider;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\QuoteIdToMaskedQuoteId;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\OrderFactory;

class Success extends Template
{
    protected $order;
    protected CheckoutSession $checkoutSession;
    protected CustomerSession $customerSession;
    protected Data $adyenHelper;
    protected StoreManagerInterface $storeManager;
    private Config $configHelper;
    private SerializerInterface $serializerInterface;
    private AdyenCheckoutSuccessConfigProvider $configProvider;
    private QuoteIdToMaskedQuoteId $quoteIdToMaskedQuoteId;
    private OrderRepositoryInterface $orderRepository;
    /** @deprecated This property has been deprecated and will be removed on V10. */
    protected OrderFactory $orderFactory;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        QuoteIdToMaskedQuoteId $quoteIdToMaskedQuoteId,
        OrderFactory $orderFactory,
        Data $adyenHelper,
        Config $configHelper,
        AdyenCheckoutSuccessConfigProvider $configProvider,
        StoreManagerInterface $storeManager,
        SerializerInterface $serializerInterface,
        OrderRepositoryInterface $orderRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
        $this->orderFactory = $orderFactory;
        $this->adyenHelper = $adyenHelper;
        $this->configHelper = $configHelper;
        $this->configProvider = $configProvider;
        $this->storeManager = $storeManager;
        $this->serializerInterface = $serializerInterface;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Render with the checkout component on the success page for the following cases:
     * PresentToShopper e.g. Multibanco
     * Received e.g. Bank Transfer IBAN
     * @return bool
     */
    public function renderAction()
    {
        if (
            !empty($this->getOrder()->getPayment()->getAdditionalInformation('resultCode')) &&
            !empty($this->getOrder()->getPayment()->getAdditionalInformation('action')) &&
            (
            in_array($this->getOrder()->getPayment()->getAdditionalInformation('resultCode'),
                [
                    PaymentResponseHandler::PRESENT_TO_SHOPPER,
                    PaymentResponseHandler::RECEIVED
                ]
            )
            )
        ) {
            return true;
        }
        return false;
    }

    public function getAction()
    {
        return json_encode($this->getOrder()->getPayment()->getAdditionalInformation('action'));
    }

    public function showAdyenGiving()
    {
        return $this->adyenGivingEnabled() && $this->hasDonationToken();
    }

    public function adyenGivingEnabled(): bool
    {
        return (bool) $this->configHelper->adyenGivingEnabled($this->storeManager->getStore()->getId());
    }

    public function hasDonationToken()
    {
        return $this->getDonationToken() && 'null' !== $this->getDonationToken();
    }

    public function getDonationToken()
    {
        return json_encode($this->getOrder()->getPayment()->getAdditionalInformation('donationToken'));
    }

    public function getDonationComponentConfiguration(): array
    {
        $storeId = $this->storeManager->getStore()->getId();
        $imageBaseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA).'adyen/';
        $donationAmounts = explode(',', (string) $this->configHelper->getAdyenGivingDonationAmounts($storeId));
        $donationAmounts = array_map(function ($amount) {
            return $this->adyenHelper->formatAmount($amount, $this->getOrder()->getOrderCurrencyCode());
        }, $donationAmounts);

        return [
            'name' => $this->configHelper->getAdyenGivingCharityName($storeId),
            'description' => $this->configHelper->getAdyenGivingCharityDescription($storeId),
            'backgroundUrl' => $imageBaseUrl . $this->configHelper->getAdyenGivingBackgroundImage($storeId),
            'logoUrl' => $imageBaseUrl . $this->configHelper->getAdyenGivingCharityLogo($storeId),
            'website' => $this->configHelper->getAdyenGivingCharityWebsite($storeId),
            'donationAmounts' => implode(',', $donationAmounts)
        ];
    }

    public function getSerializedCheckoutConfig()
    {
        return $this->serializerInterface->serialize($this->configProvider->getConfig());
    }

    public function getLocale()
    {
        return $this->adyenHelper->getCurrentLocaleCode(
            $this->storeManager->getStore()->getId()
        );
    }

    public function getClientKey()
    {
        $environment = $this->configHelper->isDemoMode() ? 'test' : 'live';
        return $this->configHelper->getClientKey($environment);
    }

    public function getEnvironment()
    {
        return $this->adyenHelper->getCheckoutEnvironment(
            $this->storeManager->getStore()->getId()
        );
    }

    /**
     * @return Order
     */
    public function getOrder()
    {
        if ($this->order == null) {
            $this->order = $this->orderRepository->get($this->checkoutSession->getLastOrderId());
        }
        return $this->order;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getMaskedQuoteId(): ?string
    {
        return $this->quoteIdToMaskedQuoteId->execute($this->getOrder()->getQuoteId());
    }

    public function getIsCustomerLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }
}
