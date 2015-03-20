<?php
namespace noFlash\TinyWs;


use InvalidArgumentException;
use OverflowException;
use Psr\Log\LoggerInterface;

/**
 * Represents single WebSocket frame created manually.
 * In opposite to NetworkFrame this object doesn't contain any frame data collection mechanisms.
 *
 * @package noFlash\TinyWs
 */
class DataFrame implements RawMessageInterface
{
    const MAXIMUM_FRAME_PAYLOAD = 9223372036854775808;
    //const MAXIMUM_FRAME_PAYLOAD = 524288; //512K is sensible amount for single frame

    const CODE_CLOSE_NORMAL           = 1000;
    const CODE_GOING_AWAY             = 1001;
    const CODE_PROTOCOL_ERROR         = 1002;
    const CODE_INVALID_DATA_TYPE      = 1003;
    const CODE_NO_STATUS              = 1005;
    const CODE_ABNORMAL               = 1006;
    const CODE_DATA_TYPE_INCONSISTENT = 1007; //Unlikely, but RFC limits frame length to 63 bits (who proposed & accepted this?!)

    const CODE_POLICY_VIOLATION     = 1008;
    const CODE_MESSAGE_TOO_LONG     = 1009;
    const CODE_MANDATORY_EXTENSION  = 1010;
    const CODE_UNEXPECTED_CONDITION = 1011;
    const CODE_TLS_ERROR            = 1015;

    const OPCODE_CONTINUE = 0;
    const OPCODE_TEXT     = 1;
    const OPCODE_BINARY   = 2;
    const OPCODE_CLOSE    = 8;
    const OPCODE_PING     = 9;
    const OPCODE_PONG     = 10;

    /** @var LoggerInterface */
    protected $logger;
    /** @var int|null 8-bit integer holding FIN[1b] | RSV1[1b] | RSV2[1b] | RSV3[1b] | OPCODE[4b] */
    protected $firstFrameByte = null; //Continuation frame on multipart frameset
    /** @var int|null 8-bit integer holding MASK[1b] | FIRST PAYLOAD LEN[7b] */
    protected $secondFrameByte = null;
    /** @var null|int See page 28 of RFC 6455 ("Payload length" section), this value contains value from 2nd byte of frame */
    protected $payloadLengthDiscriminator = null;
    /** @var null|int Real payload length in bytes */
    protected $payloadLength = null;
    protected $maskingKey    = null;
    /** @var string Payload contents. Note: this variable holds unmasked data until all data are collected! */
    protected $payload;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->logger->debug("Creating DataFrame");
        $this->firstFrameByte = 129; //Default: text frame, no fragmentation (0b10000001)
        $this->secondFrameByte = 0; //No masking, payload length unknown for now
        $this->headersCollected = true;
    }

    /**
     * Validates close code against rules specified by RFC 6455/7.4
     *
     * @param int $code Close code to check
     *
     * @return bool Returns true if provided close code / opcode is valid, false otherwise
     */
    public static function validateCloseCode($code)
    {
        return !($code < 1000 || //Not used
            $code > 5000 || //Invalid
            ($code > 1015 && $code < 3000) || //Range 1000-2999 is reserved for protocol, but currently there are no codes above 1015
            $code === 1004 || //Reserved
            $code === self::CODE_NO_STATUS || //Reserved (internal app usage)
            $code === self::CODE_ABNORMAL || //Reserved (internal app usage)
            ($code > 1011 && $code <= 1015) //Not defined by RFC, but from reserved range (exc. 1015 is reserved for app int. use)
        );
    }

    /**
     * Denotes whatever this frame is last frame from fragmented frameset
     *
     * @return bool
     */
    public function isFin()
    {
        return (bool)($this->firstFrameByte & 128);
    }

    /**
     * Sets fin-bit
     *
     * @see isFin
     *
     * @param bool $value
     */
    public function setFin($value)
    {
        if ($value) {
            $this->firstFrameByte |= 128;
        } else {
            $this->firstFrameByte &= ~128;
        }
    }

    /**
     * Returns opcode for current frame
     *
     * @return int
     */
    public function getOpcode()
    {
        // 240 => 128[fin] | 64[rsv1] | 32[rsv2] | 16[rsv3]
        // So basically removes fin+rsv bits leaving opcode only
        $this->logger->debug("getOpcode() called -> " . decbin($this->firstFrameByte & ~240));

        return $this->firstFrameByte & ~240;
    }

    //No extensions support yet (so no RSV bits either)
    /*
    public function getRsv1() {
        return (bool)($this->firstFrameByte & 64);
    }

    public function getRsv2() {
        return (bool)($this->firstFrameByte & 32);
    }

    public function getRsv3() {
        return (bool)($this->firstFrameByte & 16);
    }*/

    /**
     * Sets packet type (named "opcode").
     *
     * @param int $opcode Valid WS opcode.
     *
     * @throws InvalidArgumentException Invalid opcode specified
     */
    public function setOpcode($opcode)
    {
        if (!self::validateOpcode($opcode)) {
            throw new InvalidArgumentException("Invalid opcode");
        }

        $this->firstFrameByte = $this->firstFrameByte & 240;
        $this->firstFrameByte |= $opcode;
    }

    /**
     * Validates opcode
     *
     * @param $opcode
     *
     * @return bool
     */
    public static function validateOpcode($opcode)
    {
        switch ($opcode) { //Looks better than if & it's faster than in_array()
            case self::OPCODE_CONTINUE:
            case self::OPCODE_TEXT:
            case self::OPCODE_BINARY:
            case self::OPCODE_CLOSE:
            case self::OPCODE_PING:
            case self::OPCODE_PONG:
                return true;

            default:
                return false;
        }
    }

    /**
     * Denotes if frame is masked.
     * Note: client=>server frames MUST be masked, server=>client frame SHOULD NOT
     *
     * @return bool
     */
    public function isMasked()
    {
        return (bool)($this->secondFrameByte & 128);
    }

    /**
     * Enables/disabled masking on current frame
     * Note: this method require openssl support for strong rng. If you wish to disable openssl rng and use weaker
     * implementation (STRONGLY not advised, see RFC 6455/5.3) alternative solution is provided (see code).
     *
     * @see isMasked()
     *
     * @param bool $value
     */
    public function setMasking($value)
    {
        if ($value) {
            //$this->maskingKey = pack("N", mt_rand(1, 4294967296)); //Use this instead if you don't have access to openssl (it's not recommended!)
            $this->maskingKey = openssl_random_pseudo_bytes(4);
            $this->secondFrameByte |= 128;

        } else {
            $this->maskingKey = null;
            $this->secondFrameByte &= ~128;
        }
    }

    /**
     * Returns whole payload
     *
     * @return string
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Set payload & calculate needed values for packet.
     * Note: payload will be converted into string, but conversion is binary-safe
     *
     * @param mixed $payload
     */
    public function setPayload($payload)
    {
        $this->payload = (string)$payload;
        $this->payloadLength = strlen($payload);
        if ($this->payloadLength > static::MAXIMUM_FRAME_PAYLOAD) {
            throw new OverflowException('Payload too long - it must be smaller or equal ' . static::MAXIMUM_FRAME_PAYLOAD . ' bytes');
        }

        if ($this->payloadLength < 126) {
            $this->logger->debug("setPayload encode as 7bit [l=" . $this->payloadLength . "]");
            $this->payloadLengthDiscriminator = $this->payloadLength;

        } elseif ($this->payloadLength <= 65535) {
            $this->logger->debug("setPayload encode as 16bit [l=" . $this->payloadLength . "]");
            $this->payloadLengthDiscriminator = 126;

        } else {
            $this->logger->debug("setPayload encode as 63bit [l=" . $this->payloadLength . "]");
            $this->payloadLengthDiscriminator = 127;
        }

        $this->secondFrameByte = $this->payloadLengthDiscriminator | $this->secondFrameByte & ~127; //Is there a easier way?
    }

    /**
     * Provides data/payload length contained in frame
     * Note: this function will return payload length from headers, even if frame is not completed yet.
     *
     * @return integer Number of bytes
     */
    public function getPayloadLength()
    {
        return $this->payloadLength;
    }

    /**
     * Returns raw frame data, suitable to be sent via socket to WebSocket client.
     *
     * @return string Complete packet ready to push into socket
     */
    public function __toString()
    {
        if (!($this->secondFrameByte & 128)) { //Not masked packet
            //That code snippet was VERY useful debugging packets errors, so leave it in case of emer^Crandom bugs
            /*$this->logger->debug("Strigifing non-masked frame, dumping payload");
            foreach(unpack("C*", $this->payload) as $char) {
                echo dechex($char)."\t";
            }
            echo "\n";*/

            return $this->generateHeader() . $this->payload;
        }

        return $this->generateHeader() . $this->getMaskedPayload();
    }

    /**
     * Generates raw frame headers.
     *
     * @return string Frame header
     */
    private function generateHeader()
    {
        $this->logger->debug("Generating raw frame header");

        $this->logger->debug("Generated frame 1st byte: " . decbin($this->firstFrameByte) . ", 2nd: " . decbin($this->secondFrameByte));
        $header = chr($this->firstFrameByte) . chr($this->secondFrameByte);

        //Payloads with len. of 7 bits are already handled by secondFrameByte
        if ($this->payloadLengthDiscriminator === 126) {
            $this->logger->debug("Encoding length as 16bit");
            $header .= pack("n", $this->payloadLength);

        } elseif ($this->payloadLengthDiscriminator === 127) {
            $this->logger->debug("Encoding length as 64bit");
            //64 bit support in pack()/unpack() was introduced in 5.6.3, which is too young to require it
            //This method also allows proper 64-bit packets handling on 32-bits systems
            $highMap = 0xffffffff00000000;
            $lowMap = 0x00000000ffffffff;
            $higher = ($this->payloadLength & $highMap) >> 32;
            $lower = $this->payloadLength & $lowMap;
            $header .= pack('NN', $higher, $lower);

        } else {
            $this->logger->debug("Length encoded as 7bit");
        }

        if ($this->secondFrameByte & 128) { //Packet is masked, so attach masking key
            $this->logger->debug("Attaching masking key");
            $header .= ord($this->maskingKey);
        }

        return $header;
    }

    /**
     * Provides masked payload
     * Note: If payload is already masked this method will return unmasked payload.
     *
     * @return string Masked payload
     */
    protected function getMaskedPayload()
    {
        $this->logger->debug("Fetching payload with pre-masking");

        $maskedPayload = '';
        for ($i = 0; $i < $this->payloadLength; $i++) {
            $maskedPayload .= $this->payload[$i] ^ $this->maskingKey[$i % 4];
        }

        return $maskedPayload;
    }
}
