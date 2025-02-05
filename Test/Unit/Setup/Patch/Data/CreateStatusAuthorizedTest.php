<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Setup\Patch\Data;

use Adyen\Payment\Helper\DataPatch;
use Adyen\Payment\Setup\Patch\Data\CreateStatusAuthorized;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;
use PHPUnit\Framework\MockObject\MockObject;

class CreateStatusAuthorizedTest extends AbstractAdyenTestCase
{
    protected CreateStatusAuthorized $createStatusAuthorized;
    protected ModuleDataSetupInterface|MockObject $moduleDataSetupMock;
    protected WriterInterface|MockObject $configWriteMock;
    protected ReinitableConfigInterface|MockObject $reinitableConfigMock;
    protected DataPatch|MockObject $dataPatchHelperMock;
    protected StatusFactory|MockObject $statusFactoryMock;
    protected StatusResourceFactory|MockObject $statusResourceFactoryMock;
    protected StatusResource|MockObject $statusResourceMock;

    /**
     * @return void
     */
    protected function setUp():void
    {
        $this->moduleDataSetupMock = $this->createConfiguredMock(ModuleDataSetupInterface::class, [
            'getConnection' => $this->createMock(AdapterInterface::class)
        ]);
        $this->configWriteMock = $this->createMock(WriterInterface::class);
        $this->reinitableConfigMock = $this->createMock(ReinitableConfigInterface::class);
        $this->dataPatchHelperMock = $this->createConfiguredMock(DataPatch::class, [
            'findConfig' => null
        ]);
        $this->statusResourceMock = $this->createMock(StatusResource::class);
        $this->statusFactoryMock = $this->createGeneratedMock(StatusFactory::class, ['create']);
        $this->statusFactoryMock->method('create')->willReturn(
            $this->createMock(Status::class)
        );
        $this->statusResourceFactoryMock = $this->createGeneratedMock(StatusResourceFactory::class, [
            'create'
        ]);
        $this->statusResourceFactoryMock->method('create')->willReturn($this->statusResourceMock);

        $this->createStatusAuthorized = new CreateStatusAuthorized(
            $this->moduleDataSetupMock,
            $this->configWriteMock,
            $this->reinitableConfigMock,
            $this->dataPatchHelperMock,
            $this->statusFactoryMock,
            $this->statusResourceFactoryMock
        );
    }

    public function testApply()
    {
        $this->statusResourceMock->expects($this->once())
            ->method('save');

        $result = $this->createStatusAuthorized->apply();

        $this->assertInstanceOf(CreateStatusAuthorized::class, $result);
    }

    public function testApplyFail()
    {
        $this->statusResourceMock->expects($this->once())
            ->method('save')
            ->willThrowException(new AlreadyExistsException());

        $this->dataPatchHelperMock->expects($this->never())->method('findConfig');

        $result = $this->createStatusAuthorized->apply();

        $this->assertInstanceOf(CreateStatusAuthorized::class, $result);
    }

    public function testGetAliases()
    {
        $aliases = $this->createStatusAuthorized->getAliases();

        $this->assertSame([], $aliases);
    }

    public function testGetDependencies()
    {
        $dependencies = $this->createStatusAuthorized::getDependencies();

        $this->assertSame([], $dependencies);
    }

    public function getVersion()
    {
        $version = $this->createStatusAuthorized::getVersion();

        $this->assertNotEmpty($version);
    }
}
