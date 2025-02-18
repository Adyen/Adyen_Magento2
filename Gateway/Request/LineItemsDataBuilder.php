<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2025 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Helper\OpenInvoice;
use Adyen\Payment\Helper\PaymentMethods;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;

class LineItemsDataBuilder implements BuilderInterface
{
    public function __construct(
        private readonly PaymentMethods $paymentMethodsHelper,
        private readonly OpenInvoice $openInvoiceHelper
    ) { }

    /**
     * Add delivery\billing details into request
     *
     * @param array $buildSubject
     * @return array
     * @throws LocalizedException
     */
    public function build(array $buildSubject): array
    {
        /** @var PaymentDataObject $paymentDataObject */
        $paymentDataObject = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        $paymentMethodInstance = $payment->getMethodInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $storeId = $order->getStoreId();

        $requestBody = [];

        $isLineItemsRequired = $this->paymentMethodsHelper->getRequiresLineItems($paymentMethodInstance, $storeId);
        if ($isLineItemsRequired === true) {
            $requestLineItems = $this->openInvoiceHelper->getOpenInvoiceDataForOrder($order);
            $requestBody = array_merge($requestBody, $requestLineItems);
        }

        return [
            'body' => $requestBody
        ];
    }
}
