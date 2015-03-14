<?php
namespace noFlash\TinyWs;

use Exception;
use LengthException;
use noFlash\CherryHttp\NodeDisconnectException;
use noFlash\CherryHttp\StreamServerNode;
use noFlash\CherryHttp\StreamServerNodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Class WebSocketClient
 * @package noFlash\tinyWS
 */
class WebSocketClient extends StreamServerNode implements StreamServerNodeInterface
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var ClientsHandlerInterface */
    private $handler;

    /** @var Message */
    private $currentMessage = null;

    /** @var NetworkFrame */
    private $currentFrame = null;

    /** @var DataFrame */
    private $currentPingFrame = null;

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
                $this->logger->debug("Message completed, notifying API");
                $this->handler->onMessage($this, $this->currentMessage);
                $this->currentMessage = null; //Message is completed, API has been notified so no need to hold it here

            } else {
                $this->logger->debug("Message not completed yet");
            }

        } elseif ($opcode === DataFrame::OPCODE_CLOSE) {
            $this->handleClientCloseFrame();

        } elseif ($opcode === DataFrame::OPCODE_PING) {
            $this->handlePingFrame();

        } elseif ($opcode === DataFrame::OPCODE_PONG) {
            $this->handlePongFrame();

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

            //Current frame is completed, this call also "kick" the frame to try fetching new data from buffer (if any)
            $this->routeCurrentFrame();

            return empty($this->inputBuffer); //This method will be called again if buffer is not empty


        } catch(Exception $e) {
            $this->handleWebSocketException($e);

            return true; //handleWebSocketException() will disconnect client, so it's useless to parse buffer
        }
    }

    /**
     * Handles WebSocketException & other exception thrown by client by sending proper close response.
     *
     * @param WebSocketException|Exception $e
     */
    protected function handleWebSocketException(Exception $e)
    {
        $code = ($e instanceof WebSocketException) ? $e->getCode() : DataFrame::CODE_ABNORMAL;
        $respondPayload = pack("n", $code) . $e->getMessage();

        $this->logger->warning("WebSocket exception occurred #" . $e->getCode() . " for " . $this . " client");
        $this->handler->onException($this, $code);

        $error = new DataFrame($this->logger);
        $error->setOpcode(DataFrame::OPCODE_CLOSE);
        $error->setPayload($respondPayload);
        $this->pushData($error);
        $this->disconnect();
    }

    /**
     * Sends "PING" frame to connected client.
     *
     * @param mixed|null $payload Maximum of 125 bytes. If set to null current time will be used. It's possible to use
     *     empty string.
     *
     * @return mixed Used payload value
     * @throws LengthException In case of payload exceed 125 bytes.
     */
    public function ping($payload = null)
    {
        if ($payload === null) {
            $payload = microtime(true);

        } elseif (isset($payload[125])) { //Much faster than strlen($payload) > 125
            throw new LengthException("Ping payload cannot be larger than 125 bytes");
        }

        $this->currentPingFrame = new DataFrame($this->logger);
        $this->currentPingFrame->setOpcode(DataFrame::OPCODE_PING);
        $this->currentPingFrame->setPayload($payload);
        $this->pushData($this->currentPingFrame);

        return $payload;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect($drop = false)
    {
        $this->handler->onClose($this);
        parent::disconnect($drop);
    }

    /**
     * Verifies & responds to close frame sent by client to server.
     *
     * @throws NodeDisconnectException
     * @throws WebSocketException WebSocketClient provided invalid closing code.
     */
    private function handleClientCloseFrame()
    {
        $this->logger->debug("Client sent close frame, parsing");

        //TODO strict
        $code = $this->currentFrame->getPayloadPart(0, 2);
        if (!empty($code)) { //Code is optional BUT it can't be 0
            $code = unpack("n", $code);
            $code = $code[1]; //Array dereference on call is allowed from 5.4
            $this->logger->debug("Client disconnected with code: " . $code);

            if (!DataFrame::validateCloseCode($code)) {
                throw new WebSocketException("Invalid close code", DataFrame::CODE_PROTOCOL_ERROR);
            }

            //TODO: well, this may be required by RFC - I'm not 100% sure
            //if(!mb_check_encoding($this->currentFrame->getPayload(), 'UTF-8')) {
            //    throw new WebSocketException("Text is not valid UTF-8", NetworkFrame::CODE_DATA_TYPE_INCONSISTENT);
            //}
        }

        $this->logger->debug("Converting client close frame into server close frame");
        $this->currentFrame->setOpcode(DataFrame::OPCODE_CLOSE);
        $this->currentFrame->setMasking(false);
        $this->currentFrame->setPayload(pack("n", DataFrame::CODE_CLOSE_NORMAL) . "Client closed connection");
        $this->pushData($this->currentFrame);
        $this->disconnect();
        $this->currentFrame = null;
    }


    /**
     * Handles responses to ping frames sent by clients to server.
     */
    private function handlePingFrame()
    {
        $this->logger->debug("Sending pong frame...");
        $this->currentFrame->setOpcode(DataFrame::OPCODE_PONG);
        $this->currentFrame->setMasking(false);
        $this->pushData($this->currentFrame);
    }

    /**
     * Verifies pong frame & notifies children class about success pong.
     *
     * @throws WebSocketException Invalid pong payload
     */
    private function handlePongFrame()
    {
        if ($this->currentPingFrame === null) {
            $this->logger->warning("Got unsolicited pong packet from $this - ignoring");

            return;
        }

        if ($this->currentPingFrame->getPayload() !== $this->currentFrame->getPayload()) {
            throw new WebSocketException("Invalid pong payload", DataFrame::CODE_PROTOCOL_ERROR);
        }

        $this->handler->onPong($this, $this->currentPingFrame);
        $this->currentPingFrame = null;
    }
}
