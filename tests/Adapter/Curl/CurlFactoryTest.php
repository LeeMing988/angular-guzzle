<?php

// Override curl_setopt_array() to get the last set curl options
namespace GuzzleHttp\Adapter\Curl
{
    function curl_setopt_array($handle, array $options)
    {
        $_SERVER['last_curl'] = $options;
        \curl_setopt_array($handle, $options);
    }
}

namespace GuzzleHttp\Tests\Adapter\Curl {

    require_once __DIR__ . '/../../Server.php';

    use GuzzleHttp\Adapter\Curl\CurlAdapter;
    use GuzzleHttp\Adapter\Curl\MultiAdapter;
    use GuzzleHttp\Event\BeforeEvent;
    use GuzzleHttp\Message\RequestInterface;
    use GuzzleHttp\Message\Response;
    use GuzzleHttp\Stream\Stream;
    use GuzzleHttp\Tests\Server;
    use GuzzleHttp\Adapter\Curl\CurlFactory;
    use GuzzleHttp\Adapter\Transaction;
    use GuzzleHttp\Client;
    use GuzzleHttp\Message\MessageFactory;
    use GuzzleHttp\Message\Request;

    /**
     * @covers GuzzleHttp\Adapter\Curl\CurlFactory
     */
    class CurlFactoryTest extends \PHPUnit_Framework_TestCase
    {
        /** @var \GuzzleHttp\Tests\Server */
        static $server;

        public static function setUpBeforeClass()
        {
            self::$server = new Server();
            self::$server->start();
            unset($_SERVER['last_curl']);
        }

        public static function tearDownAfterClass()
        {
            self::$server->stop();
            unset($_SERVER['last_curl']);
        }

        public function testCreatesCurlHandle()
        {
            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nFoo: Bar\r\n Baz:  bam\r\nContent-Length: 2\r\n\r\nhi"]);
            $request = new Request('PUT', self::$server->getUrl() . 'haha', ['Hi' => ' 123'], Stream::factory('testing'));
            $stream = Stream::factory();
            $request->getConfig()->set('save_to', $stream);
            $request->getConfig()->set('verify', true);
            $this->emit($request);

            $t = new Transaction(new Client(), $request);
            $f = new CurlFactory();
            $h = $f->createHandle($t, new MessageFactory());
            $this->assertInternalType('resource', $h);
            curl_exec($h);
            $response = $t->getResponse();
            $this->assertInstanceOf('GuzzleHttp\Message\ResponseInterface', $response);
            $this->assertEquals('hi', $response->getBody());
            $this->assertEquals('Bar', $response->getHeader('Foo'));
            $this->assertEquals('bam', $response->getHeader('Baz'));
            curl_close($h);

            $sent = self::$server->getReceivedRequests(true)[0];
            $this->assertEquals('PUT', $sent->getMethod());
            $this->assertEquals('/haha', $sent->getPath());
            $this->assertEquals('123', $sent->getHeader('Hi'));
            $this->assertEquals('7', $sent->getHeader('Content-Length'));
            $this->assertEquals('testing', $sent->getBody());
            $this->assertEquals('1.1', $sent->getProtocolVersion());
            $this->assertEquals('hi', (string) $stream);

            $this->assertEquals(2, $_SERVER['last_curl'][CURLOPT_SSL_VERIFYHOST]);
            $this->assertEquals(true, $_SERVER['last_curl'][CURLOPT_SSL_VERIFYPEER]);
        }

        public function testSendsHeadRequests()
        {
            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n"]);
            $request = new Request('HEAD', self::$server->getUrl());
            $this->emit($request);

            $t = new Transaction(new Client(), $request);
            $f = new CurlFactory();
            $h = $f->createHandle($t, new MessageFactory());
            curl_exec($h);
            curl_close($h);
            $response = $t->getResponse();
            $this->assertEquals('2', $response->getHeader('Content-Length'));
            $this->assertEquals('', $response->getBody());

            $sent = self::$server->getReceivedRequests(true)[0];
            $this->assertEquals('HEAD', $sent->getMethod());
            $this->assertEquals('/', $sent->getPath());
        }

        public function testSendsPostRequestWithNoBody()
        {
            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
            $request = new Request('POST', self::$server->getUrl());
            $this->emit($request);
            $t = new Transaction(new Client(), $request);
            $h = (new CurlFactory())->createHandle($t, new MessageFactory());
            curl_exec($h);
            curl_close($h);
            $sent = self::$server->getReceivedRequests(true)[0];
            $this->assertEquals('POST', $sent->getMethod());
            $this->assertEquals('', $sent->getBody());
        }

        public function testSendsChunkedRequests()
        {
            $stream = $this->getMockBuilder('GuzzleHttp\Stream\Stream')
                ->setConstructorArgs([fopen('php://temp', 'r+')])
                ->setMethods(['getSize'])
                ->getMock();
            $stream->expects($this->any())
                ->method('getSize')
                ->will($this->returnValue(null));
            $stream->write('foo');
            $stream->seek(0);

            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
            $request = new Request('PUT', self::$server->getUrl(), [], $stream);
            $this->emit($request);
            $this->assertNull($request->getBody()->getSize());
            $t = new Transaction(new Client(), $request);
            $h = (new CurlFactory())->createHandle($t, new MessageFactory());
            curl_exec($h);
            curl_close($h);
            $sent = self::$server->getReceivedRequests(false)[0];
            $this->assertContains('PUT / HTTP/1.1', $sent);
            $this->assertContains('transfer-encoding: chunked', strtolower($sent));
            $this->assertContains("\r\n\r\nfoo", $sent);
        }

        public function testDecodesGzippedResponses()
        {
            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
            $request = new Request('GET', self::$server->getUrl(), ['Accept-Encoding' => '']);
            $this->emit($request);
            $t = new Transaction(new Client(), $request);
            $h = (new CurlFactory())->createHandle($t, new MessageFactory());
            curl_exec($h);
            curl_close($h);
            $this->assertEquals('foo', $t->getResponse()->getBody());
            $sent = self::$server->getReceivedRequests(true)[0];
            $this->assertContains('gzip', $sent->getHeader('Accept-Encoding'));
        }

        public function testAddsDebugInfoToBuffer()
        {
            $r = fopen('php://temp', 'r+');
            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
            $request = new Request('GET', self::$server->getUrl());
            $request->getConfig()->set('debug', $r);
            $this->emit($request);
            $t = new Transaction(new Client(), $request);
            $h = (new CurlFactory())->createHandle($t, new MessageFactory());
            curl_exec($h);
            curl_close($h);
            rewind($r);
            $this->assertNotEmpty(stream_get_contents($r));
        }

        public function testAddsProxyOptions()
        {
            $request = new Request('GET', self::$server->getUrl());
            $this->emit($request);
            $request->getConfig()->set('proxy', '123');
            $request->getConfig()->set('connect_timeout', 1);
            $request->getConfig()->set('timeout', 2);
            $request->getConfig()->set('cert', __FILE__);
            $request->getConfig()->set('ssl_key', [__FILE__, '123']);
            $request->getConfig()->set('verify', false);
            $t = new Transaction(new Client(), $request);
            $f = new CurlFactory();
            curl_close($f->createHandle($t, new MessageFactory()));
            $this->assertEquals('123', $_SERVER['last_curl'][CURLOPT_PROXY]);
            $this->assertEquals(1000, $_SERVER['last_curl'][CURLOPT_CONNECTTIMEOUT_MS]);
            $this->assertEquals(2000, $_SERVER['last_curl'][CURLOPT_TIMEOUT_MS]);
            $this->assertEquals(__FILE__, $_SERVER['last_curl'][CURLOPT_SSLCERT]);
            $this->assertEquals(__FILE__, $_SERVER['last_curl'][CURLOPT_SSLKEY]);
            $this->assertEquals('123', $_SERVER['last_curl'][CURLOPT_SSLKEYPASSWD]);
            $this->assertEquals(0, $_SERVER['last_curl'][CURLOPT_SSL_VERIFYHOST]);
            $this->assertEquals(false, $_SERVER['last_curl'][CURLOPT_SSL_VERIFYPEER]);
        }

        /**
         * @expectedException \RuntimeException
         */
        public function testEnsuresCertExists()
        {
            $request = new Request('GET', self::$server->getUrl());
            $this->emit($request);
            $request->getConfig()->set('cert', __FILE__ . 'ewfwef');
            (new CurlFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
        }

        /**
         * @expectedException \RuntimeException
         */
        public function testEnsuresKeyExists()
        {
            $request = new Request('GET', self::$server->getUrl());
            $this->emit($request);
            $request->getConfig()->set('ssl_key', __FILE__ . 'ewfwef');
            (new CurlFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
        }

        /**
         * @expectedException \RuntimeException
         */
        public function testEnsuresCacertExists()
        {
            $request = new Request('GET', self::$server->getUrl());
            $this->emit($request);
            $request->getConfig()->set('verify', __FILE__ . 'ewfwef');
            (new CurlFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
        }

        public function testClientUsesSslByDefault()
        {
            self::$server->flush();
            self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
            $f = new CurlFactory();
            $client = new Client([
                'base_url' => self::$server->getUrl(),
                'adapter' => new MultiAdapter(new MessageFactory(), ['handle_factory' => $f])
            ]);
            $client->get();
            $this->assertEquals(2, $_SERVER['last_curl'][CURLOPT_SSL_VERIFYHOST]);
            $this->assertEquals(true, $_SERVER['last_curl'][CURLOPT_SSL_VERIFYPEER]);
            $this->assertFileExists($_SERVER['last_curl'][CURLOPT_CAINFO]);
        }

        public function testConvertsConstantNameKeysToValues()
        {
            $request = new Request('GET', self::$server->getUrl());
            $request->getConfig()->set('curl', ['CURLOPT_USERAGENT' => 'foo']);
            $this->emit($request);
            $f = new CurlFactory();
            curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
            $this->assertEquals('foo', $_SERVER['last_curl'][CURLOPT_USERAGENT]);
        }

        public function testStripsFragment()
        {
            $request = new Request('GET', self::$server->getUrl() . '#foo');
            $this->emit($request);
            $f = new CurlFactory();
            curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
            $this->assertEquals(self::$server->getUrl(), $_SERVER['last_curl'][CURLOPT_URL]);
        }

        public function testDoesNotSendSizeTwice()
        {
            $request = new Request('PUT', self::$server->getUrl(), [], Stream::factory(str_repeat('a', 32769)));
            $this->emit($request);
            $f = new CurlFactory();
            curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
            $this->assertEquals(32769, $_SERVER['last_curl'][CURLOPT_INFILESIZE]);
            $this->assertNotContains('Content-Length', implode(' ', $_SERVER['last_curl'][CURLOPT_HTTPHEADER]));
        }

        private function emit(RequestInterface $request)
        {
            $event = new BeforeEvent(new Transaction(new Client(), $request));
            $request->getEmitter()->emit('before', $event);
        }
    }
}