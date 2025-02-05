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

namespace Adyen\Payment\Helper;

use Adyen\Payment\Api\Data\CreditmemoInterface;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Api\Repository\AdyenCreditmemoRepositoryInterface;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory as OrderPaymentCollectionFactory;
use Adyen\Payment\Helper\Creditmemo as AdyenCreditmemoHelper;
use Exception;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierPool;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\Order\StatusResolver;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;

class Order extends AbstractHelper
{
    /**
     * @param Context $context
     * @param Builder $transactionBuilder
     * @param Data $dataHelper
     * @param AdyenLogger $adyenLogger
     * @param OrderSender $orderSender
     * @param TransactionFactory $transactionFactory
     * @param ChargedCurrency $chargedCurrency
     * @param AdyenOrderPayment $adyenOrderPaymentHelper
     * @param Config $configHelper
     * @param OrderStatusCollectionFactory $orderStatusCollectionFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepository $orderRepository
     * @param NotifierPool $notifierPool
     * @param OrderPaymentCollectionFactory $adyenOrderPaymentCollectionFactory
     * @param PaymentMethods $paymentMethodsHelper
     * @param Creditmemo $adyenCreditmemoHelper
     * @param StatusResolver $statusResolver
     * @param AdyenCreditmemoRepositoryInterface $adyenCreditmemoRepository
     */
    public function __construct(
        Context $context,
        private readonly Builder $transactionBuilder,
        private readonly Data $dataHelper,
        private readonly AdyenLogger $adyenLogger,
        private readonly OrderSender $orderSender,
        private readonly TransactionFactory $transactionFactory,
        private readonly ChargedCurrency $chargedCurrency,
        private readonly AdyenOrderPayment $adyenOrderPaymentHelper,
        private readonly Config $configHelper,
        private readonly OrderStatusCollectionFactory $orderStatusCollectionFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderRepository $orderRepository,
        private readonly NotifierPool $notifierPool,
        private readonly OrderPaymentCollectionFactory $adyenOrderPaymentCollectionFactory,
        private readonly PaymentMethods $paymentMethodsHelper,
        private readonly AdyenCreditmemoHelper $adyenCreditmemoHelper,
        private readonly MagentoOrder\StatusResolver $statusResolver,
        private readonly AdyenCreditmemoRepositoryInterface $adyenCreditmemoRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @param MagentoOrder $order
     * @param Notification $notification
     * @return TransactionInterface|null
     * @throws Exception
     */
    public function updatePaymentDetails(MagentoOrder $order, Notification $notification): ?TransactionInterface
    {
        //Set order state to new because with order state payment_review it is not possible to create an invoice
        if (strcmp($order->getState(), MagentoOrder::STATE_PAYMENT_REVIEW) == 0) {
            $order->setState(MagentoOrder::STATE_NEW);
        }

        $paymentObj = $order->getPayment();

        // set pspReference as transactionId
        $paymentObj->setCcTransId($notification->getPspreference());
        $paymentObj->setLastTransId($notification->getPspreference());

        // set transaction
        $paymentObj->setTransactionId($notification->getPspreference());
        // Prepare transaction
        $transaction = $this->transactionBuilder->setPayment($paymentObj)
            ->setOrder($order)
            ->setTransactionId($notification->getPspreference())
            ->build(TransactionInterface::TYPE_AUTH);

        $transaction->setIsClosed(false);
        $transaction->save();

        return $transaction;
    }

    /**
     * @param MagentoOrder $order
     * @param Notification $notification
     * @return MagentoOrder
     */
    public function addWebhookStatusHistoryComment(MagentoOrder $order, Notification $notification): MagentoOrder
    {
        $order->addStatusHistoryComment(__(sprintf(
            '%s webhook notification w/amount %s %s was processed',
            $notification->getEventCode(),
            $notification->getAmountCurrency(),
            $this->dataHelper->originalAmount($notification->getAmountValue(), $notification->getAmountCurrency())
        )), false);

        return $order;
    }

    /**
     * @param MagentoOrder $order
     */
    public function sendOrderMail(MagentoOrder $order)
    {
        try {
            $this->orderSender->send($order);
            $this->adyenLogger->addAdyenNotification(
                'Send order confirmation email to shopper',
                [
                    'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                    'merchantReference' => $order->getPayment()->getData('entity_id')
                ]
            );
        } catch (Exception $exception) {
            $this->adyenLogger->addAdyenWarning(
                "Exception in Send Mail in Magento. This is an issue in the the core of Magento" .
                $exception->getMessage()
            );
        }
    }

    /**
     * @param MagentoOrder $order
     * @return MagentoOrder
     *
     * TODO: Throw exception when order cannot be shipped
     */
    public function createShipment(MagentoOrder $order): MagentoOrder
    {
        // create shipment for cash payment
        if ($order->canShip()) {
            $itemQty = [];
            $shipment = $order->prepareShipment($itemQty);
            if ($shipment) {
                $shipment->register();
                $shipment->getOrder()->setIsInProcess(true);
                $comment = __('Shipment created by Adyen');
                $shipment->addComment($comment);

                $transaction = $this->transactionFactory->create();
                $transaction->addObject($shipment)
                    ->addObject($shipment->getOrder())
                    ->save();
            }
        } else {
            $this->adyenLogger->addAdyenNotification(
                'Order can\'t be shipped',
                [
                    'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                    'merchantReference' => $order->getPayment()->getData('entity_id')
                ]
            );
        }

        return $order;
    }

    /**
     * Finalize order by setting it to captured if manual capture is enabled, or authorized if auto capture is used
     * Full order will only NOT be finalized if the full amount has not been captured/authorized.
     *
     * @param MagentoOrder $order
     * @param Notification $notification
     * @return MagentoOrder
     */
    public function finalizeOrder(MagentoOrder $order, Notification $notification): MagentoOrder
    {
        $amount = $notification->getAmountValue();
        $orderAmountCurrency = $this->chargedCurrency->getOrderAmountCurrency($order, false);
        $formattedOrderAmount = $this->dataHelper->formatAmount($orderAmountCurrency->getAmount(), $orderAmountCurrency->getCurrencyCode());
        $fullAmountFinalized = $this->adyenOrderPaymentHelper->isFullAmountFinalized($order);

        $eventLabel = 'payment_authorized';
        $status = $this->configHelper->getConfigData(
            $eventLabel,
            'adyen_abstract',
            $order->getStoreId()
        );
        $possibleStates = Webhook::STATE_TRANSITION_MATRIX[$eventLabel];

        // virtual order can have different statuses
        if ($order->getIsVirtual()) {
            $status = $this->getVirtualStatus($order, $status);
        }

        // check for boleto if payment is totally paid
        if ($order->getPayment()->getMethod() == "adyen_boleto") {
            $status = $this->paymentMethodsHelper->getBoletoStatus($order, $notification, $status);
        }

        $order = $this->addProcessedStatusHistoryComment($order, $notification);
        if ($fullAmountFinalized) {
            $this->adyenLogger->addAdyenNotification(sprintf(
                'Notification w/amount %s has completed the capturing of order %s w/amount %s',
                $amount,
                $order->getIncrementId(),
                $formattedOrderAmount
            ),
            [
                'pspReference' => $notification->getPspreference(),
                'merchantReference' => $notification->getMerchantReference()
            ]);
            $comment = "Adyen Payment Successfully completed";
            // If a status is set, add comment, set status and update the state based on the status
            // Else add comment
            if (!empty($status)) {
                $order->addStatusHistoryComment(__($comment), $status);
                $this->setState($order, $status, $possibleStates);
                $this->adyenLogger->addAdyenNotification(
                    'Order status was changed to authorised status: ' . $status,
                    array_merge(
                        $this->adyenLogger->getOrderContext($order),
                        ['pspReference' => $notification->getPspreference()]
                    )
                );
            } else {
                $order->addStatusHistoryComment(__($comment));
                $this->adyenLogger->addAdyenNotification(sprintf(
                    'Order %s was finalized. Authorised status not set',
                    $order->getIncrementId()
                ),
                [
                    'pspReference' => $notification->getPspreference(),
                    'merchantReference' => $notification->getMerchantReference()
                ]);
            }
        } else {
            /*
             * Set order status back to pre_payment_authorized if the order state is payment_review.
             * Otherwise, capture-cancel-refund is not possible.
             */
            if ($order->getState() === MagentoOrder::STATE_PAYMENT_REVIEW) {
                $order = $this->setPrePaymentAuthorized($order);
            }
        }

        return $order;
    }

    /**
     * @param MagentoOrder $order
     * @param Notification $notification
     * @return MagentoOrder
     */
    public function addProcessedStatusHistoryComment(MagentoOrder $order, Notification $notification): MagentoOrder
    {
        $order->addStatusHistoryComment(__(sprintf(
            '%s webhook notification w/amount %s %s was processed',
            $notification->getEventCode(),
            $notification->getAmountCurrency(),
            $this->dataHelper->originalAmount($notification->getAmountValue(), $notification->getAmountCurrency())
        )), false);

        return $order;
    }

    /**
     * Set status on authorisation
     *
     * @param MagentoOrder $order
     *
     * @return MagentoOrder
     */
    public function setPrePaymentAuthorized(MagentoOrder $order): MagentoOrder
    {
        $eventLabel = "payment_pre_authorized";
        $status = $this->configHelper->getConfigData(
            $eventLabel,
            'adyen_abstract',
            $order->getStoreId()
        );
        $possibleStates = Webhook::STATE_TRANSITION_MATRIX[$eventLabel];

        // only do this if status in configuration is set
        if (!empty($status)) {
            $order->setStatus($status);
            $order = $this->setState($order, $status, $possibleStates);

            $this->adyenLogger->addAdyenNotification(
                'Order status is changed to Pre-authorised status, status is ' . $status,
                [
                    'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                    'merchantReference' => $order->getPayment()->getData('entity_id')
                ]
            );
        } else {
            $this->adyenLogger->addAdyenNotification(
                'No pre-authorised status is used so ignore',
                [
                    'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                    'merchantReference' => $order->getPayment()->getData('entity_id')
                ]
            );
        }

        return $order;
    }

    public function setStatusOrderCreation(OrderInterface $order): OrderInterface
    {
        $paymentMethod = $order->getPayment()->getMethod();

        // Fetch the default order status for order creation from the configuration.
        $status = $this->configHelper->getConfigData(
            'order_status',
            $paymentMethod,
            $order->getStoreId()
        );

        if (is_null($status)) {
            // If the configuration doesn't exist, use the default status.
            $status = $this->statusResolver->getOrderStatusByState($order, MagentoOrder::STATE_NEW);
        }

        $order->setStatus($status);
        $order->setState(MagentoOrder::STATE_NEW);

        return $order;
    }

    /**
     * @param MagentoOrder $order
     * @param $ignoreHasInvoice
     * @return MagentoOrder
     * @throws LocalizedException
     */
    public function holdCancelOrder(MagentoOrder $order, $ignoreHasInvoice): MagentoOrder
    {
        if (!$this->configHelper->getNotificationsCanCancel($order->getStoreId())) {
            $this->adyenLogger->addAdyenNotification(
                'Order cannot be cancelled based on the plugin configuration',
                [
                    'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                    'merchantReference' => $order->getPayment()->getData('entity_id')
                ]
            );
            return $order;
        }

        $orderStatus = $this->configHelper->getConfigData(
            'payment_cancelled',
            'adyen_abstract',
            $order->getStoreId()
        );

        // check if order has in invoice only cancel/hold if this is not the case
        if ($ignoreHasInvoice || !$order->hasInvoices()) {
            if ($orderStatus == MagentoOrder::STATE_HOLDED) {
                // Allow magento to hold order
                $order->setActionFlag(MagentoOrder::ACTION_FLAG_HOLD, true);

                if ($order->canHold()) {
                    $order->hold();
                    $order->addCommentToStatusHistory('Order held', $orderStatus);
                } else {
                    $this->adyenLogger->addAdyenNotification(
                        'Order can not hold or is already on Hold',
                        [
                            'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                            'merchantReference' => $order->getPayment()->getData('entity_id')
                        ]
                    );
                }
            } else {
                // Allow magento to cancel order
                $order->setActionFlag(MagentoOrder::ACTION_FLAG_CANCEL, true);

                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addCommentToStatusHistory('Order cancelled', $orderStatus ?? false);
                } else {
                    $this->adyenLogger->addAdyenNotification(
                        'Order can not be cancelled',
                        [
                            'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                            'merchantReference' => $order->getPayment()->getData('entity_id')
                        ]
                    );
                }
            }
        } else {
            $this->adyenLogger->addAdyenNotification(sprintf(
                    'Order %s already has an invoice linked so it cannot be cancelled', $order->getIncrementId()
            ), [
                'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                'merchantReference' => $order->getPayment()->getData('entity_id')
            ]);
        }

        return $order;
    }

    public function addRefundFailedNotice(MagentoOrder $order, Notification $notification): Notification
    {
        $description = __(
            "Reason: %1 | PSPReference: %2 | You can go to Adyen Customer Area
                and trigger this refund manually or contact our support.",
            $notification->getReason(),
            $notification->getPspreference()
        );

        $this->notifierPool->addNotice(
            __("Adyen: Refund for order #%1 has failed", $notification->getMerchantReference()),
            $description,
            $this->dataHelper->getPspReferenceSearchUrl($notification->getPspreference(), $notification->getLive())
        );

        $order->addStatusHistoryComment(__(
            sprintf('Refund has failed. Unable to change back status of the order.<br /> %s', $description)
        ), $order->getStatus());

        $linkedAdyenCreditmemo = $this->adyenCreditmemoRepository->getByRefundWebhook($notification);

        if (isset($linkedAdyenCreditmemo)) {
            $this->adyenCreditmemoHelper->updateAdyenCreditmemosStatus(
                $linkedAdyenCreditmemo,
                CreditmemoInterface::FAILED_STATUS
            );
        }

        return $notification;
    }

    /**
     * Set order state, based on the passed status
     *
     * @param MagentoOrder $order
     * @param $status
     * @param $possibleStates
     * @return MagentoOrder
     */
    private function setState(MagentoOrder $order, $status, $possibleStates): MagentoOrder
    {
        // Loop over possible states, select first available status that fits this state
        foreach ($possibleStates as $state) {
            $statusObject = $this->orderStatusCollectionFactory->create()
                ->addFieldToFilter('main_table.status', $status)
                ->joinStates()
                ->addStateFilter($state)
                ->getFirstItem();

            if ($statusObject->getState() == $state) {
                // Exit function if fitting state is found
                $order->setState($statusObject->getState());
                $this->adyenLogger->addAdyenNotification(
                    'State is changed to ' . $statusObject->getState(),
                    [
                        'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                        'merchantReference' => $order->getPayment()->getData('entity_id')
                    ]
                );

                return $order;
            }
        }

        $this->adyenLogger->addAdyenNotification(
            'No new state assigned, status should be connected to one of the following states: ' . json_encode($possibleStates),
            [
                'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                'merchantReference' => $order->getPayment()->getData('entity_id')
            ]);

        return $order;
    }

    /**
     * Set the order data member by fetching the entity from the database.
     * This should be moved out of this file in the future.
     * @param Notification $notification
     * @return false|\Magento\Sales\Api\Data\OrderInterface
     */
    public function fetchOrderByIncrementId(Notification $notification)
    {
        $incrementId = $notification->getMerchantReference();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId, 'eq')
            ->create();

        $orderList = $this->orderRepository->getList($searchCriteria)->getItems();

        /** @var MagentoOrder $order */
        return reset($orderList);
    }

    /**
     * @param MagentoOrder $order
     * @param Notification $notification
     * @return void
     * @throws Exception
     */
    public function refundOrder(MagentoOrder $order, Notification $notification): MagentoOrder
    {
        /*
         * Update AdyenOrderPayment entity with the refund
         */
        /** @var OrderPaymentInterface $orderPayment */
        $orderPayment = $this->adyenOrderPaymentCollectionFactory
            ->create()
            ->addFieldToFilter(Notification::PSPREFRENCE, $notification->getOriginalReference())
            ->getFirstItem();

        $this->adyenOrderPaymentHelper->refundAdyenOrderPayment($orderPayment, $notification);
        $this->adyenLogger->addAdyenNotification(
            sprintf(
                'Refunding %s from AdyenOrderPayment %s',
                $notification->getAmountCurrency() . $notification->getAmountValue(),
                $orderPayment->getEntityId()
            ),
            array_merge(
                $this->adyenLogger->getOrderContext($order),
                ['pspReference' => $notification->getPspreference()]
            )
        );

        /*
         * Check adyen_creditmemo table.
         * If credit memo doesn't exist for this notification, create it.
         */
        $linkedAdyenCreditmemo = $this->adyenCreditmemoRepository->getByRefundWebhook($notification);

        if (is_null($linkedAdyenCreditmemo)) {
            if ($order->canCreditmemo()) {
                $amount = $this->dataHelper->originalAmount(
                    $notification->getAmountValue(),
                    $notification->getAmountCurrency()
                );

                $linkedAdyenCreditmemo = $this->adyenCreditmemoHelper->createAdyenCreditMemo(
                    $order->getPayment(),
                    $notification->getPspreference(),
                    $notification->getOriginalReference(),
                    $amount
                );

                /*
                 * Following method will try to create the credit memo for this order.
                 * If the credit memo can't be created, this method will also provide comment history to warn
                 * merchant to create an offline credit memo.
                 * For the Adyen Customer Area initiated refunds, order currency and the store currency should match
                 * and the full amount should be refunded at once.
                 */
                $payment = $order->getPayment()->registerRefundNotification($amount);

                if (!is_null($payment->getCreditmemo())) {
                    /*
                     * Since the full amount is refunded and the credit memo is created,
                     * now the order can be closed by plugin. This call is required since
                     * `registerRefundNotification()` function changes the status to `processing` again.
                     */
                    $order->setState(MagentoOrder::STATE_CLOSED);
                    $order->setStatus($order->getConfig()->getStateDefaultStatus(MagentoOrder::STATE_CLOSED));

                    $this->adyenLogger->addAdyenNotification(
                        sprintf('Created credit memo for order %s', $order->getIncrementId()),
                        array_merge(
                            $this->adyenLogger->getOrderContext($order),
                            ['pspReference' => $notification->getPspreference()]
                        )
                    );
                }
            } else {
                $this->adyenLogger->addAdyenNotification(
                    sprintf(
                        'Could not create a credit memo for order %s while processing notification %s',
                        $order->getIncrementId(),
                        $notification->getId()
                    ),
                    array_merge(
                        $this->adyenLogger->getOrderContext($order),
                        ['pspReference' => $notification->getPspreference()]
                    )
                );
            }
        } else {
            $this->adyenLogger->addAdyenNotification(
                sprintf(
                    'Did not create a credit memo for order %s. '
                    . 'Because credit memo already exists for this refund request %s',
                    $order->getIncrementId(),
                    $notification->getPspreference()
                ),
                array_merge(
                    $this->adyenLogger->getOrderContext($order),
                    ['pspReference' => $notification->getPspreference()]
                )
            );
        }

        $this->adyenCreditmemoHelper->updateAdyenCreditmemosStatus(
            $linkedAdyenCreditmemo, CreditmemoInterface::COMPLETED_STATUS
        );

        $order->addStatusHistoryComment(__(sprintf(
            '%s Webhook successfully handled',
            $notification->getEventCode())), $order->getStatus());
        return $order;
    }

    public function getOrderByIncrementId(string $incrementId): ?OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        return $orders ? reset($orders) : null;
    }

    /**
     * If the payment_authorized_virtual config is set, return the virtual status
     *
     * @param MagentoOrder $order
     * @param $status
     * @return mixed
     */
    private function getVirtualStatus(MagentoOrder $order, $status)
    {
        $this->adyenLogger->addAdyenNotification(
            'Product is a virtual product',
            [
                'pspReference' => $order->getPayment()->getData('adyen_psp_reference'),
                'merchantReference' => $order->getPayment()->getData('entity_id')
            ]);
        $virtualStatus = $this->configHelper->getConfigData(
            'payment_authorized_virtual',
            'adyen_abstract',
            $order->getStoreId()
        );
        if ($virtualStatus != "") {
            $status = $virtualStatus;
        }

        return $status;
    }
}
