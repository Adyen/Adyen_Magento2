<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen NV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Helper\StateData;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;

class ChannelDataBuilder implements BuilderInterface
{
    private StateData $stateDataHelper;

    public function __construct(StateData $stateDataHelper)
    {
        $this->stateDataHelper = $stateDataHelper;
    }

    public function build(array $buildSubject): array
    {
        $order = SubjectReader::readPayment($buildSubject)->getPayment()->getOrder();
        $stateData = $order->getQuoteId() ? $this->stateDataHelper->getStateData((int)$order->getQuoteId()) : [];

        return [
            'body' => [
                'channel' => $stateData['channel'] ?? 'web'
            ]
        ];
    }
}
