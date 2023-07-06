<?php

/**
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V  .
 */

namespace Adyen\Payment\Model\Methods;

use Adyen\Payment\Block\Form\Hpp;
use Adyen\Payment\Block\Info\Hpp as HppInfo;
use Adyen\Payment\Model\AdyenPaymentMethod;
use Adyen\Payment\Model\Api\PaymentRequest;
use Adyen\Payment\Model\Method\PaymentMethodInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;

class SepaDirectDebit extends AdyenPaymentMethod implements PaymentMethodInterface
{
	public const CODE = 'adyen_sepadirectdebit';
	public const TX_VARIANT = 'sepadirectdebit';
	public const NAME = 'SEPA Direct Debit';

    public function __construct(
        PaymentRequest $paymentRequest,
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        $infoBlockType
    ) {
        $code = self::CODE;
        $formBlockType = Hpp::class;

        parent::__construct($paymentRequest, $eventManager, $valueHandlerPool, $paymentDataObjectFactory,
            $code, $formBlockType, $infoBlockType);
    }

	public function supportsRecurring(): bool
	{
		return true;
	}


	public function supportsManualCapture(): bool
	{
		return true;
	}


	public function supportsAutoCapture(): bool
	{
		return true;
	}


	public function supportsCardOnFile(): bool
	{
		return false;
	}


	public function supportsSubscription(): bool
	{
		return true;
	}


	public function supportsUnscheduledCardOnFile(): bool
	{
		return true;
	}


	public function isWallet(): bool
	{
		return false;
	}
}
