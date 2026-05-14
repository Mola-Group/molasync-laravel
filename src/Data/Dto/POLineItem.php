<?php

namespace Molaprise\Molasync\Data\Dto;

class POLineItem
{
    /**
     * @param  Attribute[]    $attributes   Cisco/Apple vendor-specific key-value pairs (v3.0)
     * @param  string[]       $comments
     */
    public function __construct(
        public readonly int             $lineNumber,
        public readonly float           $unitPrice,
        public readonly int             $orderQuantity,
        public readonly ?string         $sku                          = null,
        public readonly ?string         $manufacturerPartNumber       = null,
        public readonly ?string         $productName                  = null,
        public readonly ?int            $shipFromWarehouse            = null,
        public readonly ?string         $specialPriceReferenceNumber  = null,
        public readonly ?string         $giftComment                  = null,   // v3.0
        public readonly ?string         $custPOLineNo                 = null,   // v3.1
        public readonly ?RoutingLabel   $routingLabel                 = null,   // v2.5
        public readonly array           $attributes                   = [],     // v3.0
        public readonly array           $comments                     = [],
    ) {}
}
