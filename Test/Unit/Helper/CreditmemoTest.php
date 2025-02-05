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

namespace Adyen\Payment\Test\Unit\Helper;

use Adyen\Payment\Api\Data\CreditmemoInterface;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Api\Repository\AdyenCreditmemoRepositoryInterface;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Creditmemo;
use Adyen\Payment\Model\CreditmemoFactory;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Creditmemo as CreditmemoResourceModel;
use Adyen\Payment\Model\ResourceModel\Order\Payment;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;

class CreditmemoTest extends AbstractAdyenTestCase
{
    public function testCreateAdyenCreditMemo()
    {
        $pspReference = '12A34B56C78D910E';
        $originalReference = 'E10D9C78B56A3412';
        $refundAmount = 10.00;
        $adyenOrderPaymentId = 5;

        $paymentMock = $this->createConfiguredMock(\Magento\Sales\Model\Order\Payment::class, [
            'getEntityId' => 5
        ]);

        $adyenOrderPaymentMock = $this->createConfiguredMock(OrderPaymentInterface::class, [
            'getEntityId' => $adyenOrderPaymentId
        ]);

        $orderPaymentResourceModel = $this->createConfiguredMock(Payment::class, [
            'getOrderPaymentDetails' => [OrderPaymentInterface::ENTITY_ID => $adyenOrderPaymentMock->getEntityId()]
        ]);

        $adyenCreditmemoMock = $this->createMock(\Adyen\Payment\Model\Creditmemo::class);
        $adyenCreditmemoFactoryMock = $this->createGeneratedMock(CreditmemoFactory::class, ['create']);
        $adyenCreditmemoFactoryMock->method('create')->willReturn($adyenCreditmemoMock);

        $adyenCreditmemoRepositoryMock = $this->createMock(AdyenCreditmemoRepositoryInterface::class);
        $adyenCreditmemoRepositoryMock->expects($this->once())
            ->method('save')
            ->with($adyenCreditmemoMock);

        $creditmemoHelper = $this->createCreditmemoHelper(
            null,
            null,
            $adyenCreditmemoFactoryMock,
            null,
            $orderPaymentResourceModel,
            $adyenCreditmemoRepositoryMock
        );

        $adyenCreditmemoMock->expects($this->once())->method('setPspreference')->with($pspReference);
        $adyenCreditmemoMock->expects($this->once())->method('setOriginalReference')->with($originalReference);
        $adyenCreditmemoMock->expects($this->once())->method('setAdyenPaymentOrderId')->with($adyenOrderPaymentId);
        $adyenCreditmemoMock->expects($this->once())->method('setAmount')->with($refundAmount);

        $result = $creditmemoHelper->createAdyenCreditMemo(
            $paymentMock,
            $pspReference,
            $originalReference,
            $refundAmount
        );

        $this->assertSame($adyenCreditmemoMock, $result);
    }

    public function testLinkAndUpdateAdyenCreditmemos()
    {
        $adyenOrderPaymentId = 5;
        $magentoCreditmemoId = 10;

        $adyenOrderPaymentMock = $this->createConfiguredMock(\Adyen\Payment\Model\Order\Payment::class, [
                'getEntityId' => $adyenOrderPaymentId
            ]
        );

        $adyenCreditmemoMock = $this->createConfiguredMock(CreditmemoInterface::class, [
            'getAmount' => 10.00
        ]);
        $adyenCreditmemoMock->expects($this->once())->method('setCreditmemoId')->with($magentoCreditmemoId);
        $adyenCreditmemoMock->expects($this->once())->method('setCreditmemoId')->with($magentoCreditmemoId);

        $adyenCreditmemos[] = $adyenCreditmemoMock;

        $adyenCreditmemoRepositoryMock = $this->createMock(AdyenCreditmemoRepositoryInterface::class);
        $adyenCreditmemoRepositoryMock->expects($this->once())
            ->method('getByAdyenOrderPaymentId')
            ->with($adyenOrderPaymentId)
            ->willReturn($adyenCreditmemos);
        $adyenCreditmemoRepositoryMock->expects($this->once())
            ->method('save')
            ->with($adyenCreditmemoMock);

        $magentoCreditmemoMock = $this->createConfiguredMock(\Magento\Sales\Model\Order\Creditmemo::class, [
            'getEntityId' => $magentoCreditmemoId,
            'getGrandTotal' => 10.00
        ]);

        $creditmemoHelper = $this->createCreditmemoHelper(
            null,
            null,
            null,
            null,
            null,
            $adyenCreditmemoRepositoryMock
        );

        $creditmemoHelper->linkAndUpdateAdyenCreditmemos($adyenOrderPaymentMock, $magentoCreditmemoMock);
    }

    public function testSaveMethodIsCalledCorrectNumberOfTimes()
    {
        $adyenCreditmemoResourceModelMock = $this->createMock(
            CreditmemoResourceModel::class
        );
        $magentoCreditmemoMock = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $magentoCreditmemoId = 1;
        $adyenCreditmemos = [
            ['data' => 'creditmemo1'],
            ['data' => 'creditmemo2'],
            ['data' => 'creditmemo3']
        ];

        $adyenCreditmemoResourceModelMock->expects($this->exactly(count($adyenCreditmemos)))
            ->method('save')
            ->willReturn($this);

        $magentoCreditmemoMock->method('getEntityId')
            ->willReturn($magentoCreditmemoId);

        foreach ($adyenCreditmemos as $adyenCreditmemo) {
            $currAdyenCreditmemoMock = $this->createMock(AbstractModel::class);
            $currAdyenCreditmemoMock->setData($adyenCreditmemo);
            $currAdyenCreditmemoMock->setEntityId($magentoCreditmemoMock->getEntityId());
            $currAdyenCreditmemoMock->setCreditmemoId($magentoCreditmemoId);
            $adyenCreditmemoResourceModelMock->save($currAdyenCreditmemoMock);
        }
    }

    /**
     * @return void
     */
    public function testUpdateAdyenCreditmemosStatus()
    {
        $adyenCreditmemoMock = $this->createMock(CreditmemoInterface::class);
        $status = 'refunded';

        $adyenCreditmemoMock->expects($this->once())
            ->method('setStatus')
            ->with($status);

        $adyenCreditmemoRepositoryMock = $this->createMock(AdyenCreditmemoRepositoryInterface::class);
        $adyenCreditmemoRepositoryMock->expects($this->once())
            ->method('save')
            ->with($adyenCreditmemoMock);

        $creditmemoHelper = $this->createCreditmemoHelper(
            null,
            null,
            null,
            null,
            null,
            $adyenCreditmemoRepositoryMock
        );

        $creditmemoHelper->updateAdyenCreditmemosStatus($adyenCreditmemoMock, $status);
    }

    /**
     * @return array
     */
    private static function dataproviderGetAdyenCreditmemoByPspreference(): array
    {
        return [
            ['result' => ['entity_id' => 1]],
            ['result' => null]
        ];
    }

    /**
     * @dataProvider dataproviderGetAdyenCreditmemoByPspreference
     *
     * @param $result
     * @return void
     * @throws NoSuchEntityException
     */
    public function testGetAdyenCreditmemoByPspreference($result)
    {
        $pspreference = '123_abcdef';

        $creditmemoResourceModel = $this->createMock(CreditmemoResourceModel::class);
        $creditmemoResourceModel->expects($this->once())
            ->method('getAdyenCreditmemoByPspreference')
            ->with($pspreference)
            ->willReturn($result);

        $creditmemoRepository = $this->createMock(AdyenCreditmemoRepositoryInterface::class);

        $creditmemoHelper = $this->createCreditmemoHelper(
            null,
            null,
            null,
            $creditmemoResourceModel,
            null,
            $creditmemoRepository
        );

        if (is_null($result)) {
            $this->assertNull($creditmemoHelper->getAdyenCreditmemoByPspreference($pspreference));
        } else {
            $creditmemoRepository->expects($this->once())
                ->method('get')
                ->with($result['entity_id']);

            $this->assertInstanceOf(
                CreditmemoInterface::class,
                $creditmemoHelper->getAdyenCreditmemoByPspreference($pspreference)
            );
        }
    }

    /**
     * @param null $context
     * @param null $adyenDataHelper
     * @param null $adyenCreditmemoFactory
     * @param null $adyenCreditmemoResourceModel
     * @param null $orderPaymentResourceModel
     * @param null $adyenCreditmemoRepositoryMock
     * @return Creditmemo
     */
    protected function createCreditmemoHelper(
        $context = null,
        $adyenDataHelper = null,
        $adyenCreditmemoFactory = null,
        $adyenCreditmemoResourceModel = null,
        $orderPaymentResourceModel = null,
        $adyenCreditmemoRepositoryMock = null
    ): Creditmemo {
        if (is_null($context)) {
            $context = $this->createMock(Context::class);
        }

        if (is_null($adyenDataHelper)) {
            $adyenDataHelper = $this->createMock(Data::class);
        }

        if (is_null($adyenCreditmemoFactory)) {
            $adyenCreditmemoFactory = $this->createGeneratedMock(CreditmemoFactory::class);
        }

        if (is_null($adyenCreditmemoResourceModel)) {
            $adyenCreditmemoResourceModel = $this->createMock(
                CreditmemoResourceModel::class
            );
        }

        if (is_null($orderPaymentResourceModel)) {
            $orderPaymentResourceModel = $this->createMock(Payment::class);
        }

        if (is_null($adyenCreditmemoRepositoryMock)) {
            $adyenCreditmemoRepositoryMock = $this->createMock(
                AdyenCreditmemoRepositoryInterface::class
            );
        }

        return new Creditmemo(
            $context,
            $adyenDataHelper,
            $adyenCreditmemoFactory,
            $adyenCreditmemoResourceModel,
            $orderPaymentResourceModel,
            $adyenCreditmemoRepositoryMock
        );
    }
}
