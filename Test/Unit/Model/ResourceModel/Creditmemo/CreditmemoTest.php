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

namespace Adyen\Payment\Test\Helper\Unit\Model\ResourceModel\Creditmemo;

use Adyen\Payment\Api\Data\CreditmemoInterface;
use Adyen\Payment\Model\ResourceModel\Creditmemo\Creditmemo;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;

class CreditmemoTest extends AbstractAdyenTestCase
{
    private ?Creditmemo $creditmemoResourceModel;
    private Context|MockObject $contextMock;
    private Select|MockObject $dbSelectMock;
    private AdapterInterface|MockObject $connectionMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->dbSelectMock = $this->createMock(Select::class);

        $this->connectionMock = $this->createMock(AdapterInterface::class);
        $this->connectionMock->method('select')->willReturn($this->dbSelectMock);

        $resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $resourceConnectionMock->method('getConnection')->willReturn($this->connectionMock);
        $resourceConnectionMock->method('getTableName')->willReturn('adyen_creditmemo');

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getResources')->willReturn($resourceConnectionMock);

        $this->creditmemoResourceModel = new Creditmemo($this->contextMock);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->creditmemoResourceModel = null;
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function testGetIdByPspreference()
    {
        $pspreference = 'abc_123456789';
        $invoiceId = '1';

        $this->dbSelectMock->method('from')
            ->with('adyen_creditmemo', CreditmemoInterface::ENTITY_ID)
            ->willReturnSelf();
        $this->dbSelectMock->method('where')
            ->with('pspreference = :pspreference')
            ->willReturnSelf();

        $this->connectionMock->method('fetchOne')
            ->with($this->dbSelectMock, [':pspreference' => $pspreference])
            ->willReturn($invoiceId);

        $result = $this->creditmemoResourceModel->getIdByPspreference($pspreference);
        $this->assertEquals($invoiceId, $result);
    }
}
