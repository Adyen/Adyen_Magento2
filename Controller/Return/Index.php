<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

// phpcs:ignore
namespace Adyen\Payment\Controller\Return;

use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Helper\PaymentsDetails;
use Adyen\Payment\Helper\Quote;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Logger\AdyenLogger;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;

class Index extends Action
{
    const BRAND_CODE_DOTPAY = 'dotpay';
    const RESULT_CODE_RECEIVED = 'Received';
    const DETAILS_ALLOWED_PARAM_KEYS = [
        'MD',
        'PaReq',
        'PaRes',
        'billingToken',
        'cupsecureplus.smscode',
        'facilitatorAccessToken',
        'oneTimePasscode',
        'orderID',
        'payerID',
        'payload',
        'paymentID',
        'paymentStatus',
        'redirectResult',
        'threeDSResult',
        'threeds2.challengeResult',
        'threeds2.fingerprint'
    ];

    protected OrderFactory $orderFactory;
    protected Config $configHelper;
    protected Order $order;
    protected Session $session;
    protected AdyenLogger $adyenLogger;
    protected StoreManagerInterface $storeManager;
    private Quote $quoteHelper;
    private Order\Payment $payment;
    private PaymentsDetails $paymentsDetailsHelper;
    private PaymentResponseHandler $paymentResponseHandler;
    private CartRepositoryInterface $cartRepository;
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        Context                  $context,
        OrderFactory             $orderFactory,
        Session                  $session,
        AdyenLogger              $adyenLogger,
        StoreManagerInterface    $storeManager,
        Quote                    $quoteHelper,
        Config                   $configHelper,
        PaymentsDetails $paymentsDetailsHelper,
        PaymentResponseHandler $paymentResponseHandler,
        CartRepositoryInterface $cartRepository,
        OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context);

        $this->orderFactory = $orderFactory;
        $this->session = $session;
        $this->adyenLogger = $adyenLogger;
        $this->storeManager = $storeManager;
        $this->quoteHelper = $quoteHelper;
        $this->configHelper = $configHelper;
        $this->paymentsDetailsHelper = $paymentsDetailsHelper;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->cartRepository = $cartRepository;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function execute(): void
    {
        // Receive all params as this could be a GET or POST request
        $redirectResponse = $this->getRequest()->getParams();
        $storeId = $this->storeManager->getStore()->getId();

        if ($redirectResponse) {
            $result = $this->validateRedirectResponse($redirectResponse);

            // Adjust the success path, fail path, and restore quote based on if it is a multishipping quote
            if (
                !empty($redirectResponse['merchantReference']) &&
                $this->quoteHelper->getIsQuoteMultiShippingWithMerchantReference($redirectResponse['merchantReference'])
            ) {
                $successPath = $failPath = 'multishipping/checkout/success';
                $setQuoteAsActive = true;
            } else {
                $successPath = $this->configHelper->getAdyenAbstractConfigData('custom_success_redirect_path', $storeId) ??
                    'checkout/onepage/success';
                $failPath = $this->configHelper->getAdyenAbstractConfigData('return_path', $storeId);
                $setQuoteAsActive = false;
            }

            if ($result) {
                $quote = $this->session->getQuote();
                $quote->setIsActive($setQuoteAsActive);
                $this->cartRepository->save($quote);

                // Add OrderIncrementId to redirect parameters for headless support.
                $redirectParams = $this->configHelper->getAdyenAbstractConfigData('custom_success_redirect_path', $storeId)
                    ? ['_query' => ['utm_nooverride' => '1', 'order_increment_id' => $this->order->getIncrementId()]]
                    : ['_query' => ['utm_nooverride' => '1']];
                $this->_redirect($successPath, $redirectParams);
            } else {
                $this->adyenLogger->addAdyenResult(
                    sprintf(
                        'Payment for order %s was unsuccessful, ' .
                        'it will be cancelled when the OFFER_CLOSED notification has been processed.',
                        isset($this->order) ? $this->order->getIncrementId() :
                            ($redirectResponse['merchantReference'] ?? null)
                    )
                );

                $this->session->restoreQuote();
                $this->messageManager->addError(__('Your payment failed, Please try again later'));

                $this->_redirect($failPath, ['_query' => ['utm_nooverride' => '1']]);
            }
        } else {
            $this->_redirect($this->configHelper->getAdyenAbstractConfigData('return_path', $storeId));
        }
    }

    /**
     * @throws LocalizedException
     * @throws Exception
     */
    protected function validateRedirectResponse(array $redirectResponse): bool
    {
        $this->adyenLogger->addAdyenResult('Processing redirect response');
        $order = $this->getOrder($redirectResponse['merchantReference'] ?? null);

        try {
            // Make paymentsDetails call to validate the payment
            $request["details"] = $redirectResponse;
            $paymentsDetailsResponse = $this->paymentsDetailsHelper->initiatePaymentDetails($order, $request);
        } catch (Exception $e) {
            $paymentsDetailsResponse['error'] = $e->getMessage();
        }

        $result = $this->paymentResponseHandler->handlePaymentsDetailsResponse(
            $paymentsDetailsResponse,
            $order
        );

        if ($result) {
            $this->order = $order;
            $this->payment = $order->getPayment();
            $this->cleanUpRedirectAction();
        }

        return $result;
    }

    /**
     * @throws LocalizedException
     */
    private function getOrder(string $incrementId = null): Order
    {
        if ($incrementId !== null) {
            $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        } else {
            $order = $this->session->getLastRealOrder();
        }

        if (!$order->getId()) {
            throw new LocalizedException(
                __('Order cannot be loaded')
            );
        }

        return $order;
    }

    /**
     * @return void
     * @throws Exception
     */
    private function cleanUpRedirectAction(): void
    {
        // Prevent action component to redirect page again after returning to the shop
        $paymentAction = $this->order->getPayment()->getAdditionalInformation('action');
        $brandCode = $this->order->getPayment()->getAdditionalInformation('brand_code');
        $resultCode = $this->order->getPayment()->getAdditionalInformation('resultCode');

        if (($brandCode == self::BRAND_CODE_DOTPAY && $resultCode == self::RESULT_CODE_RECEIVED) ||
            (isset($paymentAction) && $paymentAction['type'] === 'redirect')
        ) {
            $this->payment->unsAdditionalInformation('action');
            $this->orderRepository->save($this->order);
        }
    }
}
