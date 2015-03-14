<?php
namespace noFlash\TinyWs;

use Exception;
use Psr\Log\InvalidArgumentException;

/**
 * Please be warned that currently provided closing code is NOT verified. You're responsible for providing RFC-complaint
 * closing code.
 * For list of valid codes see NetworkFrame class (CODE_* constants)
 *
 * @package noFlash\TinyWS
 * @see WebSocketFrame
 */
class WebSocketException extends Exception
{
    public function __construct($message = "", $code = DataFrame::CODE_POLICY_VIOLATION)
    {
        if (!DataFrame::validateCloseCode($code)) {
            throw new InvalidArgumentException("Invalid WebSocketException code", 0, $this);
        }

        parent::__construct($message, $code);
    }
}
