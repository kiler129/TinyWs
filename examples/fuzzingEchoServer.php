<?php
require_once('../../../autoload.php');

use noFlash\TinyWs\AbstractClientsHandler;
use noFlash\TinyWs\Message;
use noFlash\TinyWs\Server;
use noFlash\TinyWs\WebSocketClient;

class FuzzingHandler extends AbstractClientsHandler
{
    public function onMessage(WebSocketClient $client, Message $message)
    {
        $client->pushData($message); //Faster than pushMessage but will not stop you from passing invalid packets
    }
}

$server = new Server();
$server->run(new FuzzingHandler());
