<?php

namespace Molaprise\Molasync\Data\Dto;

class BillTo
{
    public function __construct(
        public readonly string  $accountNumber,
        public readonly ?string $addressName1        = null,
        public readonly ?string $addressName2        = null,
        public readonly ?string $addressLine1        = null,
        public readonly ?string $addressLine2        = null,
        public readonly ?string $city                = null,
        public readonly ?string $state               = null,
        public readonly ?string $zipCode             = null,
        public readonly ?string $country             = null,
        public readonly ?int    $synnexLocationNumber = null,
    ) {}
}
