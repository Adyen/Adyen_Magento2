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

namespace Adyen\Payment\Controller\Return;

use Adyen\Payment\Exception\PaymentMethodException;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods\AbstractWalletPaymentMethod;
use Adyen\Payment\Helper\PaymentMethods\PaymentMethodFactory;
use Adyen\Payment\Model\Method\PaymentMethodInterface;
use Adyen\Payment\Helper\Quote;
use Adyen\Payment\Helper\Recurring;
use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Methods\Paypal;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Adyen\Payment\Model\Ui\AdyenHppConfigProvider;
use Adyen\Payment\Model\Ui\AdyenOneclickConfigProvider;
use Adyen\Service\Validator\DataArrayValidator;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;

class Index extends \Magento\Framework\App\Action\Action
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

    /**
     * @var Data
     */
    protected $_adyenHelper;

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var Order
     */
    protected $_order;

    /**
     * @var HistoryFactory
     */
    protected $_orderHistoryFactory;

    /**
     * @var Session
     */
    protected $_session;

    /**
     * @var AdyenLogger
     */
    protected $_adyenLogger;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Quote
     */
    private $quoteHelper;

    private $payment;

    /**
     * @var Vault
     */
    private $vaultHelper;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order
     */
    private $orderResourceModel;

    /**
     * @var StateData
     */
    private $stateDataHelper;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * @var Config
     */
    private $configHelper;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Recurring
     */
    private $recurringHelper;

    /**
     * @var PaymentMethodFactory
     */
    private $paymentMethodFactory;

    /**
     * @param Context $context
     * @param Data $adyenHelper
     * @param OrderFactory $orderFactory
     * @param HistoryFactory $orderHistoryFactory
     * @param Session $session
     * @param AdyenLogger $adyenLogger
     * @param StoreManagerInterface $storeManager
     * @param Quote $quoteHelper
     * @param Vault $vaultHelper
     * @param \Magento\Sales\Model\ResourceModel\Order $orderResourceModel
     * @param StateData $stateDataHelper
     * @param Data $dataHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param Recurring $recurringHelper
     * @param Config $configHelper
     */
    public function __construct(
        Context $context,
        Data $adyenHelper,
        OrderFactory $orderFactory,
        HistoryFactory $orderHistoryFactory,
        Session $session,
        AdyenLogger $adyenLogger,
        StoreManagerInterface $storeManager,
        Quote $quoteHelper,
        Vault $vaultHelper,
        \Magento\Sales\Model\ResourceModel\Order $orderResourceModel,
        StateData $stateDataHelper,
        Data $dataHelper,
        OrderRepositoryInterface $orderRepository,
        Recurring $recurringHelper,
        Config $configHelper,
        PaymentMethodFactory $paymentMethodFactory
    ) {
        $this->_adyenHelper = $adyenHelper;
        $this->_orderFactory = $orderFactory;
        $this->_orderHistoryFactory = $orderHistoryFactory;
        $this->_session = $session;
        $this->_adyenLogger = $adyenLogger;
        $this->storeManager = $storeManager;
        $this->quoteHelper = $quoteHelper;
        $this->vaultHelper = $vaultHelper;
        $this->orderResourceModel = $orderResourceModel;
        $this->stateDataHelper = $stateDataHelper;
        $this->dataHelper = $dataHelper;
        $this->orderRepository = $orderRepository;
        $this->recurringHelper = $recurringHelper;
        $this->configHelper = $configHelper;
        $this->paymentMethodFactory = $paymentMethodFactory;
        parent::__construct($context);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        // Receive all params as this could be a GET or POST request
        $response = $this->getRequest()->getParams();

        if ($response) {
            $result = $this->validateResponse($response);

            // Adjust the success path, fail path, and restore quote based on if it is a multishipping quote
            if (
                !empty($response['merchantReference']) &&
                $this->quoteHelper->getIsQuoteMultiShippingWithMerchantReference($response['merchantReference'])
            ) {
                $successPath = $failPath = 'multishipping/checkout/success';
                $setQuoteAsActive = true;
            } else {
                $successPath = $this->_adyenHelper->getAdyenAbstractConfigData('custom_success_redirect_path') ?? 'checkout/onepage/success';
                $failPath = $this->_adyenHelper->getAdyenAbstractConfigData('return_path');
                $setQuoteAsActive = false;
            }
        } else {
            $this->_redirect($this->_adyenHelper->getAdyenAbstractConfigData('return_path'));
        }

        if ($result) {
            $session = $this->_session;
            $session->getQuote()->setIsActive($setQuoteAsActive)->save();

            // Prevent action component to redirect page with the payment method Dotpay Bank transfer / postal
            if (
                $this->_order->getPayment()->getAdditionalInformation('brand_code') == self::BRAND_CODE_DOTPAY &&
                $this->_order->getPayment()->getAdditionalInformation('resultCode') == self::RESULT_CODE_RECEIVED
            ) {
                $this->payment->unsAdditionalInformation('action');
                $this->_order->save();
            }

            // Add OrderIncrementId to redirect parameters for headless support.
            $redirectParams = $this->_adyenHelper->getAdyenAbstractConfigData('custom_success_redirect_path')
                ? ['_query' => ['utm_nooverride' => '1', 'order_increment_id' => $this->_order->getIncrementId()]]
                : ['_query' => ['utm_nooverride' => '1']];
            $this->_redirect($successPath, $redirectParams);
        } else {
            $this->_adyenLogger->addAdyenResult(
                sprintf(
                    'Payment for order %s was unsuccessful, ' .
                    'it will be cancelled when the OFFER_CLOSED notification has been processed.',
                    $this->_order->getIncrementId()
                )
            );
            $this->replaceCart($response);
            $this->_redirect($failPath, ['_query' => ['utm_nooverride' => '1']]);
        }
    }

    /**
     * @param $response
     */
    protected function replaceCart($response)
    {
        $this->_session->restoreQuote();

        if (isset($response['authResult']) && $response['authResult'] == \Adyen\Payment\Model\Notification::CANCELLED) {
            $this->messageManager->addError(__('You have cancelled the order. Please try again'));
        } else {
            $this->messageManager->addError(__('Your payment failed, Please try again later'));
        }
    }

    /**
     * @param $response
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateResponse($response)
    {
        $result = true;

        $this->_adyenLogger->addAdyenResult('Processing ResultUrl');

        // send the payload verification payment\details request to validate the response
        $response = $this->validatePayloadAndReturnResponse($response);

        $order = $this->_order;

        $this->_eventManager->dispatch(
            'adyen_payment_process_resulturl_before',
            [
                'order' => $order,
                'adyen_response' => $response
            ]
        );

        // Save PSP reference from the response
        if (!empty($response['pspReference'])) {
            $this->payment->setAdditionalInformation('pspReference', $response['pspReference']);
        }

        $paymentMethodInstance = $this->payment->getMethodInstance();
        $paymentInstanceCode = $paymentMethodInstance->getCode();

        if ($this->vaultHelper->hasRecurringDetailReference($response) && $paymentInstanceCode !== AdyenOneclickConfigProvider::CODE) {
            $storeId = $paymentMethodInstance->getStore();
            $storePaymentMethods = $this->configHelper->isStoreAlternativePaymentMethodEnabled($storeId);
            $cardVaultEnabled = $this->vaultHelper->isCardVaultEnabled($storeId);

            // If payment method is NOT card
            // Else if card
            if ($storePaymentMethods && $paymentMethodInstance instanceof PaymentMethodInterface) {
                try {
                    $this->payment->setAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE, true);
                    $this->vaultHelper->saveRecurringDetails($this->payment, $response['additionalData']);
                } catch (PaymentMethodException $e) {
                    $this->_adyenLogger->error(sprintf(
                        'Unable to create payment method with tx variant %s in details handler',
                        $response['additionalData']['paymentMethod']
                    ));
                }
            } elseif ($cardVaultEnabled && $paymentInstanceCode === AdyenCcConfigProvider::CODE) {
                $this->vaultHelper->saveRecurringCardDetails($this->payment, $response['additionalData']);
            }
        }

        // Save donation token if available in the response
        if (!empty($response['donationToken'])) {
            $this->payment->setAdditionalInformation('donationToken', $response['donationToken']);
        }

        // update the order
        $result = $this->_validateUpdateOrder($order, $response);

        $this->_eventManager->dispatch(
            'adyen_payment_process_resulturl_after',
            [
                'order' => $order,
                'adyen_response' => $response
            ]
        );

        return $result;
    }

    /**
     * @param Order $order
     * @param $response
     * @return bool
     */
    protected function _validateUpdateOrder($order, $response)
    {
        $result = false;

        if (!empty($response['authResult'])) {
            $authResult = $response['authResult'];
        } elseif (!empty($response['resultCode'])) {
            $authResult = $response['resultCode'];
        } else {
            // In case the result is unknown we log the request and don't update the history
            $this->_adyenLogger->error("Unexpected result query parameter. Response: " . json_encode($response));

            return $result;
        }

        $this->_adyenLogger->addAdyenResult('Updating the order');

        if (isset($response['paymentMethod']['brand'])) {
            $paymentMethod = $response['paymentMethod']['brand'];
        }
        elseif (isset($response['paymentMethod']['type'])) {
            $paymentMethod = $response['paymentMethod']['type'];
        }
        else {
            $paymentMethod = '';
        }

        $pspReference = isset($response['pspReference']) ? trim((string) $response['pspReference']) : '';

        $type = 'Adyen Result URL response:';
        $comment = __(
            '%1 <br /> authResult: %2 <br /> pspReference: %3 <br /> paymentMethod: %4',
            $type,
            $authResult,
            $pspReference,
            $paymentMethod
        );

        // needed because then we need to save $order objects
        $order->setAdyenResulturlEventCode($authResult);

        // Update the payment additional information with the new result code
        $orderPayment = $order->getPayment();
        $orderPayment->setAdditionalInformation('resultCode', $authResult);
        $this->orderResourceModel->save($order);

        switch (strtoupper((string) $authResult)) {
            case Notification::AUTHORISED:
                $result = true;
                $this->_adyenLogger->addAdyenResult('Do nothing wait for the notification');
                break;
            case Notification::RECEIVED:
                $result = true;
                if (strpos((string) $paymentMethod, "alipay_hk") !== false) {
                    $result = false;
                }
                $this->_adyenLogger->addAdyenResult('Do nothing wait for the notification');
                break;
            case Notification::PENDING:
                // do nothing wait for the notification
                $result = true;
                if (strpos((string) $paymentMethod, "bankTransfer") !== false) {
                    $comment .= "<br /><br />Waiting for the customer to transfer the money.";
                } elseif ($paymentMethod == "sepadirectdebit") {
                    $comment .= "<br /><br />This request will be send to the bank at the end of the day.";
                } else {
                    $comment .= "<br /><br />The payment result is not confirmed (yet).
                                 <br />Once the payment is authorised, the order status will be updated accordingly.
                                 <br />If the order is stuck on this status, the payment can be seen as unsuccessful.
                                 <br />The order can be automatically cancelled based on the OFFER_CLOSED notification.
                                 Please contact Adyen Support to enable this.";
                }
                $this->_adyenLogger->addAdyenResult('Do nothing wait for the notification');
                break;
            case Notification::CANCELLED:
            case Notification::ERROR:
                $this->_adyenLogger->addAdyenResult('Cancel or Hold the order on OFFER_CLOSED notification');
                $result = false;
                break;
            case Notification::REFUSED:
                // if refused there will be a AUTHORIZATION : FALSE notification send only exception is idea
                $this->_adyenLogger->addAdyenResult(
                    'Cancel or Hold the order on AUTHORISATION
                success = false notification'
                );
                $result = false;

                if (!$order->canCancel()) {
                    $order->setState(Order::STATE_NEW);
                    $this->orderRepository->save($order);
                }
                $this->dataHelper->cancelOrder($order);

                break;
            default:
                $this->_adyenLogger->addAdyenResult('This event is not supported: ' . $authResult);
                $result = false;
                break;
        }

        $history = $this->_orderHistoryFactory->create()
            ->setStatus($order->getStatus())
            ->setComment($comment)
            ->setEntityName('order')
            ->setOrder($order);

        $history->save();

        // Cleanup state data
        try {
            $this->stateDataHelper->CleanQuoteStateData($order->getQuoteId(), $authResult);
        } catch (\Exception $exception) {
            $this->_adyenLogger->error(__('Error cleaning the payment state data: %s', $exception->getMessage()));
        }


        return $result;
    }

    /**
     * Authenticate using sha256 Merchant signature
     *
     * @param $response
     * @return bool
     */
    protected function _authenticate($response)
    {
        $merchantSigNotification = $response['merchantSig'];

        // do it like this because $_GET is converting dot to underscore
        $queryString = $_SERVER['QUERY_STRING'];
        $result = [];
        $pairs = explode("&", (string) $queryString);

        foreach ($pairs as $pair) {
            $nv = explode("=", $pair);
            $name = urldecode($nv[0]);
            $value = urldecode($nv[1]);
            $result[$name] = $value;
        }

        // do not include the merchantSig in the merchantSig calculation
        unset($result['merchantSig']);

        // Sign request using secret key
        $hmacKey = $this->_adyenHelper->getHmac();
        $merchantSig = \Adyen\Util\Util::calculateSha256Signature($hmacKey, $result);

        if (strcmp($merchantSig, (string) $merchantSigNotification) === 0) {
            return true;
        }

        return false;
    }

    /**
     * The character escape function is called from the array_map function in _signRequestParams
     *
     * @param $val
     * @return mixed
     */
    protected function escapeString($val)
    {
        return str_replace(':', '\\:', str_replace('\\', '\\\\', (string) $val));
    }

    /**
     * Get order based on increment_id
     *
     * @param $incrementId
     * @return Order
     */
    protected function _getOrder($incrementId = null)
    {
        if (!$this->_order) {
            if ($incrementId !== null) {
                //TODO Replace with order repository search for best practice
                $this->_order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
            } else {
                $this->_order = $this->_session->getLastRealOrder();
            }
        }

        return $this->_order;
    }

    /**
     * Validates the payload from checkout /payments hpp and returns the api response
     *
     * @param $response
     * @return mixed
     * @throws \Adyen\AdyenException
     */
    protected function validatePayloadAndReturnResponse($result)
    {
        $client = $this->_adyenHelper->initializeAdyenClient($this->storeManager->getStore()->getId());
        $service = $this->_adyenHelper->createAdyenCheckoutService($client);

        $order = $this->_getOrder(
            !empty($result['merchantReference']) ? $result['merchantReference'] : null
        );

        if (!$order->getId()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Order cannot be loaded')
            );
        }

        $this->payment = $order->getPayment();

        $request = [];

        // filter details to match the keys
        $details = $result;
        // TODO build a validator class which also validates the type of the param
        $details = DataArrayValidator::getArrayOnlyWithApprovedKeys($details, self::DETAILS_ALLOWED_PARAM_KEYS);

        // Remove helper params in case left in the request
        $helperParams = ['isAjax', 'merchantReference'];
        foreach ($helperParams as $helperParam) {
            if (array_key_exists($helperParam, $details)) {
                unset($details[$helperParam]);
            }
        }

        $request["details"] = $details;

        try {
            $response = $service->paymentsDetails($request);
            $responseMerchantReference = !empty($response['merchantReference']) ? $response['merchantReference'] : null;
            $resultMerchantReference = !empty($result['merchantReference']) ? $result['merchantReference'] : null;
            $merchantReference = $responseMerchantReference ?: $resultMerchantReference;
            if ($merchantReference) {
                if ($order->getIncrementId() === $merchantReference) {
                    $this->_order = $order;
                } else {
                    $this->_adyenLogger->error("Wrong merchantReference was set in the query or in the session");
                    $response['error'] = 'merchantReference mismatch';
                }
            } else {
                $this->_adyenLogger->error("No merchantReference in the response");
                $response['error'] = 'merchantReference is missing from the response';
            }
        } catch (\Adyen\AdyenException $e) {
            $response['error'] = $e->getMessage();
        }

        return $response;
    }
}
