<?php

namespace Molaprise\Molasync\Contracts;

interface TaxServiceInterface
{
    /**
     * Look up tax using structured location data.
     */
    public function lookup(array $location): ?array;

    /**
     * Get tax rate by full street address — door-level accuracy
     * Adjusts for unincorporated areas and special tax jurisdictions
     *
     * @param  string  $address  Full street address e.g. "200 Spectrum Center Drive, Irvine, CA 92618"
     * @return array|null
     */
    public function by_address(string $address): ?array;

    /**
     * Get tax rate by latitude and longitude — door-level accuracy
     * Adjusts for unincorporated areas and special tax jurisdictions
     */
    public function by_coordinates(float $lat, float $lng): ?array;

    /**
     * Get tax rates by postal code
     * Returns multiple rates for all applicable jurisdictions overlapping the postal code
     * NOTE: Less accurate than address or coordinates — no adjustment for unincorporated areas
     */
    public function by_postal_code(string $postal_code): ?array;

    /**
     * Get just the total sales tax rate for an address as a decimal
     * e.g. 0.0775 for 7.75%
     */
    public function get_rate(string $address): ?float;

    /**
     * Calculate the tax amount for a given subtotal at a given address
     */
    public function calculate(string $address, float $subtotal): ?float;

    /**
     * Get a breakdown of rates by jurisdiction for a given address
     * Returns state, county, city and district rates individually
     */
    public function get_breakdown(string $address): ?array;

    /**
     * Check if freight/shipping is taxable at a given address
     */
    public function is_freight_taxable(string $address): ?bool;

    /**
     * Bust the cache for a given address
     */
    public function bust(string $address): void;
}
