<?php

namespace Molaprise\Molasync\Data\Dto;

class StatusLineItem
{
    /** @param PackageInfo[] $packages */
    public function __construct(
        public readonly int     $lineNumber,
        public readonly string  $code,
        public readonly string  $orderNumber,
        public readonly string  $orderType,
        public readonly int     $orderQuantity,
        public readonly float   $unitPrice,
        public readonly string  $sku,
        public readonly string  $mfgPN,
        public readonly string  $productName,
        public readonly int     $shipQuantity,
        public readonly array   $packages                   = [],
        public readonly ?string $shipDatetime               = null,
        public readonly ?string $shipFromWarehouse          = null,
        public readonly ?string $shipFromCity               = null,
        public readonly ?string $shipFromState              = null,
        public readonly ?string $shipFromZip                = null,
        public readonly ?string $shipMethod                 = null,
        public readonly ?string $shipMethodDescription      = null,
        public readonly ?string $etaDate                    = null,
        public readonly ?float  $freight                    = null,
        public readonly ?float  $handlingFee                = null,
        public readonly ?float  $tax                        = null,
        public readonly ?float  $recyclingFee               = null,
        public readonly ?string $vendorOrderNumber          = null,   // v2.5
        public readonly ?string $estimatedDeliveryDate      = null,   // v2.6
        public readonly ?string $estimatedShipDate          = null,   // v2.7
        public readonly ?string $estimatedShipDateCode      = null,   // v2.7
        public readonly ?string $custPOLineNo               = null,   // v3.0
    ) {}
}
