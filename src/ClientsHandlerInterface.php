<?php
namespace noFlash\TinyWs;

use noFlash\CherryHttp\HttpClient;
use noFlash\CherryHttp\HttpRequest;
use noFlash\CherryHttp\HttpResponse;
use Psr\Log\LoggerInterface;

/**
 * WebSocket client handler interface
 *
 * @package noFlash\TinyWS
 */
interface ClientsHandlerInterface
{

    public function __construct(LoggerInterface $logger);

    /**
     * Called when new client is connected & upgrade is possible.
     * Method is called only if valid upgrade headers are provided.
     *
     * @param HttpClient $client
     * @param HttpRequest $request
     * @param HttpResponse $response
     *
     * @return HttpResponse
     */
    public function onUpgrade(HttpClient $client, HttpRequest $request, HttpResponse $response);

    /**
     * Called everytime new message is received.
     *
     * @param WebSocketClient $client
     * @param Message $message
     *
     */
    public function onMessage(WebSocketClient $client, Message $message);

    /**
     * If something bad happen during communication this method is called with failure code & client object
     *
     * @param WebSocketClient $client
     * @param int $code Failure code as described by DataFrame::CODE_* constants
     *
     * @see DataFrame
     */
    public function onException(WebSocketClient $client, $code);

    /**
     * Called everytime valid pong packet is received in response to sent ping packet.
     *
     * @param WebSocketClient $client
     * @param DataFrame $pongFrame
     */
    public function onPong(WebSocketClient $client, DataFrame $pongFrame);

    /**
     * Called when client disconnection was requested (either by server or client).
     * Note: This method can be called multiple times for single client. Implementation should ignore unwanted calls
     * without throwing exception.
     *
     * @param WebSocketClient $client
     *
     * @return mixed
     * @see Client::disconnect()
     * @see \noFlash\CherryHttp\StreamServerNode::disconnect()
     */
    public function onClose(WebSocketClient $client);
}
