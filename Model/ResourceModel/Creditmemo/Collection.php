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

use Adyen\Payment\Model\Creditmemo as CreditmemoModel;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Creditmemo as CreditmemoResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    public function _construct(): void
    {
        $this->_init(CreditmemoModel::class, CreditmemoResourceModel::class);
    }

    /**
     * Get all the adyen_creditmemo linked to a magento invoice
     *
     * @deprecated
     *
     * @param int $creditMemoId
     * @return array
     */
    public function getAdyenCreditMemosLinkedToMagentoInvoice(int $creditMemoId): array
    {
        $select = $this->getConnection()->select()
            ->from(['adyen_creditmemo' => $this->getTable('adyen_creditmemo')])
            ->where('adyen_creditmemo.creditmemo_id=?', $creditMemoId);

        return $this->getConnection()->fetchAll($select);
    }
}
