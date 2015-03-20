<?php
namespace noFlash\TinyWs;


/**
 * Interface represents objects able to print itself as WebSocket complaint data frame(s)
 *
 * @package noFlash\TinyWs
 */
interface RawMessageInterface {
    /**
     * Builds and returns WebSocket complaint set of frames.
     *
     * @return string
     */
    public function __toString();
}
