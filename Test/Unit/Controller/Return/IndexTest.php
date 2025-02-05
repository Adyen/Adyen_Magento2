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

namespace Adyen\Payment\Test\Unit\Controller\Return;

use Adyen\Payment\Controller\Return\Index;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\PaymentResponseHandler;
use Adyen\Payment\Helper\PaymentsDetails;
use Adyen\Payment\Helper\Quote;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection as OrderStatusHistoryCollection;

class IndexTest extends AbstractAdyenTestCase
{
    private $indexControllerMock;
    private $controllerRequestMock;
    private $messageManagerMock;
    private $redirectMock;
    private $contextResponseMock;
    private $quoteMock;
    private $orderEntityMock;
    private $paymentEntityMock;
    private $storeMock;

    private $contextMock;
    private $orderFactoryMock;
    private $sessionMock;
    private $adyenLoggerMock;
    private $storeManagerMock;
    private $quoteHelperMock;
    private $configHelperMock;
    private $paymentsDetailsHelperMock;
    private $paymentResponseHandlerMock;
    private $cartRepositoryMock;
    private $orderRepositoryMock;

    const STORE_ID = 1;

    protected function setUp(): void
    {
        // Constructor argument mocks
        $this->contextMock = $this->createMock(Context::class);
        $this->orderFactoryMock = $this->createGeneratedMock(OrderFactory::class, ['create']);
        $this->sessionMock = $this->createMock(Session::class);
        $this->adyenLoggerMock = $this->createMock(AdyenLogger::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->quoteHelperMock = $this->createMock(Quote::class);
        $this->configHelperMock = $this->createMock(Config::class);
        $this->paymentsDetailsHelperMock = $this->createMock(PaymentsDetails::class);
        $this->paymentResponseHandlerMock = $this->createMock(PaymentResponseHandler::class);
        $this->cartRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);

        // Extra mock objects and methods
        $this->messageManagerMock = $this->createMock(MessageManagerInterface::class);
        $this->redirectMock = $this->createMock(RedirectInterface::class);
        $this->contextResponseMock = $this->createMock(ResponseInterface::class);
        $this->quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $this->paymentEntityMock = $this->createMock(Payment::class);
        $this->paymentEntityMock->method('getAdditionalInformation')->will(
            $this->returnValueMap([
                ['action', ['type' => 'redirect']],
                ['brand_code', Index::BRAND_CODE_DOTPAY],
                ['resultCode', Index::RESULT_CODE_RECEIVED]
            ])
        );
        $this->orderEntityMock = $this->createMock(Order::class);
        $this->orderEntityMock->method('getPayment')->willReturn($this->paymentEntityMock);
        $this->controllerRequestMock = $this->createMock(RequestInterface::class);
        $this->orderFactoryMock->method('create')->willReturn($this->orderEntityMock);
        $this->orderEntityMock->method('loadByIncrementId')->willReturnSelf();
        $this->quoteMock->method('setIsActive')->willReturnSelf();
        $this->sessionMock->method('getLastRealOrder')->willReturn($this->orderEntityMock);
        $this->sessionMock->method('getQuote')->willReturn($this->quoteMock);
        $this->contextMock->method('getRedirect')->willReturn($this->redirectMock);
        $this->contextMock->method('getRequest')->willReturn($this->controllerRequestMock);
        $this->contextMock->method('getMessageManager')->willReturn($this->messageManagerMock);
        $this->contextMock->method('getResponse')->willReturn($this->contextResponseMock);
        $this->configHelperMock->method('getAdyenAbstractConfigData')->will(
            $this->returnValueMap([
                ['return_path', self::STORE_ID, '/checkout/cart'],
                ['custom_success_redirect_path', self::STORE_ID, null]
            ])
        );
        $this->storeMock = $this->createMock(StoreInterface::class);
        $this->storeMock->method('getId')->willReturn(self::STORE_ID);
        $this->storeManagerMock->method('getStore')->willReturn($this->storeMock);

        $this->indexControllerMock = new Index(
            $this->contextMock,
            $this->orderFactoryMock,
            $this->sessionMock,
            $this->adyenLoggerMock,
            $this->storeManagerMock,
            $this->quoteHelperMock,
            $this->configHelperMock,
            $this->paymentsDetailsHelperMock,
            $this->paymentResponseHandlerMock,
            $this->cartRepositoryMock,
            $this->orderRepositoryMock
        );
    }

    private static function testDataProvider(): array
    {
        return [
            [
                'redirectResponse' => [
                    'merchantReference' => PHP_INT_MAX,
                    'redirectResult' => 'ABCDEFG123456789'
                ],
                'paymentsDetailsResponse' => [
                    'merchantReference' => PHP_INT_MAX,
                    'resultCode' => 'Authorised',
                    'pspReference' => 'PSP123456789'
                ],
                'responseHandlerResult' => true,
                'returnPath' => 'checkout/onepage/success',
                'orderId' => PHP_INT_MAX,
                'expectedException' => null,
                'multishipping' => false,
                'orderStatusHistory' => ['Payment received']
            ],
            [
                'redirectResponse' => [
                    'merchantReference' => 'ORDER123',
                    'redirectResult' => 'ABCDEFG123456789'
                ],
                'paymentsDetailsResponse' => [
                    'merchantReference' => 'ORDER123',
                    'resultCode' => 'Authorised',
                    'pspReference' => 'PSP123456789'
                ],
                'responseHandlerResult' => true,
                'returnPath' => 'checkout/onepage/success',
                'orderId' => 'ORDER123',
                'expectedException' => null,
                'multishipping' => false,
                'orderStatusHistory' => ['PSP reference: PSP123456789']
            ],
        ];
    }

    /**
     * @dataProvider testDataProvider
     */
    public function testExecute(
        $redirectResponse,
        $paymentsDetailsResponse,
        $responseHandlerResult,
        $returnPath,
        $orderId,
        $expectedException,
        $multishipping,
        $orderStatusHistory
    ) {
        // Set up order status history
        $orderStatusHistoryCollectionMock = $this->createMock(OrderStatusHistoryCollection::class);
        $orderStatusHistoryCollectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator(array_map(function($comment) {
                $statusHistoryMock = $this->createMock(Order\Status\History::class);
                $statusHistoryMock->method('getComment')->willReturn($comment);
                return $statusHistoryMock;
            }, $orderStatusHistory)));
        $this->orderEntityMock->method('getStatusHistories')->willReturn($orderStatusHistoryCollectionMock);

        if ($expectedException) {
            $this->expectException($expectedException);
        } else {
            $this->redirectMock->expects($this->once())->method('redirect')->with(
                $this->contextResponseMock,
                $returnPath,
                $redirectResponse ? ['_query' => ['utm_nooverride' => '1']] : []
            );
        }

        if ($multishipping) {
            $this->quoteHelperMock->method('getIsQuoteMultiShippingWithMerchantReference')
                ->willReturn(true);
        }

        if (empty($paymentsDetailsResponse)) {
            $this->paymentsDetailsHelperMock->method('initiatePaymentDetails')
                ->willThrowException(new Exception);
        }

        $this->controllerRequestMock->method('getParams')->willReturn($redirectResponse);
        $this->orderEntityMock->method('getId')->willReturn($orderId);
        $this->orderEntityMock->method('getIncrementId')->willReturn($orderId);
        $this->paymentsDetailsHelperMock->method('initiatePaymentDetails')
            ->willReturn($paymentsDetailsResponse);
        $this->adyenLoggerMock->expects($this->once())
            ->method('addAdyenResult')
            ->with('Processing redirect response');
        $this->paymentResponseHandlerMock->expects($this->once())
            ->method('handlePaymentsDetailsResponse')
            ->willReturn($responseHandlerResult);


        $this->indexControllerMock->execute();
    }
}
