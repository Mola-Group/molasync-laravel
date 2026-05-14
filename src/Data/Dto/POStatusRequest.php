<?php

namespace Molaprise\Molasync\Data\Dto;

class POStatusRequest
{
    public function __construct(
        public readonly string $customerNumber,
        public readonly string $poNumber,
        public readonly ?string $vendorOrderNumber = null,
        public readonly string $apiVersion = '2.5',
    ) {}
}
