<?php
namespace noFlash\TinyWs;

use InvalidArgumentException;
use noFlash\CherryHttp\ClientUpgradeException;
use noFlash\CherryHttp\HttpClient;
use noFlash\CherryHttp\HttpCode;
use noFlash\CherryHttp\HttpException;
use noFlash\CherryHttp\HttpRequest;
use noFlash\CherryHttp\HttpRequestHandlerInterface;
use noFlash\CherryHttp\HttpResponse;
use noFlash\CherryHttp\StreamServerNodeInterface;
use Psr\Log\LoggerInterface;

/**
 * This class implements RFC 6455 WebSocket protocol (rev. 13) upgrade requests
 * Currently there's no other versions worth supporting
 *
 * @package noFlash\TinyWs
 * @todo origin validation
 */
class UpgradeHandler implements HttpRequestHandlerInterface
{
    const WEBSOCKET_GUID              = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"; //RFC 6455, page 7
    const SUPPORTED_WEBSOCKET_VERSION = 13;

    /** @var string[] */
    private $handlerPaths;
    private $clientsHandler;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     * @param ClientsHandlerInterface $clientsHandler
     * @param string|string[] $paths One (or more) paths to be handled
     *
     * @throws InvalidArgumentException Raised if invalid path(s) was specified
     */
    function __construct(LoggerInterface $logger, ClientsHandlerInterface $clientsHandler, $paths = "*")
    {
        $this->logger = $logger;
        $this->populatePaths($paths);
        $this->clientsHandler = $clientsHandler;
    }

    /**
     * @param StreamServerNodeInterface $client HTTP client
     * @param HttpRequest $request HTTP request (which is expected to be valid WebSocket upgrade handshake)
     *
     * @throws ClientUpgradeException Raised after client HTTP=>WS handshake is completed
     * @throws HttpException
     */
    public function onRequest(StreamServerNodeInterface $client, HttpRequest $request)
    {
        $this->verifyUpgradeRequest($request);
        $switchResponse = $this->generateSwitchResponse($client, $request);

        $newClient = new WebSocketClient($this->clientsHandler, $client->socket, $this->logger);
        $newClient->pushData($switchResponse);
        $this->clientsHandler->onAfterUpgrade($newClient);

        throw new ClientUpgradeException($client, $newClient);
    }

    /**
     * Generates WebSocket upgrade response & notifies clients handler
     *
     * @param HttpClient|StreamServerNodeInterface $client WebSocketClient which should be upgraded to WebSocket
     *     protocol
     * @param HttpRequest $request Original HTTP upgrade handshake sent by client
     *
     * @return HttpResponse
     */
    private function generateSwitchResponse($client, $request)
    {
        $acceptHeader = sha1($request->getHeader("sec-websocket-key") . self::WEBSOCKET_GUID, true);
        $switchParams = array(
            "Upgrade" => "websocket",
            "Connection" => "Upgrade",
            "Sec-WebSocket-Accept" => base64_encode($acceptHeader)
        );
        $response = new HttpResponse("", $switchParams, HttpCode::SWITCHING_PROTOCOLS);
        $response = $this->clientsHandler->onUpgrade($client, $request, $response);

        return $response;
    }

    /**
     * Validates HTTP => WebSocket upgrade request
     *
     * @param HttpRequest $request HTTP request, it's assumed upgrade request
     *
     * @throws HttpException
     */
    private function verifyUpgradeRequest(HttpRequest $request)
    {
        $this->logger->debug("Attempting to switch protocols (checking preconditions per RFC)");

        //That "ifology" is ugly, but I don't have better idea to perform checks

        //Check request to conform with RFC 6455 [see page 16]
        if ($request->getMethod() !== "GET") {
            throw new HttpException("Cannot upgrade to WebSocket - invalid method", HttpCode::METHOD_NOT_ALLOWED);
        }

        if ($request->getProtocolVersion() != 1.1) {
            throw new HttpException("Cannot upgrade to WebSockets - http version not supported",
                HttpCode::VERSION_NOT_SUPPORTED);
        }

        //Intentionally skipped host & origin validation here

        if (strtolower($request->getHeader("upgrade")) !== "websocket") {
            throw new HttpException("Cannot upgrade to WebSockets - invalid upgrade header", HttpCode::BAD_REQUEST);
        }

        if (strpos(strtolower($request->getHeader("connection")), "upgrade") === false) {
            throw new HttpException("Cannot upgrade to WebSockets - invalid connection header", HttpCode::BAD_REQUEST);
        }

        $wsNoonce = base64_decode($request->getHeader("sec-websocket-key"));
        if (strlen($wsNoonce) !== 16) {
            throw new HttpException("Cannot upgrade to WebSockets - invalid key", HttpCode::BAD_REQUEST);
        }

        if (strtolower($request->getHeader("sec-websocket-version")) != self::SUPPORTED_WEBSOCKET_VERSION) {
            throw new HttpException("Cannot upgrade to WebSockets - version not supported", HttpCode::UPGRADE_REQUIRED,
                array("Sec-WebSocket-Version" => self::SUPPORTED_WEBSOCKET_VERSION));
        }

        $this->logger->debug("RFC check OK");
    }

    /**
     * Verifies & adds paths handled by this handler
     * It's private due to fact that for performance reasons CherryHttp will cache paths for each handler
     *
     * @param string|string[] $paths One (or more) paths to be handled
     *
     * @throws InvalidArgumentException Raised if any of paths is invalid
     */
    private function populatePaths($paths)
    {
        if (!is_array($paths)) {
            $paths = array($paths);
        }

        foreach ($paths as $path) {
            if ($path[0] !== "/" && $path[0] !== "*") {
                throw new InvalidArgumentException("Invalid handler path specified");
            }

            $this->handlerPaths[] = $path;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHandledPaths()
    {
        return $this->handlerPaths;
    }
}
