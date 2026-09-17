<?php

namespace Molaprise\Molasync\Contracts;

interface EtilizeCatalogServiceInterface
{
    /**
     * @param array $identifiers
     * @return int|null
     */
    public function resolveProductId(array $identifiers): ?int;

    /**
     * @param array $identifiers
     * @return array
     */
    public function resolveProductIdWithDiagnostics(array $identifiers): array;

    /**
     * @param string|null $manufacturerPartNumber
     * @return int|null
     */
    public function resolveProductIdByManufacturerPartNumber(?string $manufacturerPartNumber): ?int;

    /**
     * @param string|null $upc
     * @return int|null
     */
    public function resolveProductIdByUpc(?string $upc): ?int;

    /**
     * @param int $productId
     * @return array
     */
    public function similarProductIds(int $productId): array;

    /**
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function searchProductIds(string $query, int $limit = 500): array;
}
