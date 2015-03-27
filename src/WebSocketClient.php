<?php
/*
 * @author Grzegorz Zdanowski <grzegorz129@gmail.com>
 *
 * @project TinyWs
 * @package 
 */

namespace noFlash\TinyWs;

use Exception;
use LengthException;
use noFlash\CherryHttp\NodeDisconnectException;

/**
 * Represents standard WebSocket client.
 *
 * @package noFlash\TinyWs
 */
class WebSocketClient extends ClientPacketsRouter
{
    /** @var DataFrame */
    protected $currentPingFrame = null;

    /**
     * {@inheritdoc}
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
     * Disconnects client from server.
     * It also notifies clients handler about that fact.
     *
     * @param bool $drop By default client is disconnected after delivering output buffer contents. Set to true to drop
     *     it immediately.
     *
     * @return void
     */
    public function disconnect($drop = false)
    {
        $this->handler->onClose($this);
        parent::disconnect($drop);
    }

    /**
     * Behaves the same as pushData(), but ensures correct data type.
     * If you want higher performance or you need to sent packets cached internally as strings use pushData().
     *
     * @param RawMessageInterface $message
     *
     * @return bool
     * @see \noFlash\CherryHttp\StreamServerNodeInterface::pushData()
     */
    public function pushMessage(RawMessageInterface $message)
    {
        return $this->pushData((string)$message);
    }

    /**
     * Sends "PING" frame to connected client.
     *
     * @param mixed|null $payload Maximum of 125 bytes. If set to null current time will be used. It's possible to use
     *     empty string.
     *
     * @return string Used payload value
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
     *
     * @throws NodeDisconnectException
     * @throws WebSocketException WebSocketClient provided invalid closing code or whole payload
     */
    protected function handleClientCloseFrame(NetworkFrame $frame)
    {
        $this->logger->debug("Client sent close frame, parsing");

        //TODO strict
        $code = $frame->getPayloadPart(0, 2);

        if (!empty($code)) { //Code is optional BUT it can't be 0
            if (!isset($code[1])) { // payload have to be at least 2 bytes
                throw new WebSocketException("Invalid closing payload", DataFrame::CODE_PROTOCOL_ERROR);
            }

            $code = unpack("n", $code);
            $code = $code[1]; //Array dereference on call is allowed from 5.4
            $this->logger->debug("Client disconnected with code: " . $code);

            if (!DataFrame::validateCloseCode($code)) {
                throw new WebSocketException("Invalid close code", DataFrame::CODE_PROTOCOL_ERROR);
            }

            if (!mb_check_encoding($frame->getPayloadPart(2), 'UTF-8')) {
                throw new WebSocketException("Close frame payload is not valid UTF-8",
                    NetworkFrame::CODE_DATA_TYPE_INCONSISTENT);
            }
        }

        $this->logger->debug("Converting client close frame into server close frame");
        $frame->setOpcode(DataFrame::OPCODE_CLOSE);
        $frame->setMasking(false);
        $frame->setPayload(pack("n", DataFrame::CODE_CLOSE_NORMAL) . "Client closed connection");
        $this->pushData($frame);
        $this->disconnect();
    }

    /**
     * {@inheritdoc}
     */
    protected function handlePingFrame(NetworkFrame $frame)
    {
        $this->logger->debug("Sending pong frame...");
        $frame->setOpcode(DataFrame::OPCODE_PONG);
        $frame->setMasking(false);
        $this->pushData($frame);
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebSocketException Invalid pong payload
     */
    protected function handlePongFrame(NetworkFrame $frame)
    {
        if ($this->currentPingFrame === null) {
            $this->logger->warning("Got unsolicited pong packet from $this - ignoring");

            return;
        }

        if ($this->currentPingFrame->getPayload() !== $frame->getPayload()) {
            throw new WebSocketException("Invalid pong payload", DataFrame::CODE_PROTOCOL_ERROR);
        }

        $this->handler->onPong($this, $this->currentPingFrame);
        $this->currentPingFrame = null;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleMessage(Message $message)
    {
        $this->logger->debug("Got message from router - notifying API");
        $this->handler->onMessage($this, $message);
    }
}
