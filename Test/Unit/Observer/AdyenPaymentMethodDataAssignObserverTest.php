<?php
declare(strict_types=1);

namespace Adyen\Payment\Test\Unit\Observer;

use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Util\CheckoutStateDataValidator;
use Adyen\Payment\Helper\Util\DataArrayValidator;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Model\ResourceModel\StateData\Collection;
use Adyen\Payment\Observer\AdyenPaymentMethodDataAssignObserver;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use PHPUnit\Framework\MockObject\MockObject;
use \Magento\Framework\DataObject;
use Magento\Quote\Model\Quote\Payment;

class AdyenPaymentMethodDataAssignObserverTest extends AbstractAdyenTestCase
{
    private MockObject $checkoutStateDataValidator;
    private MockObject $stateDataCollection;
    private MockObject $stateData;
    private MockObject $vaultHelper;
    private AdyenPaymentMethodDataAssignObserver $observer;

    protected function setUp(): void
    {
        $this->checkoutStateDataValidator = $this->createMock(CheckoutStateDataValidator::class);
        $this->stateDataCollection = $this->createMock(Collection::class);
        $this->stateData = $this->createMock(StateData::class);
        $this->vaultHelper = $this->createMock(Vault::class);
        $this->paymentInfo = $this->createMock(Payment::class);
        $this->observer = new AdyenPaymentMethodDataAssignObserver(
            $this->checkoutStateDataValidator,
            $this->stateDataCollection,
            $this->stateData,
            $this->vaultHelper
        );
    }

    public function testExecuteWithNoAdditionalData()
    {
        $dataObject = new DataObject();
        $paymentInfo = $this->createMock(Payment::class);
        $observer = $this->getPreparedObserverWithMap([
            [AbstractDataAssignObserver::DATA_CODE, $dataObject],
            [AbstractDataAssignObserver::MODEL_CODE, $paymentInfo],
        ]);

        $this->observer->execute($observer);
    }

    public function testExecuteWithValidAdditionalData()
    {
        $additionalData = [
            'stateData' => json_encode(['paymentMethod' => ['type' => 'scheme']]),
            'recurringProcessingModel' => 'Subscription',
        ];

        $dataObject = new DataObject([
            PaymentInterface::KEY_ADDITIONAL_DATA => $additionalData,
        ]);



        $observer = $this->getPreparedObserverWithMap([
            [AbstractDataAssignObserver::DATA_CODE, $dataObject],
            [AbstractDataAssignObserver::MODEL_CODE, $this->paymentInfo],
        ]);
        $this->paymentInfo->expects($this->once())->method('unsAdditionalInformation')->with('cc_type');

        $this->paymentInfo->method('getData')
            ->with('quote_id')
            ->willReturn(1);

        $this->vaultHelper->expects($this->once())
            ->method('validateRecurringProcessingModel')
            ->with('Subscription')
            ->willReturn(true);

        $this->paymentInfo->expects($this->once())->method('setAdditionalInformation');

        // Execute the observer logic
        $this->observer->execute($observer);
    }

    public function testExecuteWithInvalidRecurringProcessingModel()
    {
        $additionalData = [
            'stateData' => json_encode(['paymentMethod' => ['type' => 'scheme']]),
            'recurringProcessingModel' => 'invalid',
        ];

        $dataObject = new \Magento\Framework\DataObject([
            PaymentInterface::KEY_ADDITIONAL_DATA => $additionalData,
        ]);

        $this->paymentInfo->expects($this->atLeastOnce())
            ->method('unsAdditionalInformation')
            ->withConsecutive(['cc_type'], ['recurringProcessingModel']);

        $this->paymentInfo->expects($this->any())->method('getData')
            ->with('quote_id')->willReturn(123);

        $observer = $this->getPreparedObserverWithMap([
            [AbstractDataAssignObserver::DATA_CODE, $dataObject],
            [AbstractDataAssignObserver::MODEL_CODE, $this->paymentInfo],
        ]);

        $this->vaultHelper->expects($this->once())->method('validateRecurringProcessingModel')
            ->with('invalid')->willReturn(false);

        $this->observer->execute($observer);
    }

    public function testExecuteWithStateDataFromDatabase()
    {
        $this->paymentInfo->expects($this->any())->method('getData')
            ->with('quote_id')->willReturn(123);

        $dataObject = new \Magento\Framework\DataObject();
        $observer = $this->getPreparedObserverWithMap([
            [AbstractDataAssignObserver::DATA_CODE, $dataObject],
            [AbstractDataAssignObserver::MODEL_CODE, $this->paymentInfo],
        ]);

        $this->observer->execute($observer);
    }

    /**
     * @param array $returnMap
     * @return MockObject|Observer
     */
    private function getPreparedObserverWithMap(array $returnMap): Observer|MockObject
    {
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->getMock();

        $observer->expects(static::atLeastOnce())
            ->method('getEvent')
            ->willReturn($event);
        $event->expects(static::atLeastOnce())
            ->method('getDataByKey')
            ->willReturnMap(
                $returnMap
            );

        return $observer;
    }
}
