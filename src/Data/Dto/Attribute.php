<?php

namespace Molaprise\Molasync\Data\Dto;

class Attribute
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {}
}
