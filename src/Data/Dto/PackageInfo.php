<?php

namespace Molaprise\Molasync\Data\Dto;

class PackageInfo
{
    /** @param string[] $serialNumbers */
    public function __construct(
        public readonly string  $trackingNumber,
        public readonly float   $weight,
        public readonly int     $shipItemQuantity,
        public readonly array   $serialNumbers             = [],
        public readonly ?string $imei                      = null,   // v2.9
        public readonly ?string $macAddress                = null,   // v2.9
        public readonly ?string $carrierDeliveredDate      = null,   // v2.6
        public readonly ?string $refusedDeliveryDate       = null,   // v2.6
    ) {}
}
