<?php
namespace noFlash\TinyWs;

use LogicException;
use noFlash\CherryHttp\Server as HttpServer;

/**
 * Main server object. Please note
 *
 * @package noFlash\TinyWs
 */
class Server extends HttpServer
{
    /**
     * In future versions it's possible to add other versions of protocol using different handlers
     * Currently only one version is in use (13), so there's no param to set protocol version
     *
     * @param ClientsHandlerInterface $clientsHandler
     * @param string $ip Address to listen on, use 0.0.0.0 to listen on all addresses
     * @param int $port Port to listen
     * @param string|string[] $paths One (or more) URI(s) to be used as WebSocket endpoints
     *
     * @throws LogicException
     * @throws \noFlash\CherryHttp\ServerException
     */
    public function run(ClientsHandlerInterface &$clientsHandler, $ip = "0.0.0.0", $port = 8080, $paths = "*")
    {
        $this->router->addPathHandler(new UpgradeHandler($this->logger, $clientsHandler, $paths));
        $this->bind($ip, $port);
        parent::run();
    }
}
