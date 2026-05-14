<?php

namespace Molaprise\Molasync\Contracts;

interface EtilizeCatalogServiceInterface
{
    public function resolveProductId(array $identifiers): ?int;

    public function resolveProductIdWithDiagnostics(array $identifiers): array;

    public function resolveProductIdByManufacturerPartNumber(?string $manufacturerPartNumber): ?int;

    public function resolveProductIdByUpc(?string $upc): ?int;

    public function similarProductIds(int $productId): array;

    public function searchProductIds(string $query, int $limit = 500): array;
}
