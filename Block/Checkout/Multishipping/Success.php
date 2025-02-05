<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2021 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Block\Checkout\Multishipping;

use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Model\ResourceModel\PaymentResponse\Collection;
use Adyen\Payment\Model\Ui\AdyenCheckoutSuccessConfigProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Multishipping\Model\Checkout\Type\Multishipping;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class Success extends \Magento\Multishipping\Block\Checkout\Success
{
    private bool $isAdyenPayment;
    private ?array $paymentResponseEntities;
    private ?array $ordersInfo;
    private Data $adyenHelper;
    private StoreManagerInterface $storeManager;
    private SerializerInterface $serializerInterface;
    private AdyenCheckoutSuccessConfigProvider $configProvider;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private Config $configHelper;
    private PaymentMethods $paymentMethodsHelper;

    public function __construct(
        Collection $paymentResponseCollection,
        Data $adyenHelper,
        PaymentMethods $paymentMethodsHelper,
        StoreManagerInterface $storeManager,
        SerializerInterface $serializerInterface,
        AdyenCheckoutSuccessConfigProvider $configProvider,
        Context $context,
        Multishipping $multishipping,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Config $configHelper,
        array $data = []
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->paymentMethodsHelper = $paymentMethodsHelper;
        $this->storeManager = $storeManager;
        $this->serializerInterface = $serializerInterface;
        $this->configProvider = $configProvider;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->configHelper = $configHelper;
        parent::__construct($context, $multishipping, $data);

        $orderIds = $this->getOrderIds();

        $this->paymentResponseEntities = $paymentResponseCollection
            ->getPaymentResponsesWithMerchantReferences(array_values($orderIds));

        $this->setOrderInfo(array_keys($orderIds));
    }

    /**
     * Returns true if the component must be rendered in the multishipping success page
     * @return bool
     */
    public function renderAction()
    {
        foreach ($this->paymentResponseEntities as $paymentResponseEntity) {
            if (in_array($paymentResponseEntity['result_code'], PaymentResponseHandler::ACTION_REQUIRED_STATUSES)) {
                return true;
            }
        }
        return false;
    }

    public function getPaymentResponseEntities()
    {
        return $this->paymentResponseEntities ?? [];
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

    public function getSerializedCheckoutConfig()
    {
        return $this->serializerInterface->serialize($this->configProvider->getConfig());
    }

    private function setOrderInfo($orderIds)
    {
        $orders = $this->orderRepository->getList(
            $this->searchCriteriaBuilder->addFilter('entity_id', $orderIds, 'in')->create()
        )->getItems();

        foreach ($orders as $order) {
            $payment = $order->getPayment();
            $paymentMethod = $payment->getMethod();
            $additionalInformation = $payment->getAdditionalInformation();
            if ($this->paymentMethodsHelper->isAdyenPayment($paymentMethod)) {
                $this->setIsAdyenPayment(true);
                $this->ordersInfo[$order->getEntityId()]['resultCode'] = $additionalInformation['resultCode'];
                switch ($additionalInformation['resultCode']) {
                    case PaymentResponseHandler::AUTHORISED:
                        $this->ordersInfo[$order->getEntityId()]['buttonLabel'] = $this->getPaymentCompletedLabel();
                        break;
                    case PaymentResponseHandler::REFUSED:
                        $this->ordersInfo[$order->getEntityId()]['buttonLabel'] = $this->getPaymentFailedLabel();
                        break;
                    default:
                        $this->ordersInfo[$order->getEntityId()]['buttonLabel'] = $this->getCompletePaymentLabel();
                }
            }
        }
    }

    public function getIsPaymentCompleted(int $orderId)
    {
        // TODO check for all completed responses, not only Authorised, Refused, Pending or PresentToShopper
        return !in_array($this->ordersInfo[$orderId]['resultCode'], PaymentResponseHandler::ACTION_REQUIRED_STATUSES);
    }

    public function getPaymentButtonLabel(int $orderId)
    {
        return $this->ordersInfo[$orderId]['buttonLabel'];
    }

    public function getPaymentCompletedLabel()
    {
        return __('Payment Completed');
    }

    public function getCompletePaymentLabel()
    {
        return __('Complete Payment');
    }

    public function getPaymentFailedLabel()
    {
        return __('Payment Failed');
    }

    public function isAdyenPayment(): ?bool
    {
        return $this->isAdyenPayment;
    }

    public function setIsAdyenPayment(bool $isAdyenPayment)
    {
        $this->isAdyenPayment = $isAdyenPayment;
    }
}
