<?php
//require_once('../../../autoload.php');
require_once('_RequiredFiles.php');

use noFlash\TinyWs\AbstractClientsHandler;
use noFlash\TinyWs\Message;
use noFlash\TinyWs\Server;
use noFlash\TinyWs\WebSocketClient;

class FuzzingHandler extends AbstractClientsHandler
{
    public function onMessage(WebSocketClient $client, Message $message)
    {
        $client->pushData($message);
    }
}

$server = new Server();
$server->run(new FuzzingHandler());
