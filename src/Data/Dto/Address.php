<?php

namespace Molaprise\Molasync\Data\Dto;

class Address
{
    public function __construct(
        public readonly string  $addressName1,
        public readonly string  $addressLine1,
        public readonly string  $city,
        public readonly string  $state,
        public readonly string  $zipCode,
        public readonly string  $country = 'US',
        public readonly ?string $addressName2 = null,
        public readonly ?string $addressLine2 = null,
    )
    {
    }
}
