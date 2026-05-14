<?php

namespace Molaprise\Molasync\Data\Dto;

class Shipment
{
    public function __construct(
        public readonly Address $address,
        public readonly ?Contact $contact = null,
        public readonly ?ShipMethod $shipMethod = null,
        public readonly ?string $shipToCode = null,
        public readonly ?string $deliveryOption = null,
    ) {}
}
