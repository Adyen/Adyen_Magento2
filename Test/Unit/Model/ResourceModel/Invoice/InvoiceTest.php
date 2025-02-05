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

namespace Adyen\Payment\Test\Helper\Unit\Model\ResourceModel\Invoice;

use Adyen\Payment\Api\Data\InvoiceInterface;
use Adyen\Payment\Model\ResourceModel\Invoice\Invoice;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;

class InvoiceTest extends AbstractAdyenTestCase
{
    private ?Invoice $invoiceResourceModel;
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
        $resourceConnectionMock->method('getTableName')->willReturn('adyen_invoice');

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getResources')->willReturn($resourceConnectionMock);

        $this->invoiceResourceModel = new Invoice($this->contextMock);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->invoiceResourceModel = null;
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
            ->with('adyen_invoice', InvoiceInterface::ENTITY_ID)
            ->willReturnSelf();
        $this->dbSelectMock->method('where')
            ->with('pspreference = :pspreference')
            ->willReturnSelf();

        $this->connectionMock->method('fetchOne')
            ->with($this->dbSelectMock, [':pspreference' => $pspreference])
            ->willReturn($invoiceId);

        $result = $this->invoiceResourceModel->getIdByPspreference($pspreference);
        $this->assertEquals($invoiceId, $result);
    }
}
