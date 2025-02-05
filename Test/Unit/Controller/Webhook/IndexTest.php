<?php

namespace Adyen\Payment\Test\Unit\Controller\Webhook;

use Adyen\Payment\Controller\Webhook\Index;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\IpAddress;
use Adyen\Payment\Model\NotificationFactory;
use Adyen\Payment\Helper\RateLimiter;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Webhook\Receiver\HmacSignature;
use Adyen\Webhook\Receiver\NotificationReceiver;
use Magento\Framework\App\Request\Http as Http;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Adyen\Payment\Test\Unit\AbstractAdyenTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class IndexTest extends AbstractAdyenTestCase
{
    private Context|MockObject $contextMock;
    private MockObject|RequestInterface $requestMock;
    private ResponseInterface|MockObject $responseMock;
    private JsonFactory|MockObject $resultJsonFactoryMock;
    private MockObject|Json $resultJsonMock;
    private Data|MockObject $adyenHelperMock;
    private MockObject|NotificationFactory $notificationHelperMock;
    private SerializerInterface|MockObject $serializerMock;
    private AdyenLogger|MockObject $adyenLoggerMock;
    private Index $indexController;
    private http|MockObject $httpMock;
    private IpAddress|MockObject $ipAddressHelperMock;
    private Config|MockObject $configHelperMock;
    private RateLimiter|MockObject $rateLimiterHelperMock;
    private HmacSignature|MockObject $hmacSignatureMock;
    private NotificationReceiver|MockObject $notificationReceiverMock;
    private RemoteAddress|MockObject $remoteAddressMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->httpMock = $this->getMockBuilder(http::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->getMockForAbstractClass();
        $this->responseMock = $this->getMockBuilder(ResponseInterface::class)
            ->getMockForAbstractClass();
        $this->resultJsonFactoryMock = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultJsonMock = $this->getMockBuilder(Json::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->adyenHelperMock = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->notificationHelperMock = $this->createGeneratedMock(NotificationFactory::class, [
            'create'
        ]);
        $this->serializerMock = $this->getMockBuilder(SerializerInterface::class)
            ->getMockForAbstractClass();
        $this->adyenLoggerMock = $this->getMockBuilder(AdyenLogger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->ipAddressHelperMock = $this->getMockBuilder(IpAddress::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->configHelperMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->rateLimiterHelperMock = $this->getMockBuilder(RateLimiter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->hmacSignatureMock = $this->getMockBuilder(HmacSignature::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->notificationReceiverMock = $this->getMockBuilder(NotificationReceiver::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->remoteAddressMock = $this->getMockBuilder(RemoteAddress::class)
            ->disableOriginalConstructor()
            ->getMock();


        $this->contextMock->method('getRequest')->willReturn($this->requestMock);
        $this->contextMock->method('getResponse')->willReturn($this->responseMock);
        $this->resultJsonFactoryMock->method('create')->willReturn($this->resultJsonMock);

        $this->indexController = new Index(
            $this->contextMock,
            $this->notificationHelperMock,
            $this->adyenHelperMock,
            $this->adyenLoggerMock,
            $this->serializerMock,
            $this->configHelperMock,
            $this->ipAddressHelperMock,
            $this->rateLimiterHelperMock,
            $this->hmacSignatureMock,
            $this->notificationReceiverMock,
            $this->remoteAddressMock,
            $this->httpMock
        );
    }

    public function testLoadNotificationFromRequest()
    {
        $notificationMock = $this->getMockBuilder(Notification::class)
            ->disableOriginalConstructor()
            ->getMock();
        $notificationMock->expects($this->once())->method('setCreatedAt');
        $notificationMock->expects($this->once())->method('setUpdatedAt');
        $this->invokeMethod(
            $this->indexController,
            'loadNotificationFromRequest',
            [$notificationMock, []]
        );
    }

    protected function tearDown(): void
    {
        // Reset $_SERVER global after each test
        $_SERVER = [];
    }

    public function testFixCgiHttpAuthenticationWithExistingAuth()
    {
        $_SERVER['PHP_AUTH_USER'] = 'existingUser';
        $_SERVER['PHP_AUTH_PW'] = 'existingPassword';

        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertEquals('existingUser', $_SERVER['PHP_AUTH_USER']);
        $this->assertEquals('existingPassword', $_SERVER['PHP_AUTH_PW']);
    }

    public function testFixCgiHttpAuthenticationWithRedirectRemoteAuthorization()
    {
        $_SERVER['REDIRECT_REMOTE_AUTHORIZATION'] = 'Basic ' . base64_encode('testUser:testPassword');

        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertEquals('testUser', $_SERVER['PHP_AUTH_USER']);
        $this->assertEquals('testPassword', $_SERVER['PHP_AUTH_PW']);
    }

    public function testFixCgiHttpAuthenticationWithRedirectHttpAuthorization()
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testUser:testPassword');

        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertEquals('testUser', $_SERVER['PHP_AUTH_USER']);
        $this->assertEquals('testPassword', $_SERVER['PHP_AUTH_PW']);
    }

    public function testFixCgiHttpAuthenticationWithHttpAuthorization()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testUser:testPassword');

        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertEquals('testUser', $_SERVER['PHP_AUTH_USER']);
        $this->assertEquals('testPassword', $_SERVER['PHP_AUTH_PW']);
    }

    public function testFixCgiHttpAuthenticationWithRemoteUser()
    {
        $_SERVER['REMOTE_USER'] = 'Basic ' . base64_encode('testUser:testPassword');

        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertEquals('testUser', $_SERVER['PHP_AUTH_USER']);
        $this->assertEquals('testPassword', $_SERVER['PHP_AUTH_PW']);
    }

    public function testFixCgiHttpAuthenticationWithRedirectRemoteUser()
    {
        $_SERVER['REDIRECT_REMOTE_USER'] = 'Basic ' . base64_encode('testUser:testPassword');

        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertEquals('testUser', $_SERVER['PHP_AUTH_USER']);
        $this->assertEquals('testPassword', $_SERVER['PHP_AUTH_PW']);
    }

    public function testFixCgiHttpAuthenticationWithNoAuthorizationHeaders()
    {
        $this->invokeMethod(
            $this->indexController,
            'fixCgiHttpAuthentication'
        );

        $this->assertArrayNotHasKey('PHP_AUTH_USER', $_SERVER);
        $this->assertArrayNotHasKey('PHP_AUTH_PW', $_SERVER);
    }
}