<?php
namespace noFlash\TinyWs;

/* Just for reference how stupid WebSocket frame structure is:

      [   1st byte  ] [   2nd byte  ] [   3rd byte  ] [   4tn byte  ]
      0 1 2 3 4 5 6 7 0 1 2 3 4 5 6 7 0 1 2 3 4 5 6 7 0 1 2 3 4 5 6 7
     +-+-+-+-+-------+-+-------------+-------------------------------+
     |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
     |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
     |N|V|V|V|       |S|             |   (if payload len==126/127)   |
     | |1|2|3|       |K|             |                               |
     +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
     |     Extended payload length continued, if payload len == 127  |
     + - - - - - - - - - - - - - - - +-------------------------------+
     |                               |Masking-key, if MASK set to 1  |
     +-------------------------------+-------------------------------+
     | Masking-key (continued)       |          Payload Data         |
     +-------------------------------- - - - - - - - - - - - - - - - +
     :                     Payload Data continued ...                :
     + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
     |                     Payload Data continued ...                |
     +---------------------------------------------------------------+
*/
use LogicException;
use Psr\Log\LoggerInterface;
use UnderflowException;

/**
 * This implementation is REALLY ugly, I kindly welcome any pulls reqs.
 * It was wrote this way to have at least some performance.
 * Please note that this class IS NOT idiot proof, so you can eg. call setFin() during frame collection and it will
 * mark frame as final (unless you call it before first byte)!
 *
 * Do not try to read this class without deep understanding of bitwise operations & RFC 6455.
 *
 * @package noFlash\tinyWS
 */
class NetworkFrame extends DataFrame
{
    /** @var &string Reference to client input buffer */
    private $buffer;
    /** @var int Number of bytes needed to be collected to finish frame */
    private $remainingPayloadBytes = 0;
    /** @var bool Specifies is frame header is complete and payload can be collected */
    protected $headersCollected = false;

    /**
     * @param LoggerInterface $logger
     * @param string &$buffer
     *
     * @throws LogicException
     * @throws WebSocketException
     */
    public function __construct(LoggerInterface $logger, &$buffer)
    {
        $this->logger = $logger;

        $this->logger->debug("Creating new NetworkFrame [bufl=" . strlen($buffer) . "]");
        $this->buffer = &$buffer;

        if ($this->collectHeader() && !empty($buffer)) { //Probably it's possible to collect buffer right away
            $this->collectPayload();
        }
    }

    /**
     * This method will suck you into the vortex of nowhere, will rape you & stole every penny from your wallet
     * ...and if you're lucky it'll collect headers for WebSocket frame from given buffer.
     * Please, do not swear on my skills after reading that piece of code, I've really tried
     *
     * @wastedHoursCounter 23.5 Increment after every failure of this method performance or OO optimization
     * @return bool Returns true if headers has been collected, false otherwise
     * @throws LogicException Thrown if called after headers collection has been completed
     * @throws WebSocketException
     */
    private function collectHeader()
    {
        if ($this->headersCollected) {
            throw new LogicException("Frame header already collected!");
        }

        $this->logger->debug("Collecting frame header...");
        $bufferLength = strlen($this->buffer);
        if ($bufferLength < 2) //Nothing to do here for now, unlikely in real-life but possible
        {
            $this->logger->debug("Nothing to collect - not enough data in buffer");

            return false;
        }

        if ($this->firstFrameByte === null && $bufferLength >= 2) { //Basic header (fin+rsv[1-3]+opcode+mask+1st byte of payload len) not yet present & can be read
            $this->logger->debug("Collecting basic header [got at least 2B]");

            $this->firstFrameByte = ord($this->buffer[0]);
            $this->secondFrameByte = ord($this->buffer[1]);
            $this->buffer = substr($this->buffer, 2);
            $bufferLength -= 2;

            $this->logger->debug("1st byte: " . decbin($this->firstFrameByte) . " [" . $this->firstFrameByte . "], 2nd byte: " . decbin($this->secondFrameByte) . " [" . $this->secondFrameByte . "]");

            $this->payloadLengthDiscriminator = $this->secondFrameByte & ~128;

            if ($this->payloadLengthDiscriminator < 126) { //Payload length fits in first byte [7 bits]
                $this->payloadLength = $this->payloadLengthDiscriminator;
                $this->remainingPayloadBytes = $this->payloadLength;

                $this->logger->debug("Payload length got from 1st bte [l=" . $this->payloadLength . "]");
            }
        }

        if ($this->firstFrameByte & 112) { //Note: tinyWS doesn't support extensions yet
            throw new WebSocketException("Used RSV w/o ext. negotiation", DataFrame::CODE_PROTOCOL_ERROR);
        }

        $currentFrameOpcode = $this->firstFrameByte & ~240;
        if (($currentFrameOpcode === DataFrame::OPCODE_CLOSE || $currentFrameOpcode === DataFrame::OPCODE_PING || $currentFrameOpcode === DataFrame::OPCODE_PONG) && (!$this->isFin() || $this->payloadLengthDiscriminator > 125)
        ) {
            throw new WebSocketException("Control frames cannot be fragmented or contain payload larger than 125 bytes",
                DataFrame::CODE_PROTOCOL_ERROR);
        }

        if ($this->payloadLength === null) { //Payload len. not collected yet (will exec. only if payload length is extended, >125 bytes)
            $this->logger->debug("Payload length exceed 1st bte [>125B], trying to get total");

            if ($this->payloadLengthDiscriminator === 126 && $bufferLength >= 2) { //7+16 bits
                $this->payloadLength = unpack("n",
                    $this->buffer[0] . $this->buffer[1]); //Actually it's faster than substr ;)
                $this->payloadLength = $this->payloadLength[1]; //Array dereference on call is allowed from 5.4

                $this->remainingPayloadBytes = $this->payloadLength;
                $this->buffer = substr($this->buffer, 2);
                $bufferLength -= 2;

                $this->logger->debug("Payload length got from 16b [l=" . $this->payloadLength . "]");

            } elseif ($this->payloadLengthDiscriminator === 127 && $bufferLength >= 4) { //7+64 bits
                //Note that this also WORKS on 32-bits system & Window$ - PHP will automagically converts to float on integer overflow

                //In this case pack("J") is ~40% faster, but it supported since 5.6.3 which is not very common now
                list($higher, $lower) = array_values(unpack('N2', substr($this->buffer, 0, 8)));
                $this->payloadLength = $higher << 32 | $lower;

                $this->remainingPayloadBytes = $this->payloadLength;
                $this->buffer = substr($this->buffer, 8);
                $bufferLength -= 8;

                $this->logger->debug("Payload length got from 64b [l=" . $this->payloadLength . "]");

            } else {
                $this->logger->debug("Extracting payload length not [yet] possible, skipping mask");

                return false; //Unless payload length is determined it's impossible to try reading mask (fucking RFC...)
            }

            if ($this->payloadLength > DataFrame::MAXIMUM_FRAME_PAYLOAD) {
                throw new WebSocketException("Frame too large (>" . DataFrame::MAXIMUM_FRAME_PAYLOAD . " bytes)",
                    DataFrame::CODE_MESSAGE_TOO_LONG);
            }
        }

        if ($this->secondFrameByte & 128) {
            $this->logger->debug("Frame is masked - trying to collect mask");

            if (empty($this->maskingKey)) { //After collecting payload size it's possible to get masking key (if present and not yet collected)
                if ($bufferLength < 4) {
                    $this->logger->debug("Cannot collect mask - not enough buffer [cbl=$bufferLength]");

                    return false; //Packet is masked, but there's not enough data to get mask, so header cannot be completed [yet]
                }

                $this->maskingKey = substr($this->buffer, 0, 4);
                $this->buffer = substr($this->buffer, 4);
                //Since this it's last operation in a row there's no need to decrement $bufferLength

                $this->logger->debug("Got frame mask");
            }

        }

        $this->logger->debug("Got full header");
        $this->headersCollected = true;

        return true;
    }

    /**
     * Collects payload from buffer. Masked packets are decoded.
     *
     * @return bool|null Returns false if payload not yet collected, true if it was collected. Null is returned if
     *     payload cannot be collected yet
     * @throws LogicException In case of calling before collecting headers
     */
    private function collectPayload()
    {
        if ($this->payloadLength === 0) {
            $this->logger->debug("Skipping payload collection [expected length === 0]");

            return true;
        }

        $this->logger->debug("Trying to collect payload...");
        if (!$this->headersCollected) {
            throw new LogicException("Cannot collect payload before headers!");
        }

        $payloadData = substr($this->buffer, 0, $this->remainingPayloadBytes);
        $payloadDataLen = strlen($payloadData);
        $this->buffer = substr($this->buffer, $payloadDataLen);
        $this->remainingPayloadBytes -= $payloadDataLen;
        $this->payload .= $payloadData;
        $this->logger->debug("Got $payloadDataLen bytes of payload, " . $this->remainingPayloadBytes . " left");

        if ($this->remainingPayloadBytes === 0 && ($this->secondFrameByte & 128)) { //Unmask after full payload is received
            $this->logger->debug("Unmasking payload");
            $this->payload = $this->getMaskedPayload();

            return true;
        }

        return ($this->remainingPayloadBytes === 0);
    }

    /**
     * Returns frame status - whatever its completed (payload can be fetched) or not.
     * If frame is not completed it tries to fetch remaining data.
     *
     * @return bool
     * @throws LogicException
     * @throws WebSocketException
     */
    public function isComplete()
    {
        $this->logger->debug("NetworkFrame::isComplete?");
        if ($this->remainingPayloadBytes === 0 && $this->headersCollected) {
            $this->logger->debug("NetworkFrame::isComplete => OK, buffer load - " . strlen($this->buffer));

            return true;
        }

        $this->logger->debug("NetworkFrame::isComplete => not yet, buffer load - " . strlen($this->buffer));
        if (!empty($this->buffer)) {
            if ($this->headersCollected || $this->collectHeader()) {
                return $this->collectPayload();
            }
        }

        return false;
    }

    /**
     * Returns part of the payload [to save memory]
     *
     * @param integer $start Offset from start
     * @param null|integer $length Number of bytes to return. If null it will return payload from $start to end.
     *
     * @return string
     * @throws UnderflowException Payload is not collected yet
     */
    public function getPayloadPart($start = 0, $length = null)
    {
        $this->logger->debug("Chunking $start-$length from payload [hl=" . $this->payloadLength . ", rl=" . strlen($this->payload) . "]");

        if (!$this->isComplete()) {
            throw new UnderflowException("Cannot get frame payload - frame not ready");
        }

        return ($length === null) ? substr($this->payload, $start) : substr($this->payload, $start, $length);
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnderflowException Payload hasn't been collected yet
     */
    public function getPayload()
    {
        if (!$this->isComplete()) {
            throw new UnderflowException("Cannot get frame payload - frame not ready");
        }

        return $this->payload;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnderflowException Payload hasn't been collected yet
     */
    public function setPayload($payload)
    {
        if (!$this->isComplete()) {
            throw new UnderflowException("Cannot set frame payload - frame not ready");
        }

        parent::setPayload($payload);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        if (!$this->isComplete()) {
            //Due to PHP limitations exception cannot be thrown :(
            $this->logger->critical("Tried to get raw NetworkFrame which isn't completed yet! It will not generate!");

            return '';
        }

        return parent::__toString();
    }
}
