<?php

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use React\Promise\Internal\RejectedPromise;
use React\Promise\Promise;

class ConnectorTest extends TestCase
{
    public function test_construct_without_loop_assigns_loop_automatically()
    {
        $factory = new Connector;

        $ref = new ReflectionProperty($factory, '_loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function uriDataProvider()
    {
        return [
            ['ws://127.0.0.1', 'tcp://127.0.0.1:80'],
            ['wss://127.0.0.1', 'tls://127.0.0.1:443'],
            ['ws://127.0.0.1:1234', 'tcp://127.0.0.1:1234'],
            ['wss://127.0.0.1:4321', 'tls://127.0.0.1:4321'],
        ];
    }

    /**
     * @dataProvider uriDataProvider
     *
     * @param  mixed  $uri
     * @param  mixed  $expectedConnectorUri
     */
    public function test_secure_connection_uses_tls_scheme($uri, $expectedConnectorUri)
    {
        $loop = Loop::get();

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $connector->expects($this->once())
            ->method('connect')
            ->with($this->callback(function ($uri) use ($expectedConnectorUri) {
                return $uri === $expectedConnectorUri;
            }))
            // reject the promise so that we don't have to mock a connection here
            ->willReturn(new RejectedPromise(new Exception('')));

        $pawlConnector = new Connector($loop, $connector);

        $pawlConnector($uri);
    }

    public function test_connector_rejects_when_underlying_socket_connector_rejects()
    {
        $exception = new RuntimeException('Connection failed');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn(\React\Promise\reject($exception));

        $pawlConnector = new Connector($loop, $connector);

        $promise = $pawlConnector('ws://localhost');

        $actual = null;
        $promise->then(null, function ($reason) use (&$actual) {
            $actual = $reason;
        });
        $this->assertSame($exception, $actual);
    }

    public function test_cancel_connector_should_cancel_underlying_socket_connector_when_socket_connection_is_pending()
    {
        $promise = new Promise(function () {}, function () use (&$cancelled) {
            $cancelled++;
        });

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn($promise);

        $pawlConnector = new Connector($loop, $connector);

        $promise = $pawlConnector('ws://localhost');

        $this->assertNull($cancelled);
        $promise->cancel();
        $this->assertEquals(1, $cancelled);

        $message = null;
        $promise->then(null, function ($reason) use (&$message) {
            $message = $reason->getMessage();
        });
        $this->assertEquals('Connection to ws://localhost cancelled during handshake', $message);
    }

    public function test_cancel_connector_should_close_underlying_socket_connection_when_handshake_is_pending()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('close');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn(\React\Promise\resolve($connection));

        $pawlConnector = new Connector($loop, $connector);

        $promise = $pawlConnector('ws://localhost');

        $promise->cancel();

        $message = null;
        $promise->then(null, function ($reason) use (&$message) {
            $message = $reason->getMessage();
        });
        $this->assertEquals('Connection to ws://localhost cancelled during handshake', $message);
    }
}
