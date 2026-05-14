<?php

namespace Molaprise\Molasync\Data\Dto;

readonly class POSubmitRequest
{
    /**
     * @param  POLineItem[]  $items
     * @param  string[]      $comments
     * @param  string[]      $shipComments
     */
    public function __construct(
        public string           $customerNumber,
        public string           $poNumber,
        public string           $dropShipFlag,
        public Shipment         $shipment,
        public BillTo           $billTo,
        public array            $items,
        public ?string          $poDateTime                   = null,
        public ?string          $xmlPOSubmitDateTime          = null,
        public ?string          $expectedDate                 = null,
        public ?string          $expectedShipDate             = null,
        public ?string          $specialHandle                = null,
        public ?string          $backOrderFlag                = null,
        public ?string          $backOrderWarehouseSelection  = null,
        public ?string          $shipComplete                 = null,
        public ?string          $poLineShipComplete           = null,
        public ?string          $warehouseSplit               = null,
        public ?int             $shipFromWarehouse            = null,
        public ?string          $specialPriceType             = null,
        public ?string          $specialPriceReferenceNumber  = null,
        public ?string          $synnexB2BAssignedID          = null,
        public ?string          $endUserPONumber              = null,
        public ?string          $tdSynnexQuote                = null,   // v3.1
        public ?EndUser         $endUser                      = null,
        public ?SoftwareLicense $softWareLicense             = null,
        public array            $comments                     = [],
        public array            $shipComments                 = [],
        public array            $shipCommentsInternal         = [],
        public string           $apiVersion                   = '3.1',
    ) {}
}
