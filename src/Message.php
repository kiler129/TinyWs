<?php
namespace noFlash\TinyWs;

use InvalidArgumentException;
use LogicException;
use OverflowException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Single WebSocket message
 *
 * @package noFlash\tinyWS
 */
final class Message implements RawMessageInterface
{
    const FORMAT_BINARY = DataFrame::OPCODE_BINARY;
    const FORMAT_TEXT   = DataFrame::OPCODE_TEXT;
    //const OPTIMAL_FRAME_SIZE   = 65535;
    const MAXIMUM_MESSAGE_SIZE = 33554432;
    /** @var LoggerInterface */
    protected $logger;
    private   $format = self::FORMAT_TEXT;

    private $payload       = null;
    private $payloadLength = 0;

    private $isComplete;

    //private $rsvBits = [1 => -1, 2 => -1, 3 => -1];

    /**
     * Creates new message - either from first frame or empty one.
     *
     * @param LoggerInterface $logger
     * @param null|DataFrame $frame First frame. If null (default) it will make assumption that message isn't
     *     coming from frames (thus addFrame() method cannot be used)
     *
     * @throws WebSocketException
     */
    public function __construct(LoggerInterface $logger = null, DataFrame $frame = null)
    {
        $this->logger = ($logger === null) ? new NullLogger() : $logger;

        if ($frame === null) {
            $this->isComplete = true;

        } else {
            $this->addFrame($frame);
        }
    }

    /**
     * Adds frame to message. Single message can consist of unlimited number of frames.
     * After finishing frame is added no more frames can be attached.
     * Note: message created without initial frame passed to constructor is considered finished (so unless object was
     * created providing first frame to constructor this method is unusable).
     *
     * @param DataFrame $frame
     *
     * @throws WebSocketException
     * @throws OverflowException In case you try to add new frame to already finished message or message created
     *     without first frame passed to constructor
     * @todo Implement "frames credit" mechanism. Attacker having relatively small amount of resources can perform DoS
     *     attack by sending large message fragmented into very small (eg. 1 byte) frames. Frame credit mechanism
     *     prevents this type of attack by limiting number of frames per message based on it's size from first frame.
     * @todo Implement strict config switch
     */
    public function addFrame(DataFrame $frame)
    {
        if ($this->isComplete) {
            throw new OverflowException("Message cannot accept additional frames");
        }

        if ($this->payload === null) { //First frame
            $this->format = $frame->getOpcode();
            if ($this->format === DataFrame::OPCODE_CONTINUE) {
                throw new WebSocketException("1st frame of msg. cannot be continuation frame",
                    DataFrame::CODE_PROTOCOL_ERROR);
            }

        } elseif ($frame->getOpcode() !== DataFrame::OPCODE_CONTINUE) {
            throw new WebSocketException("Only continuation frames are allowed to be added",
                DataFrame::CODE_PROTOCOL_ERROR);
        }

        $this->isComplete = $frame->isFin();
        $this->payloadLength += $frame->getPayloadLength();
        if ($this->payloadLength > self::MAXIMUM_MESSAGE_SIZE) {
            throw new WebSocketException("Exceeded maximum msg. size of " . self::MAXIMUM_MESSAGE_SIZE . " bytes",
                DataFrame::CODE_MESSAGE_TOO_LONG);
        }

        $this->payload .= $frame->getPayload();

        /* STRICT MODE UTF VERIFICATION - CPU & MEMORY HOG! */
        //TODO strict
        if ($this->isComplete && $this->format === self::FORMAT_TEXT && !mb_check_encoding($this->payload, 'UTF-8')) {
            throw new WebSocketException("Text is not valid UTF-8", DataFrame::CODE_DATA_TYPE_INCONSISTENT);
        }

        $this->logger->debug("Added frame");
    }

    public function __destruct()
    {
        $this->payload = null;
        gc_collect_cycles(); //Messages can be quite large
        $this->logger->debug("Destructed message & gc");
    }

    /**
     * Returns low level message format in form of WebSocket opcode.
     *
     * @return int It will return one of two values: self::FORMAT_BINARY or self::FORMAT_TEXT
     * @see isText()
     * @see isBinary()
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * Low level method making possible to set message format.
     *
     * @param int $format It should be one of two values: self::FORMAT_TEXT or self::FORMAT_BINARY.
     *
     * @throws InvalidArgumentException
     * @deprecated
     * @see formatText()
     * @see formatBinary()
     */
    public function setFormat($format)
    {
        if ($format !== self::FORMAT_BINARY && $format !== self::FORMAT_TEXT) {
            throw new InvalidArgumentException("Invalid message format");
        }

        $this->format = $format;
    }

    /**
     * Checks if message is text.
     *
     * @return bool
     */
    public function isText()
    {
        return ($this->format === self::FORMAT_TEXT);
    }

    /**
     * Check if message is binary.
     *
     * @return bool
     */
    public function isBinary()
    {
        return ($this->format === self::FORMAT_BINARY);
    }

    /**
     * Sets message format to text.
     */
    public function formatText()
    {
        if (!$this->isComplete) {
            throw new LogicException("Cannot change format unless message is completed");
        }

        $this->format = self::FORMAT_TEXT;
    }

    public function formatBinary()
    {
        if (!$this->isComplete) {
            throw new LogicException("Cannot change format unless message is completed");
        }

        $this->format = self::FORMAT_TEXT;
    }

    /**
     * Returns payload. Unless message is completed partial payload can be returned.
     * If you're not sure if message is completed you must call isComplete() before calling getPayload().
     *
     * @return string
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Destroys all frames and sets payload. This method can be used only on completed packets.
     * Note: scalar types will be converted into strings, non-scalar ones will be enclaved directly into JSON
     *
     * @param mixed $payload
     *
     * @throws LogicException Thrown if you try to
     */
    public function setPayload($payload)
    {
        if (!$this->isComplete()) {
            throw new LogicException("Message is not complete - cannot modify it");
        }

        $this->payload = (is_scalar($payload)) ? (string)$payload : json_encode($payload);
        $this->payloadLength = strlen($this->payload);
    }

    /**
     * Denotes if message is complete (all frames has been collected & processed).
     *
     * @return bool
     */
    public function isComplete()
    {
        return $this->isComplete;
    }

    /**
     * Returns raw frames data, suitable to be sent via socket to WebSocket client.
     *
     * @return string
     * @todo Automatic fragmentation based on static::OPTIMAL_FRAME_SIZE
     */
    public function __toString()
    {
        $frame = new DataFrame($this->logger);
        $frame->setOpcode($this->format);
        $frame->setPayload($this->payload);
        $frame->setMasking(false);

        return (string)$frame;
    }

    // -> No extension support yet, so no RSV bits
    //
    //public function getRsvBit($bitNumber) {
    //    //There's no need to check for complete - in messages created from frames RSV bits are set in first frame
    //
    //    if($bitNumber !== 1 && $bitNumber !== 2 && $bitNumber !== 3) //It's better to test like this instead of in_array() or int casting + ranges
    //        throw new \InvalidArgumentException("Bit number should integer from 1 to 3 inclusive.");
    //
    //    return $this->rsvBits[$bitNumber];
    //}
    //
    ///**
    // * @param int $bitNumber Values 0-2
    // * @param bool $bitValue
    // */
    //public function setRsvBit($bitNumber, $bitValue) {
    //    if(!$this->isComplete())
    //        throw new \LogicException("Message is not complete - you cannot modify it while collecting!");
    //
    //    if($bitNumber !== 1 && $bitNumber !== 2 && $bitNumber !== 3) //It's better to test like this instead of in_array() or int casting + ranges
    //        throw new \InvalidArgumentException("Bit number should integer from 1 to 3 inclusive.");
    //
    //    $this->rsvBits[$bitNumber] = (bool)$bitValue;
    //}
}
