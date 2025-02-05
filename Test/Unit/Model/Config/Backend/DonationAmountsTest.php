<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2021 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Test\Unit\Model\Config\Backend;

use Adyen\Payment\Model\Config\Backend\DonationAmounts;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Validator\Exception;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class DonationAmountsTest extends AbstractAdyenTestCase
{
    protected ?DonationAmounts $donationAmounts;
    protected Context|MockObject $contextMock;
    protected Registry|MockObject $registryMock;
    protected ScopeConfigInterface|MockObject $scopeConfigMock;
    protected TypeListInterface|MockObject $typeListMock;
    protected AbstractResource|MockObject $abstractResourceMock;
    protected AbstractDb|MockObject $abstractDbMock;
    protected StoreManagerInterface|MockObject $storeManagerMock;
    protected array $data = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->typeListMock = $this->createMock(TypeListInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->abstractResourceMock = $this->createMock(AbstractResource::class);
        $this->abstractDbMock = $this->createMock(AbstractDb::class);

        $currency = $this->createMock(Currency::class);
        $currency->method('getRate')->willReturn(1);
        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getBaseCurrency')->willReturn($currency);
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);
    }

    /**
     * This method is required as `data` property needs to be adjusted independently after setUp() method runs.
     *
     * @return void
     */
    private function generateSutClass(): void
    {
        $this->donationAmounts = new DonationAmounts(
            $this->contextMock,
            $this->registryMock,
            $this->scopeConfigMock,
            $this->typeListMock,
            $this->storeManagerMock,
            $this->abstractResourceMock,
            $this->abstractDbMock,
            $this->data
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testValidateBeforeSaveSuccess()
    {
        $this->data = [
            'fieldset_data' => [
                'active' => true,

            ],
            'value' => '1,5,10'
        ];

        $this->generateSutClass();

        $result = $this->donationAmounts->validateBeforeSave();
        $this->assertInstanceOf(DonationAmounts::class, $result);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testValidateBeforeSaveFail()
    {
        $this->data = [
            'fieldset_data' => [
                'active' => true,

            ],
            'value' => ''
        ];

        $this->generateSutClass();

        $this->expectException(Exception::class);
        $this->donationAmounts->validateBeforeSave();
    }

    /**
     * @dataProvider donationAmountsProvider
     */
    public function testValidateDonationAmounts($donationAmounts, $expectedResult)
    {
        $this->generateSutClass();

        $result = $this->donationAmounts->validateDonationAmounts(explode(',', (string) $donationAmounts));
        $this->assertEquals($result, $expectedResult);
    }

    /**
     * @return array[]
     */
    private static function donationAmountsProvider(): array
    {
        return array(
            array(
                "12,453,68,2",
                true
            ),
            array(
                "12,45,h,34",
                false
            ),
            array(
                "12.4,45.3,,34",
                false
            ),
            array(
                "12.4,45.3,34",
                true
            ),
            array(
                "",
                false
            )
        );
    }
}
