<?php
namespace noFlash\TinyWs;

use Exception;
use noFlash\CherryHttp\NodeDisconnectException;
use noFlash\CherryHttp\StreamServerNode;
use noFlash\CherryHttp\StreamServerNodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes packets received from client.
 *
 * @package noFlash\tinyWS
 */
abstract class ClientPacketsRouter extends StreamServerNode implements StreamServerNodeInterface
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var ClientsHandlerInterface */
    protected $handler;

    /** @var Message */
    private $currentMessage = null;

    /** @var NetworkFrame */
    private $currentFrame = null;

    /**
     * @param ClientsHandlerInterface $handler
     * @param resource $socket WebSocketClient stream socket
     * @param LoggerInterface $logger
     */
    public function __construct(ClientsHandlerInterface $handler, $socket, LoggerInterface $logger)
    {
        $this->handler = $handler;
        $this->logger = $logger;

        $peerName = stream_socket_get_name($socket, true);
        parent::__construct($socket, $peerName, $this->logger);
    }

    /**
     * Takes care of current frame.
     *
     * Possible bug-ish behaviour: if any of method called by routeCurrentFrame throws exception isn't resulting in
     * client disconnection $this->currentFrame will not be null-ed. It can possible result in overflow frame exception on
     * next frame received.
     *
     * @throws WebSocketException
     */
    private function routeCurrentFrame()
    {
        $this->logger->debug("Routing frame");

        $opcode = $this->currentFrame->getOpcode();
        if ($opcode === DataFrame::OPCODE_TEXT || $opcode === DataFrame::OPCODE_BINARY || $opcode === DataFrame::OPCODE_CONTINUE) { //Non-control frame
            if ($this->currentMessage === null) //No message in processing - new need to be build
            {
                $this->logger->debug("No message, creating new");
                $this->currentMessage = new Message($this->logger, $this->currentFrame);

            } else { //Only first frame should contain a type
                $this->logger->debug("Adding frame to message");
                $this->currentMessage->addFrame($this->currentFrame);
            }

            if ($this->currentMessage->isComplete()) {
                $this->logger->debug("Message completed, routing");
                $this->handleMessage($this->currentMessage);
                $this->currentMessage = null; //Message is completed, API has been notified so no need to hold it here

            } else {
                $this->logger->debug("Message not completed yet");
            }

        } elseif ($opcode === DataFrame::OPCODE_CLOSE) {
            $this->handleClientCloseFrame($this->currentFrame);

        } elseif ($opcode === DataFrame::OPCODE_PING) {
            $this->handlePingFrame($this->currentFrame);

        } elseif ($opcode === DataFrame::OPCODE_PONG) {
            $this->handlePongFrame($this->currentFrame);

        } else {
            throw new WebSocketException("Non-RFC or reserved opcode, or first message frame with continue opcode",
                DataFrame::CODE_PROTOCOL_ERROR);
        }

        $this->currentFrame = null;
        $this->logger->debug("Frame routing completed");
    }

    /**
     * Process WebSocket data stream collecting frames until final message is reached.
     * It parses one frame at a time, and it's code is messy due to WebSocket protocol craziness.
     *
     * @return bool Returns false in case of some data are still in buffer and can form new frame, true otherwise
     *     (meaning processing of buffer has been finished).
     * @throws NodeDisconnectException
     */
    protected function processInputBuffer()
    {
        $this->logger->debug("Processing WS request...");

        try {
            if ($this->currentFrame === null) //No frame in processing - new need to be build
            {
                $this->logger->debug("Creating new frame in client");
                $this->currentFrame = new NetworkFrame($this->logger, $this->inputBuffer);
            }

            if (!$this->currentFrame->isComplete()) {
                $this->logger->debug("Frame not completed yet, skipping processing");

                return true;
            }

            if(!$this->currentFrame->isMasked()) {
                throw new WebSocketException("Client to server frames must be masked", DataFrame::CODE_PROTOCOL_ERROR);
            }

            //Current frame is completed, this call also "kick" the frame to try fetching new data from buffer (if any)
            $this->routeCurrentFrame();

            return empty($this->inputBuffer); //This method will be called again if buffer is not empty

        } catch(Exception $e) {
            $this->handleWebSocketException($e);

            return true; //handleWebSocketException() will disconnect client, so it's useless to parse buffer
        }
    }

    /**
     * Handles complete message received from client.
     *
     * @param Message $message
     */
    abstract protected function handleMessage(Message $message);

    /**
     * Handles WebSocketException & other exception thrown by client by sending proper close response.
     *
     * @param WebSocketException|Exception $e
     */
    abstract protected function handleWebSocketException(Exception $e);

    /**
     * Verifies & responds to close frame sent by client to server.
     *
     * @param NetworkFrame $frame
     */
    abstract protected function handleClientCloseFrame(NetworkFrame $frame);

    /**
     * Handles responses to ping frames sent by clients to server.
     *
     * @param NetworkFrame $frame
     */
    abstract protected function handlePingFrame(NetworkFrame $frame);

    /**
     * Verifies pong frame & notifies children class about success pong.
     *
     * @param NetworkFrame $frame
     */
    abstract protected function handlePongFrame(NetworkFrame $frame);
}
