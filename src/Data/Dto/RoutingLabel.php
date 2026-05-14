<?php

namespace Molaprise\Molasync\Data\Dto;

class RoutingLabel
{
    public function __construct(
        public readonly ?string $printerModel = null,
        public readonly ?string $serialNo     = null,
        public readonly ?string $endUserName  = null,
        public readonly ?string $attn         = null,
        public readonly ?string $location     = null,
        public readonly ?string $comment1     = null,
        public readonly ?string $comment2     = null,
    ) {}
}
