<?php
require_once('_RequiredFiles.php');

use noFlash\Shout\Shout;
use noFlash\TinyWs\AbstractClientsHandler;
use noFlash\TinyWs\Message;
use noFlash\TinyWs\Server;
use noFlash\TinyWs\WebSocketClient;

class EchoHandler extends AbstractClientsHandler
{
    public function onMessage(WebSocketClient $client, Message $message)
    {
        $client->pushData($message);
    }
}

$server = new Server(new Shout());
$server->run(new EchoHandler());
