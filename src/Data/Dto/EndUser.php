<?php

namespace Molaprise\Molasync\Data\Dto;

class EndUser
{
    public function __construct(
        public readonly ?string  $synnexAccountNumber   = null,
        public readonly ?string  $endUserType           = null,
        public readonly ?string  $addressName1          = null,
        public readonly ?string  $addressLine1          = null,
        public readonly ?string  $city                  = null,
        public readonly ?string  $state                 = null,
        public readonly ?string  $zipCode               = null,
        public readonly ?string  $country               = null,
        public readonly ?Contact $endUserContact        = null,
        public readonly ?string  $endUserPODate         = null,
        public readonly ?float   $endUserShipExpense    = null,
        public readonly ?string  $contractCode          = null,
        public readonly ?string  $contractFeeCode       = null,
        public readonly ?float   $contractFee           = null,
        public readonly ?string  $contractDeliveryDate  = null,
    ) {}
}
