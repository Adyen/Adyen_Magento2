<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2024 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Setup;

use Adyen\Payment\Setup\RecurringData;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Adyen\Payment\Helper\PaymentMethodsFactory;
use Adyen\Payment\Helper\PaymentMethods;

class RecurringDataTest extends AbstractAdyenTestCase
{
    private RecurringData $recurringData;
    private PaymentMethodsFactory $paymentMethodsFactoryMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentMethodsFactoryMock = $this->createGeneratedMock(PaymentMethodsFactory::class, ['create']);
        $this->recurringData = new RecurringData($this->paymentMethodsFactoryMock);
    }

    public function testInstall()
    {
        $paymentMethods = $this->createMock(PaymentMethods::class);
        $this->paymentMethodsFactoryMock
            ->expects($this->once())
            ->method('create')
            ->willReturn($paymentMethods);
        $paymentMethods
            ->expects($this->once())
            ->method('togglePaymentMethodsActivation');
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $context = $this->createMock(ModuleContextInterface::class);

        $this->recurringData->install($setup, $context);
    }
}
