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

namespace Adyen\Payment\Test\Unit\Model\Api;

use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Helper\Requests;
use Adyen\Payment\Model\Api\AdyenDonations;
use Adyen\Payment\Model\Sales\OrderRepository;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Sales\Api\Data\OrderInterface;

class AdyenDonationsTest extends AbstractAdyenTestCase
{
    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @throws InputException
     */
    public function testDonate()
    {
        $orderRepositoryMock = $this->createPartialMock(OrderRepository::class, ['get']);
        $orderRepositoryMock->expects(self::atLeastOnce())
            ->method('get')
            ->willReturn($this->createMock(OrderInterface::class));

        $adyenDonationsMock = $this->getMockBuilder(AdyenDonations::class)
            ->setMethods(['makeDonation'])
            ->setConstructorArgs([
                $this->createMock(CommandPoolInterface::class),
                $this->createMock(Json::class),
                $this->createMock(Data::class),
                $this->createMock(ChargedCurrency::class),
                $this->createMock(Config::class),
                $this->createMock(PaymentMethods::class),
                $orderRepositoryMock,
                $this->createMock(Requests::class)
            ])
            ->getMock();

        $adyenDonationsMock->donate(1, '');
    }
}
