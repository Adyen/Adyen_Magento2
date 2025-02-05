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

namespace Adyen\Payment\Test\Gateway\Request;

use Adyen\Payment\Gateway\Request\ShopperInteractionDataBuilder;
use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Model\Method\Adapter;
use Adyen\Payment\Model\Ui\Adminhtml\AdyenMotoConfigProvider;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Adyen\Payment\Model\Ui\AdyenPayByLinkConfigProvider;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Model\Context;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class ShopperInteractionDataBuilderTest extends AbstractAdyenTestCase
{
    private ShopperInteractionDataBuilder $shopperInteractionDataBuilder;
    private State|MockObject $appState;
    private StateData|MockObject $stateData;
    private Context|MockObject $contextMock;

    protected function setUp(): void
    {
        $this->appState = $this->createMock(State::class);
        $this->stateData = $this->createPartialMock(StateData::class, [
            'getStateData'
        ]);
        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getAppState')->willReturn($this->appState);

        $this->shopperInteractionDataBuilder = new ShopperInteractionDataBuilder(
            $this->contextMock,
            $this->stateData
        );
    }

    public function testPayByLinkRequest()
    {
        $buildSubject = [
            'payment' => $this->createConfiguredMock(PaymentDataObject::class, [
                'getPayment' => $this->createConfiguredMock(Payment::class, [
                    'getMethodInstance' => $this->createConfiguredMock(Adapter::class, [
                        'getCode' => AdyenPayByLinkConfigProvider::CODE
                    ])
                ])
            ])
        ];

        $this->assertEmpty($this->shopperInteractionDataBuilder->build($buildSubject));
    }

    public function testMotoRequest()
    {
        $buildSubject = [
            'payment' => $this->createConfiguredMock(PaymentDataObject::class, [
                'getPayment' => $this->createConfiguredMock(Payment::class, [
                    'getMethodInstance' => $this->createConfiguredMock(Adapter::class, [
                        'getCode' => AdyenMotoConfigProvider::CODE
                    ]),
                    'getOrder' => $this->createConfiguredMock(Order::class, [
                        'getQuoteId' => 1
                    ])
                ])
            ])
        ];

        $this->appState->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);
        $this->stateData->method('getStateData')->willReturn([]);

        $request = $this->shopperInteractionDataBuilder->build($buildSubject);

        $this->assertArrayHasKey('shopperInteraction', $request['body']);
        $this->assertEquals(
            ShopperInteractionDataBuilder::SHOPPER_INTERACTION_MOTO,
            $request['body']['shopperInteraction']
        );
    }

    public function testRecurringRequest()
    {
        $buildSubject = [
            'payment' => $this->createConfiguredMock(PaymentDataObject::class, [
                'getPayment' => $this->createConfiguredMock(Payment::class, [
                    'getMethodInstance' => $this->createConfiguredMock(Adapter::class, [
                        'getCode' => AdyenCcConfigProvider::CODE
                    ]),
                    'getOrder' => $this->createConfiguredMock(Order::class, [
                        'getQuoteId' => 1
                    ])
                ])
            ])
        ];

        $this->appState->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);
        $this->stateData->method('getStateData')->willReturn([
            'paymentMethod' => [
                'storedPaymentMethodId' => hash('md5', time())
            ]
        ]);

        $request = $this->shopperInteractionDataBuilder->build($buildSubject);

        $this->assertArrayHasKey('shopperInteraction', $request['body']);
        $this->assertEquals(
            ShopperInteractionDataBuilder::SHOPPER_INTERACTION_CONTAUTH,
            $request['body']['shopperInteraction']
        );
    }
}
