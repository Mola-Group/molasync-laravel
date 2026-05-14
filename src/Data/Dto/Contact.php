<?php

namespace Molaprise\Molasync\Data\Dto;

class Contact
{
    public function __construct(
        public readonly ?string $contactName  = null,
        public readonly ?string $phoneNumber  = null,
        public readonly ?string $faxNumber    = null,
        public readonly ?string $emailAddress = null,
    ) {}
}
