<?php
namespace noFlash\TinyWs;

use noFlash\CherryHttp\HttpClient;
use noFlash\CherryHttp\HttpRequest;
use noFlash\CherryHttp\HttpResponse;
use Psr\Log\LoggerInterface;

/**
 * By extending this object you could implement only onMessage() method - it cannot be easier ;)
 *
 * @package noFlash\TinyWs
 */
abstract class AbstractClientsHandler implements ClientsHandlerInterface
{
    /** @var LoggerInterface */
    protected $logger;

    /**
     * {@inheritdoc}
     */
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function onUpgrade(HttpClient $client, HttpRequest $request, HttpResponse $response)
    {
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function onAfterUpgrade(WebSocketClient $client)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onException(WebSocketClient $client, $code)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onPong(WebSocketClient $client, DataFrame $pongFrame)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onClose(WebSocketClient $client)
    {
    }
}
