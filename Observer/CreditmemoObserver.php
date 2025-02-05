<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Observer;

use Adyen\Payment\Api\Repository\AdyenOrderPaymentRepositoryInterface;
use Adyen\Payment\Helper\Creditmemo as CreditMemoHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;

class CreditmemoObserver implements ObserverInterface
{
    /**
     * @param CreditMemoHelper $creditmemoHelper
     * @param AdyenOrderPaymentRepositoryInterface $adyenOrderPaymentRepository
     */
    public function __construct(
        private readonly CreditmemoHelper $creditmemoHelper,
        private readonly AdyenOrderPaymentRepositoryInterface $adyenOrderPaymentRepository
    ) { }

    /**
     * Link all adyen_creditmemos to the appropriate magento credit memo and set the order to PROCESSING to allow
     * further credit memos to be generated
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        /** @var Creditmemo $creditmemo */
        $creditmemo = $observer->getData('creditmemo');
        $order = $creditmemo->getOrder();
        $payment = $order->getPayment();

        $adyenOrderPayments = $this->adyenOrderPaymentRepository->getByPaymentId($payment->getEntityId());

        foreach ($adyenOrderPayments as $adyenOrderPayment) {
            $this->creditmemoHelper->linkAndUpdateAdyenCreditmemos($adyenOrderPayment, $creditmemo);
        }
    }
}
