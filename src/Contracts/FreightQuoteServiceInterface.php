<?php

namespace Molaprise\Molasync\Contracts;

interface FreightQuoteServiceInterface
{
    /**
     * Get a freight quote using full shipping address (v2.0)
     * Returns all available ship methods when ship_method is empty
     *
     * @param array $ship_to [ 'name1', 'name2', 'address1', 'city', 'state', 'zip', 'country', 'address_type' ]
     * @param array $items [ [ 'sku', 'mfg_part', 'description', 'quantity' ] ]
     * @param int $warehouse TD Synnex warehouse number
     * @param string $ship_method Leave empty to return all available methods
     * @param int|null $service_level 0=same day, 1=next day, 2=2 days etc. Null = return all
     * @return array|null
     */
    public function quote_by_address(
        array  $ship_to,
        array  $items,
        int    $warehouse = 3,
        string $ship_method = '',
        ?int   $service_level = null
    ): ?array;

    /**
     * Get all available ship methods for a given address and items
     * Convenience wrapper — equivalent to leaving ShipMethodCode and ServiceLevel empty
     */
    public function all_methods(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): ?array;

    /**
     * Get the cheapest available ship method for a given address
     */
    public function cheapest_method(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): ?array;

    /**
     * Get the fastest available ship method for a given address
     */
    public function fastest_method(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): ?array;

    /**
     * Check if free freight threshold is met for given items
     */
    public function is_free_freight(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): bool;
}
