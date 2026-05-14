<?php

namespace Molaprise\Molasync\Data\Dto;

class POSubmitResponse
{
    /** @param POResponseLineItem[] $items */
    public function __construct(
        public readonly string  $customerNumber,
        public readonly string  $poNumber,
        public readonly string  $code,
        public readonly string  $responseDateTime,
        public readonly string  $responseElapsedTime,
        public readonly array   $items,
        public readonly ?string $reason        = null,
        public readonly ?string $errorMessage  = null,
        public readonly ?string $errorDetail   = null,
    ) {}
}
