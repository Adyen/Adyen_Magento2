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

namespace Adyen\Payment\Test\Gateway\Request;

use Adyen\Payment\Gateway\Request\ExpiryDateDataBuilder;
use Adyen\Payment\Observer\AdyenPayByLinkDataAssignObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\RequestInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class ExpiryDateDataBuilderTest extends AbstractAdyenTestCase
{
    protected ?ExpiryDateDataBuilder $expiryDateDataBuilder;
    protected RequestInterface|MockObject $requestMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->requestMock = $this->createMock(RequestInterface::class);

        $this->expiryDateDataBuilder = new ExpiryDateDataBuilder($this->requestMock);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->expiryDateDataBuilder = null;
    }

    /**
     * @return void
     */
    public function testBuildFromRequest()
    {
        $paymentMock = $this->createMock(Payment::class);

        $paymentDataObjectMock = $this->createConfiguredMock(PaymentDataObject::class, [
            'getPayment' => $paymentMock
        ]);

        $buildSubject = [
            'payment' => $paymentDataObjectMock
        ];

        $formFields = [
            AdyenPayByLinkDataAssignObserver::PBL_EXPIRY_DATE => '01-01-1970'
        ];

        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with('payment')
            ->willReturn($formFields);

        $request = $this->expiryDateDataBuilder->build($buildSubject);

        $this->assertArrayHasKey('body', $request);
        $this->assertArrayHasKey('expiresAt', $request['body']);
        $this->assertEquals('1970-01-01T23:59:59+00:00', $request['body']['expiresAt']);
    }

    /**
     * @return void
     */
    public function testBuildFromPayment()
    {
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getAdditionalInformation')
            ->with(AdyenPayByLinkDataAssignObserver::PBL_EXPIRY_DATE)
            ->willReturn('01-01-1970');

        $paymentDataObjectMock = $this->createConfiguredMock(PaymentDataObject::class, [
            'getPayment' => $paymentMock
        ]);

        $buildSubject = [
            'payment' => $paymentDataObjectMock
        ];

        $formFields = [];

        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with('payment')
            ->willReturn($formFields);

        $request = $this->expiryDateDataBuilder->build($buildSubject);

        $this->assertArrayHasKey('body', $request);
        $this->assertArrayHasKey('expiresAt', $request['body']);
        $this->assertEquals('1970-01-01T23:59:59+00:00', $request['body']['expiresAt']);
    }
}
