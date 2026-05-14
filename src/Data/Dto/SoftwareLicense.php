<?php

namespace Molaprise\Molasync\Data\Dto;

class SoftwareLicense
{
    public function __construct(
        public readonly string  $authorizationNumber,
        public readonly string  $reOrder,
        public readonly Address $licensee,
        public readonly Contact $licenseeContact,
    ) {}
}
