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

namespace Adyen\Payment\Helper;

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Api\Repository\AdyenCreditmemoRepositoryInterface;
use Adyen\Payment\Model\Order\Payment;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Creditmemo as CreditMemoResourceModel;
use Adyen\Payment\Model\CreditmemoFactory;
use Adyen\Payment\Api\Data\CreditmemoInterface as AdyenCreditmemoInterface;
use Adyen\Payment\Model\ResourceModel\Order\Payment as OrderPaymentResourceModel;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo as MagentoCreditMemoModel;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

/**
 * Helper class for anything related to the creditmemo entity
 *
 * @package Adyen\Payment\Helper
 */
class Creditmemo extends AbstractHelper
{
    /**
     * @param Context $context
     * @param Data $adyenDataHelper
     * @param CreditmemoFactory $adyenCreditmemoFactory
     * @param CreditMemoResourceModel $adyenCreditmemoResourceModel
     * @param OrderPaymentResourceModel $orderPaymentResourceModel
     * @param AdyenCreditmemoRepositoryInterface $adyenCreditmemoRepository
     */
    public function __construct(
        Context $context,
        protected Data $adyenDataHelper,
        private readonly CreditmemoFactory $adyenCreditmemoFactory,
        private readonly CreditmemoResourceModel $adyenCreditmemoResourceModel,
        protected OrderPaymentResourceModel $orderPaymentResourceModel,
        private readonly AdyenCreditmemoRepositoryInterface $adyenCreditmemoRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Link all the adyen_creditmemos related to the adyen_order_payment with the passed creditMemoModel
     *
     * @param Order\Payment $payment
     * @param string $pspReference
     * @param string $originalReference
     * @param float $refundAmount
     * @return AdyenCreditmemoInterface
     */
    public function createAdyenCreditMemo(
        Order\Payment $payment,
        string $pspReference,
        string $originalReference,
        float $refundAmount
    ): AdyenCreditmemoInterface {
        // get adyen_order_payment record
        /** @var OrderPaymentInterface $adyenOrderPayment */
        $adyenOrderPayment = $this->orderPaymentResourceModel->getOrderPaymentDetails(
            $originalReference,
            $payment->getEntityId()
        );

        // create adyen_credit_memo record
        /** @var AdyenCreditmemoInterface $adyenCreditmemo */
        $adyenCreditmemo = $this->adyenCreditmemoFactory->create();
        $adyenCreditmemo->setPspreference($pspReference);
        $adyenCreditmemo->setOriginalReference($originalReference);
        $adyenCreditmemo->setAdyenPaymentOrderId(
            $adyenOrderPayment[OrderPaymentInterface::ENTITY_ID]
        );
        $adyenCreditmemo->setAmount($refundAmount);
        $adyenCreditmemo->setStatus(AdyenCreditmemoInterface::WAITING_FOR_WEBHOOK_STATUS);

        $this->adyenCreditmemoRepository->save($adyenCreditmemo);

        return $adyenCreditmemo;
    }

    /**
     * Link all the adyen_creditmemos related to the adyen_order_payment with the given magento entity of the creditmemo
     *
     * @param Payment $adyenOrderPayment
     * @param MagentoCreditMemoModel $magentoCreditmemo
     * @return void
     */
    public function linkAndUpdateAdyenCreditmemos(
        Payment $adyenOrderPayment,
        MagentoCreditmemoModel $magentoCreditmemo
    ): void {
        $adyenCreditmemos = $this->adyenCreditmemoRepository->getByAdyenOrderPaymentId(
            $adyenOrderPayment->getEntityId()
        );

        if (isset($adyenCreditmemos)) {
            foreach ($adyenCreditmemos as $adyenCreditmemo) {
                if ($adyenCreditmemo->getAmount() == $magentoCreditmemo->getGrandTotal()) {
                    $adyenCreditmemo->setCreditmemoId($magentoCreditmemo->getEntityId());
                    $this->adyenCreditmemoRepository->save($adyenCreditmemo);

                    break;
                }
            }
        }
    }

    /**
     * @param AdyenCreditmemoInterface $adyenCreditmemo
     * @param string $status
     * @return void
     */
    public function updateAdyenCreditmemosStatus(AdyenCreditmemoInterface $adyenCreditmemo, string $status)
    {
        $adyenCreditmemo->setStatus($status);
        $this->adyenCreditmemoRepository->save($adyenCreditmemo);
    }

    /**
     * @deprecated Use AdyenCreditmemoRepositoryInterface::getByRefundWebhook() instead.
     *
     * @param string $pspreference
     * @return AdyenCreditmemoInterface|null
     * @throws NoSuchEntityException
     */
    public function getAdyenCreditmemoByPspreference(string $pspreference): ?AdyenCreditmemoInterface {
        $results = $this->adyenCreditmemoResourceModel->getAdyenCreditmemoByPspreference($pspreference);

        if (is_null($results)) {
            return null;
        }

        return $this->adyenCreditmemoRepository->get($results['entity_id']);
    }
}
