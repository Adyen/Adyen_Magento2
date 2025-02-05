<?php

namespace Adyen\Payment\Test\Unit\Gateway\Validator;

use Adyen\Payment\Gateway\Validator\CheckoutResponseValidator;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\ValidatorException;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Validator\Result;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;

class CheckoutResponseValidatorTest extends AbstractAdyenTestCase
{
    private CheckoutResponseValidator $checkoutResponseValidator;
    private ResultInterfaceFactory|MockObject $resultFactoryMock;
    private PaymentDataObject $paymentDataObject;
    private AdyenLogger|MockObject $adyenLoggerMock;
    private Data|MockObject $adyenHelperMock;

    protected function setUp(): void
    {
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->adyenHelperMock = $this->createMock(Data::class);
        $this->resultFactoryMock = $this->getMockBuilder(ResultInterfaceFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $orderAdapterMock = $this->createMock(OrderAdapterInterface::class);
        $paymentMock = $this->createMock(Payment::class);
        $this->paymentDataObject = new PaymentDataObject($orderAdapterMock, $paymentMock);

        $this->checkoutResponseValidator = new CheckoutResponseValidator(
            $this->resultFactoryMock,
            $this->adyenLoggerMock,
            $this->adyenHelperMock
        );
    }

    public function testIfValidationFailsWhenResponseIsEmpty()
    {
        $validationSubject = [
            'payment' => [],
            'stateObject' => [],
            'response' => []
        ];

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage("No responses were provided");

        $this->checkoutResponseValidator->validate($validationSubject);
    }

    public function testValidateSuccessWithAuthorisedResultCode()
    {
        $validationSubject = [
            'payment' => $this->paymentDataObject,
            'stateObject' => [],
            'response' => [
                0 => [
                    'additionalData' => [],
                    'amount' => [],
                    'resultCode' => 'Authorised',
                    'pspReference' => 'ABC12345'
                ]
            ]
        ];

        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with([
                'isValid' => true,
                'failsDescription' => [],
                'errorCodes' => []
            ])
            ->willReturn(new Result(true));

        $this->checkoutResponseValidator->validate($validationSubject);
    }
     public function testValidateThrowsExceptionForRefusedResultCode()
    {
        $validationSubject = [
            'payment' => $this->paymentDataObject,
            'stateObject' => [],
            'response' => [
                0 => [
                    'additionalData' => [],
                    'amount' => [],
                    'resultCode' => 'Refused',
                    'pspReference' => 'ABC12345'
                ]
            ]
        ];

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage("The payment is REFUSED.");

        $this->checkoutResponseValidator->validate($validationSubject);
    }

    public function testValidateThrowsExceptionForUnknownResultCode()
    {
        $validationSubject = [
            'payment' => $this->paymentDataObject,
            'stateObject' => [],
            'response' => [
                0 => [
                    'additionalData' => [],
                    'amount' => [],
                    'resultCode' => 'Some other result code',
                    'pspReference' => 'ABC12345'
                ]
            ]
        ];

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage("Error with payment method please select different payment method.");

        $this->checkoutResponseValidator->validate($validationSubject);
    }

    public function testValidateHandlesAllowedErrorCode()
    {
        $validationSubject = [
            'payment' => $this->paymentDataObject,
            'stateObject' => [],
            'response' => [
                0 => [
                    'additionalData' => [],
                    'amount' => [],
                    'resultCode' => '',
                    'pspReference' => 'ABC12345',
                    'errorCode' => '124',
                    'error' => 'No result code present in response.'
                ]
            ]
        ];

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage("No result code present in response.");

        $this->checkoutResponseValidator->validate($validationSubject);
    }

     public function testValidateForSuccessfulPartialPayments()
    {
        $validationSubject = [
            'payment' => $this->paymentDataObject,
            'stateObject' => [],
            'response' => [
                0 => [
                    'additionalData' => [],
                    'amount' => [],
                    'resultCode' => 'Authorised',
                    'pspReference' => 'ABC12345',
                    'paymentMethod' => [
                        'name' => 'giftcard',
                        'type' => 'Givex',
                    ]
                ],
                1 => [
                    'additionalData' => [],
                    'amount' => [],
                    'resultCode' => 'Authorised',
                    'pspReference' => 'ABC12345',
                    'paymentMethod' => [
                        'name' => 'card',
                        'type' => 'CreditCard',
                    ]
                ]
            ]
        ];

        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with([
                'isValid' => true,
                'failsDescription' => [],
                'errorCodes' => []
            ])
            ->willReturn(new Result(true));

        $this->checkoutResponseValidator->validate($validationSubject);
    }


     public function testValidateForFailedPartialPayments()
     {
         $validationSubject = [
             'payment' => $this->paymentDataObject,
             'stateObject' => [],
             'response' => [
                 0 => [
                     'additionalData' => [],
                     'amount' => [],
                     'resultCode' => 'Authorised',
                     'pspReference' => 'ABC12345',
                     'paymentMethod' => [
                         'name' => 'Givex',
                         'type' => 'giftcard',
                     ]
                 ],
                 1 => [
                     'additionalData' => [],
                     'amount' => [],
                     'resultCode' => 'Refused',
                     'pspReference' => 'ABC12345',
                     'paymentMethod' => [
                         'name' => 'Cards',
                         'type' => 'scheme',
                     ]
                 ]
             ]
         ];

         $this->expectException(ValidatorException::class);
         $this->expectExceptionMessage("The payment is REFUSED.");

         $this->checkoutResponseValidator->validate($validationSubject);
     }

    public function testIfValidationSucceedsOnMiscellaneousResultCodes()
    {
        $resultCodes = [
            'IdentifyShopper',
            'ChallengeShopper',
            'PresentToShopper',
            'Pending',
            'RedirectShopper'
        ];

        $this->resultFactoryMock->expects($this->exactly(count($resultCodes)))
            ->method('create')
            ->with([
                'isValid' => true,
                'failsDescription' => [],
                'errorCodes' => []
            ])
            ->willReturn(new Result(true));

        foreach ($resultCodes as $resultCode) {
            $validationSubject = [
                'payment' => $this->paymentDataObject,
                'stateObject' => [],
                'response' => [
                    0 => [
                        'additionalData' => [],
                        'amount' => [],
                        'resultCode' => $resultCode,
                        'pspReference' => 'ABC12345'
                    ],
                ]
            ];

            $this->checkoutResponseValidator->validate($validationSubject);
        }
    }
}
