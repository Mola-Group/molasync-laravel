<?php

namespace Molaprise\Molasync\Contracts;

interface PriceAvailabilityServiceInterface
{
    /**
     * Query price and availability for a single SKU
     */
    public function query_single(string $sku): array;

    /**
     * Query price and availability for multiple SKUs — batched and cached
     *
     * @param  array  $skus  TD Synnex SKU numbers
     * @return array  Keyed by SKU
     */
    public function query(array $skus): array;

    /**
     * Query availability only for a single SKU — no price, faster response
     * Uses the /Availability endpoint instead of /PriceAvailability
     */
    public function availability_single(string $sku): array;

    /**
     * Query availability only for multiple SKUs — batched and cached
     * Uses the /Availability endpoint instead of /PriceAvailability
     */
    public function availability(array $skus): array;

    /**
     * Check if a single SKU is available to sell
     */
    public function is_available(string $sku): bool;

    /**
     * Check if a single SKU is discontinued
     */
    public function is_discontinued(string $sku): bool;

    /**
     * Get the current reseller price for a single SKU
     * Returns null if unavailable or not found
     */
    public function get_price(string $sku): ?float;

    /**
     * Get the MSRP for a single SKU
     * Returns null if unavailable or not found
     */
    public function get_msrp(string $sku): ?float;

    /**
     * Get total available quantity across all warehouses for a single SKU
     */
    public function get_total_qty(string $sku): int;

    /**
     * Get availability broken down per warehouse for a single SKU
     */
    public function get_warehouses(string $sku): array;

    /**
     * Get the nearest warehouse with stock for a single SKU based on a zip code
     */
    public function nearest_warehouse_with_stock(string $sku, string $zip): ?array;

    /**
     * Bust the cache for a single SKU — both price and availability caches
     */
    public function bust(string $sku): void;

    /**
     * Bust the cache for multiple SKUs at once
     */
    public function bust_many(array $skus): void;
}
