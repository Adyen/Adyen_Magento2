<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\ResourceModel\Creditmemo;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Creditmemo extends AbstractDb
{
    /**
     * Resource initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('adyen_creditmemo', 'entity_id');
    }

    /**
     * Get all the adyen_creditmemo entries linked to the adyen_order_payment
     *
     * @deprecated Use AdyenCreditmemoRepositoryInterface::getByAdyenOrderPaymentId() instead.
     *
     * @param int $adyenPaymentId
     * @return array|null
     */
    public function getAdyenCreditmemosByAdyenPaymentid(int $adyenPaymentId): ?array
    {
        $select = $this->getConnection()->select()
            ->from(['adyen_creditmemo' => $this->getTable('adyen_creditmemo')])
            ->where('adyen_creditmemo.adyen_order_payment_id=?', $adyenPaymentId);

        $result = $this->getConnection()->fetchAll($select);

        return empty($result) ? null : $result;
    }

    /**
     * @deprecated Use AdyenCreditmemoRepositoryInterface::getByRefundWebhook() instead.
     *
     * @param string $pspreference
     * @return array|null
     */
    public function getAdyenCreditmemoByPspreference(string $pspreference): ?array
    {
        $select = $this->getConnection()->select()
            ->from(['adyen_creditmemo' => $this->getTable('adyen_creditmemo')])
            ->where('adyen_creditmemo.pspreference=?', $pspreference);

        $result = $this->getConnection()->fetchRow($select);

        return empty($result) ? null : $result;
    }

    /**
     * @param string $pspreference
     * @return string
     * @throws LocalizedException
     */
    public function getIdByPspreference(string $pspreference): string
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from($this->getMainTable(), 'entity_id')
            ->where('pspreference = :pspreference');

        $bind = [':pspreference' => $pspreference];

        return $connection->fetchOne($select, $bind);
    }
}
