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
// phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
namespace Adyen\Payment\Model;

use Adyen\Payment\Api\Data\NotificationInterface;
use DateTime;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

class Notification extends AbstractModel implements NotificationInterface
{
    const AUTHORISATION = 'AUTHORISATION';
    const PENDING = 'PENDING';
    const AUTHORISED = 'AUTHORISED';
    const RECEIVED = 'RECEIVED';
    const CANCELLED = 'CANCELLED';
    const REFUSED = 'REFUSED';
    const ERROR = 'ERROR';
    const REFUND = 'REFUND';
    const REFUND_FAILED = 'REFUND_FAILED';
    const CANCEL_OR_REFUND = 'CANCEL_OR_REFUND';
    const CAPTURE = 'CAPTURE';
    const CAPTURE_FAILED = 'CAPTURE_FAILED';
    const CANCELLATION = 'CANCELLATION';
    const POSAPPROVED = 'POS_APPROVED';
    const HANDLED_EXTERNALLY = 'HANDLED_EXTERNALLY';
    const MANUAL_REVIEW_ACCEPT = 'MANUAL_REVIEW_ACCEPT';
    const MANUAL_REVIEW_REJECT = 'MANUAL_REVIEW_REJECT';
    const RECURRING_CONTRACT = "RECURRING_CONTRACT";
    const REPORT_AVAILABLE = "REPORT_AVAILABLE";
    const ORDER_OPENED = 'ORDER_OPENED';
    const ORDER_CLOSED = "ORDER_CLOSED";
    const OFFER_CLOSED = "OFFER_CLOSED";
    const CHARGEBACK = "CHARGEBACK";
    const SECOND_CHARGEBACK = "SECOND_CHARGEBACK";
    const CHARGEBACK_REVERSED = "CHARGEBACK_REVERSED";
    const REQUEST_FOR_INFORMATION = "REQUEST_FOR_INFORMATION";
    const NOTIFICATION_OF_CHARGEBACK = "NOTIFICATION_OF_CHARGEBACK";
    const STATE_ADYEN_AUTHORIZED = "adyen_authorized";
    const MAX_ERROR_COUNT = 5;

    public function __construct(
        Context $context,
        Registry $registry,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init(ResourceModel\Notification::class);
    }

    /**
     * Check if the Adyen Notification is already stored in the system
     *
     * @param null $done
     * @return bool
     */
    public function isDuplicate($done = null): bool
    {
        /*
         * Load resource model Adyen\Payment\Model\ResourceModel\Notification manually
         * as Magento\Framework\Model\AbstractModel::_getResource() method has been deprecated.
         * ObjectManager has been used due to dependencies not being loaded timely manner on entity classes.
         */
        $notificationResourceModel = ObjectManager::getInstance()->get($this->getResourceName());

        $result = $notificationResourceModel->getNotification(
            $this->getPspreference(),
            $this->getEventCode(),
            $this->getSuccess(),
            $this->getOriginalReference(),
            $done
        );

        return !empty($result);
    }

    /**
     * Remove OFFER_CLOSED and AUTHORISATION success=false notifications for some time from the processing list
     * to ensure they won't close any order which has an AUTHORISED notification arrived a bit later than the
     * OFFER_CLOSED or the AUTHORISATION success=false one.
     */
    public function shouldSkipProcessing(): bool
    {
        if ((
                self::OFFER_CLOSED === $this->getEventCode() ||
                self::ORDER_CLOSED === $this->getEventCode() ||
                (self::AUTHORISATION === $this->getEventCode() && !$this->isSuccessful())
            ) &&
            $this->isLessThan10MinutesOld()
        ) {
            return true;
        }

        return false;
    }

    public function getPspreference(): ?string
    {
        return $this->getData(self::PSPREFRENCE);
    }

    public function setPspreference(string $pspReference): NotificationInterface
    {
        return $this->setData(self::PSPREFRENCE, $pspReference);
    }

    public function getOriginalReference(): ?string
    {
        return $this->getData(self::ORIGINAL_REFERENCE);
    }

    public function setOriginalReference(?string $originalReference): NotificationInterface
    {
        return $this->setData(self::ORIGINAL_REFERENCE, $originalReference);
    }

    public function getMerchantReference(): ?string
    {
        return $this->getData(self::MERCHANT_REFERENCE);
    }

    public function setMerchantReference(string $merchantReference): NotificationInterface
    {
        return $this->setData(self::MERCHANT_REFERENCE, $merchantReference);
    }

    public function getEventCode(): ?string
    {
        return $this->getData(self::EVENT_CODE);
    }

    public function setEventCode(string $eventCode): NotificationInterface
    {
        return $this->setData(self::EVENT_CODE, $eventCode);
    }

    public function getSuccess(): ?string
    {
        return $this->getData(self::SUCCESS);
    }

    public function setSuccess(string $success): NotificationInterface
    {
        return $this->setData(self::SUCCESS, $success);
    }

    public function isSuccessful(): bool
    {
        return strcmp($this->getSuccess(), 'true') === 0 || strcmp($this->getSuccess(), '1') === 0;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->getData(self::PAYMENT_METHOD);
    }

    public function setPaymentMethod(string $paymentMethod): NotificationInterface
    {
        return $this->setData(self::PAYMENT_METHOD, $paymentMethod);
    }

    public function getAmountValue(): ?int
    {
        return $this->getData(self::AMOUNT_VALUE);
    }

    public function setAmountValue(int $amountValue): NotificationInterface
    {
        return $this->setData(self::AMOUNT_VALUE, $amountValue);
    }

    public function getAmountCurrency(): ?string
    {
        return $this->getData(self::AMOUNT_CURRENCY);
    }

    public function setAmountCurrency(string $amountCurrency): NotificationInterface
    {
        return $this->setData(self::AMOUNT_CURRENCY, $amountCurrency);
    }

    public function getReason(): ?string
    {
        return $this->getData(self::REASON);
    }

    public function setReason(string $reason): NotificationInterface
    {
        return $this->setData(self::REASON, $reason);
    }

    public function getLive(): ?string
    {
        return $this->getData(self::LIVE);
    }

    public function setLive(string $live): NotificationInterface
    {
        return $this->setData(self::LIVE, $live);
    }

    public function getAdditionalData(): ?string
    {
        return $this->getData(self::ADDITIONAL_DATA);
    }

    public function setAdditionalData(string $additionalData): NotificationInterface
    {
        return $this->setData(self::ADDITIONAL_DATA, $additionalData);
    }

    public function getDone(): ?bool
    {
        return $this->getData(self::DONE);
    }

    public function setDone(bool $done): NotificationInterface
    {
        return $this->setData(self::DONE, $done);
    }

    public function getProcessing(): bool
    {
        return $this->getData(self::PROCESSING);
    }

    public function setProcessing(bool $processing): NotificationInterface
    {
        return $this->setData(self::PROCESSING, $processing);
    }

    public function getErrorCount(): ?int
    {
        return $this->getData(self::ERROR_COUNT);
    }

    public function setErrorCount(int $errorCount): NotificationInterface
    {
        return $this->setData(self::ERROR_COUNT, $errorCount);
    }

    public function getErrorMessage(): ?string
    {
        return $this->getData(self::ERROR_MESSAGE);
    }

    public function setErrorMessage(string $errorMessage): NotificationInterface
    {
        return $this->setData(self::ERROR_MESSAGE, $errorMessage);
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    public function setCreatedAt(string $createdAt): NotificationInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        return  $this->getData(self::UPDATED_AT);
    }

    public function setUpdatedAt(string $timestamp): NotificationInterface
    {
        return $this->setData(self::UPDATED_AT, $timestamp);
    }

    public function isLessThan10MinutesOld(): bool
    {
        $createdAt = DateTime::createFromFormat('Y-m-d H:i:s', $this->getCreatedAt());
        $tenMinutesAgo = new DateTime('-10 minutes');

        return $createdAt >= $tenMinutesAgo;
    }
}
