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

namespace Adyen\Payment\Gateway\Http\Client;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\ConnectionException;
use Adyen\Model\Checkout\PaymentRequest;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\GiftcardPayment;
use Adyen\Payment\Helper\Idempotency;
use Adyen\Payment\Helper\OrdersApi;
use Adyen\Payment\Model\PaymentResponse;
use Adyen\Payment\Model\PaymentResponseFactory;
use Adyen\Payment\Model\ResourceModel\PaymentResponse as PaymentResponseResourceModel;
use Adyen\Service\Checkout\PaymentsApi;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Store\Model\StoreManagerInterface;

class TransactionPayment implements ClientInterface
{
    /**
     * @var Data
     */
    private Data $adyenHelper;

    /**
     * @var PaymentResponseFactory
     */
    private PaymentResponseFactory $paymentResponseFactory;

    /**
     * @var PaymentResponseResourceModel
     */
    private PaymentResponseResourceModel $paymentResponseResourceModel;

    /**
     * @var Idempotency
     */
    private Idempotency $idempotencyHelper;

    /**
     * @var OrdersApi
     */
    private OrdersApi $orderApiHelper;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var GiftcardPayment
     */
    private GiftcardPayment $giftcardPaymentHelper;

    private ?int $remainingOrderAmount;

    /**
     * @param Data $adyenHelper
     * @param PaymentResponseFactory $paymentResponseFactory
     * @param PaymentResponseResourceModel $paymentResponseResourceModel
     * @param Idempotency $idempotencyHelper
     * @param OrdersApi $orderApiHelper
     * @param StoreManagerInterface $storeManager
     * @param GiftcardPayment $giftcardPaymentHelper
     */
    public function __construct(
        Data $adyenHelper,
        PaymentResponseFactory $paymentResponseFactory,
        PaymentResponseResourceModel $paymentResponseResourceModel,
        Idempotency $idempotencyHelper,
        OrdersApi $orderApiHelper,
        StoreManagerInterface $storeManager,
        GiftcardPayment $giftcardPaymentHelper,
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->paymentResponseFactory = $paymentResponseFactory;
        $this->paymentResponseResourceModel = $paymentResponseResourceModel;
        $this->idempotencyHelper = $idempotencyHelper;
        $this->orderApiHelper = $orderApiHelper;
        $this->storeManager = $storeManager;
        $this->giftcardPaymentHelper = $giftcardPaymentHelper;
        $this->remainingOrderAmount = null;
    }

    /**
     * @param TransferInterface $transferObject
     * @return array
     * @throws AdyenException
     * @throws AlreadyExistsException
     * @throws ConnectionException
     * @throws NoSuchEntityException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $requestData = $transferObject->getBody();
        $headers = $transferObject->getHeaders();
        $clientConfig = $transferObject->getClientConfig();
        $this->remainingOrderAmount = $requestData['amount']['value'];

        // If the payments call is already done return the request
        if (!empty($requestData['resultCode'])) {
            //Initiate has already a response
            return $requestData;
        }

        $client = $this->adyenHelper->initializeAdyenClientWithClientConfig($clientConfig);
        $service = $this->adyenHelper->initializePaymentsApi($client);
        $responseCollection = [];
        $responseCollection['hasOnlyGiftCards'] = false;

        try {
            list($requestData, $giftcardResponseCollection) = $this->processGiftcards($requestData, $service);

            /** @var array $responseCollection */
            if (!empty($giftcardResponseCollection)) {
                $responseCollection = array_merge($responseCollection, $giftcardResponseCollection);

                if ($this->remainingOrderAmount === 0) {
                    $responseCollection['hasOnlyGiftCards'] = true;
                    return  $responseCollection;
                }
            }

            $requestData['applicationInfo'] = $this->adyenHelper->buildApplicationInfo($client);
            $paymentRequest = new PaymentRequest($requestData);

            $idempotencyKey = $this->idempotencyHelper->generateIdempotencyKey(
                $requestData,
                $headers['idempotencyExtraData'] ?? null
            );
            $requestOptions['idempotencyKey'] = $idempotencyKey;
            $requestOptions['headers'] = $headers;

            $this->adyenHelper->logRequest($requestData, Client::API_CHECKOUT_VERSION, '/payments');
            $response = $service->payments($paymentRequest, $requestOptions);

            // Store the /payments response in the database in case it is needed in order to finish the payment
            /** @var PaymentResponse $paymentResponse */
            $paymentResponse = $this->paymentResponseFactory->create();
            $paymentResponse->setResponse((string)$response);
            $paymentResponse->setResultCode($response->getResultCode());
            $paymentResponse->setMerchantReference($requestData["reference"]);
            $this->paymentResponseResourceModel->save($paymentResponse);
            $responseData = $response->toArray();
            $responseCollection[] = $responseData;

            $this->adyenHelper->logResponse($responseData);
        } catch (AdyenException $e) {
            $this->adyenHelper->logAdyenException($e);

            $responseObj['error'] = $e->getMessage();
            $responseObj['errorCode'] = $e->getAdyenErrorCode();

            $responseCollection[] = $responseObj;
        }

        return $responseCollection;
    }

    /**
     * @param array $request
     * @param PaymentsApi $service
     * @param array $redeemedGiftcards
     * @param array $ordersResponse
     * @return array
     * @throws AdyenException
     * @throws AlreadyExistsException
     */
    private function handleGiftcardPayments(
        array $request,
        PaymentsApi $service,
        array $redeemedGiftcards,
        array $ordersResponse
    ): array {

        $giftCardResponseCollection = [];

        foreach ($redeemedGiftcards as $giftcard) {
            $stateData = json_decode($giftcard['state_data'], true);

            if (!isset($stateData['paymentMethod']['type']) || $stateData['paymentMethod']['type'] !== 'giftcard') {
                continue;
            }

            if ($this->remainingOrderAmount > $stateData['giftcard']['balance']['value']) {
                $deductedAmount = $stateData['giftcard']['balance']['value'];
            } else {
                $deductedAmount = $this->remainingOrderAmount;
            }

            $giftcardPaymentRequest = $this->giftcardPaymentHelper->buildGiftcardPaymentRequest(
                $request,
                $ordersResponse,
                $stateData,
                $deductedAmount
            );

            $this->adyenHelper->logRequest(
                $giftcardPaymentRequest,
                Client::API_CHECKOUT_VERSION,
                '/payments'
            );

            $response = $service->payments(new PaymentRequest($giftcardPaymentRequest));

            /** @var PaymentResponse $paymentResponse */
            $paymentResponse = $this->paymentResponseFactory->create();
            $paymentResponse->setResponse(json_encode($response));
            $paymentResponse->setResultCode($response['resultCode']);
            $paymentResponse->setMerchantReference($request["reference"]);
            $this->paymentResponseResourceModel->save($paymentResponse);

            $this->remainingOrderAmount -= $deductedAmount;
            $responseArray = $response->toArray();
            $giftCardResponseCollection[] = $responseArray;
            $this->adyenHelper->logResponse($responseArray);
        }

        return $giftCardResponseCollection;
    }

    /**
     * @param array $request
     * @param PaymentsApi $service
     * @return array
     * @throws AdyenException
     * @throws AlreadyExistsException
     * @throws NoSuchEntityException
     */
    public function processGiftcards(array $request, PaymentsApi $service): array
    {
        if (isset($request['giftcardRequestParameters'])) {
            $redeemedGiftcards = $request['giftcardRequestParameters'];
            unset($request['giftcardRequestParameters']);

            $ordersResponse = $this->orderApiHelper->createOrder(
                $request['reference'],
                $request['amount']['value'],
                $request['amount']['currency'],
                $this->storeManager->getStore()->getId()
            );

            $giftcardResponseCollection = $this->handleGiftcardPayments($request, $service, $redeemedGiftcards, $ordersResponse);

            $request['amount']['value'] = $this->remainingOrderAmount;
            $request['order'] = [
                'pspReference' => $ordersResponse['pspReference'],
                'orderData' => $ordersResponse['orderData']
            ];
        } else {
            $giftcardResponseCollection = [];
        }

        return array($request, $giftcardResponseCollection);
    }
}
