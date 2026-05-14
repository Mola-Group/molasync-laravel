<?php

namespace Molaprise\Molasync\Services\Ziptax;

use Cache;
use Http;
use Log;
use Molaprise\Molasync\Contracts\TaxServiceInterface;
use Throwable;

class TaxService implements TaxServiceInterface
{
    private const DEFAULT_TTL = 60;
    private const SUCCESS_CODE = 100;

    private string $api_key;
    private string $version;
    private string $base_endpoint;
    private int    $ttl;

    public function __construct()
    {
        $this->api_key = (string)config( 'molasync.ziptax.api_key', '' );
        $this->version = strtolower( (string)config( 'molasync.ziptax.version', 'v50' ) );
        $this->base_endpoint = rtrim( (string)config( 'molasync.ziptax.endpoint', 'https://api.zip-tax.com' ), '/' );
        $this->ttl = (int)config( 'molasync.ziptax.ttl', self::DEFAULT_TTL );
    }

    public function by_coordinates(float $lat, float $lng): ?array
    {
        return $this->resolve(
            $this->cache_key( 'coords', "{$this->version}:{$lat},{$lng}" ),
            fn() => $this->fetchWithFallback( [ 'lat' => $lat, 'lng' => $lng ] )
        );
    }

    private function resolve(string $key, callable $fetcher): ?array
    {
        $cached = Cache::get( $key );

        if ( $cached !== null ) return $cached;

        $result = $fetcher();

        if ( $result !== null ) {
            Cache::put( $key, $result, now()->addMinutes( $this->ttl ) );
        }

        return $result;
    }

    private function cache_key(string $type, string $value): string
    {
        return 'ziptax_' . $type . '_' . md5( $value );
    }

    private function fetchWithFallback(array $params): ?array
    {
        $preferredVersion = $this->version === 'v60' ? 'v60' : 'v50';
        $result = $this->fetch( $params, $preferredVersion );
        if ( $result !== null ) return $result;
        if ( $preferredVersion === 'v60' ) return $this->fetch( $params, 'v50' );

        return null;
    }

    private function fetch(array $params, string $version): ?array
    {
        if ( $this->api_key === '' ) {
            Log::warning( 'ZipTax API key is not configured.' );
            return null;
        }

        $endpoint = $this->buildEndpoint( $version );
        $requestParams = $this->buildRequestParams( $params, $version );

        try {
            $response = Http::timeout( 15 )
                ->withHeaders( [
                    'Accept' => 'application/json',
                    'X-API-KEY' => $this->api_key,
                ] )
                ->get( $endpoint, array_merge( [ 'key' => $this->api_key ], $requestParams ) );

            if ( !$response->successful() ) {
                Log::warning( 'ZipTax non-200 response', [
                    'version' => $version,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ] );
                return null;
            }

            return $this->parse( (array)$response->json(), $version );
        } catch ( Throwable $exception ) {
            Log::error( 'ZipTax request failed', [
                'version' => $version,
                'error' => $exception->getMessage(),
            ] );
            return null;
        }
    }

    private function buildEndpoint(string $version): string
    {
        if ( preg_match( '#/request/v\d+$#', $this->base_endpoint ) === 1 ) {
            return preg_replace( '#/request/v\d+$#', "/request/{$version}", $this->base_endpoint ) ?? $this->base_endpoint;
        }

        return "{$this->base_endpoint}/request/{$version}";
    }

    private function buildRequestParams(array $params, string $version): array
    {
        if ( $version === 'v50' ) {
            return array_filter( [
                'format' => 'json',
                'postalcode' => $params[ 'postalcode' ] ?? null,
                'city' => $params[ 'city' ] ?? null,
                'state' => $params[ 'state' ] ?? null,
            ], fn($value) => $value !== null && $value !== '' );
        }

        if ( isset( $params[ 'lat' ], $params[ 'lng' ] ) ) {
            return [
                'address' => $params[ 'address' ] !== '' ? $params[ 'address' ] : $this->implodeAddressParts( $params ),
                'lat' => $params[ 'lat' ],
                'lng' => $params[ 'lng' ],
            ];
        }

        $address = $params[ 'address' ] !== '' ? $params[ 'address' ] : $this->implodeAddressParts( $params );

        if ( $address !== '' ) {
            return [ 'address' => $address ];
        }

        return array_filter( [
            'postalcode' => $params[ 'postalcode' ] ?? null,
            'city' => $params[ 'city' ] ?? null,
            'state' => $params[ 'state' ] ?? null,
        ], fn($value) => $value !== null && $value !== '' );
    }

    private function implodeAddressParts(array $params): string
    {
        return collect( [
            $params[ 'address_1' ] ?? null,
            $params[ 'address_2' ] ?? null,
            $params[ 'city' ] ?? null,
            $params[ 'state' ] ?? null,
            $params[ 'postalcode' ] ?? null,
        ] )
            ->filter( fn($value) => is_string( $value ) && trim( $value ) !== '' )
            ->implode( ', ' );
    }

    private function parse(array $data, string $version): ?array
    {
        if ( $version === 'v60' ) {
            return $this->parseV60( $data );
        }

        return $this->parseV50( $data );
    }

    private function parseV60(array $data): ?array
    {
        $code = (int)( $data[ 'metadata' ][ 'response' ][ 'code' ] ?? 0 );

        if ( $code !== self::SUCCESS_CODE ) {
            Log::warning( 'ZipTax API error', [
                'version' => 'v60',
                'code' => $code,
                'message' => $data[ 'metadata' ][ 'response' ][ 'message' ] ?? 'Unknown error',
            ] );
            return null;
        }

        $sales_rate = null;
        $use_rate = null;

        foreach ( $data[ 'taxSummaries' ] ?? [] as $summary ) {
            $rate = (float)( $summary[ 'rate' ] ?? 0 );

            match ( $summary[ 'taxType' ] ?? null ) {
                'SALES_TAX' => $sales_rate = $rate,
                'USE_TAX' => $use_rate = $rate,
                default => null,
            };
        }

        $total_rate = $sales_rate ?? $use_rate ?? 0.0;
        $breakdown = $this->parseBaseRates( $data[ 'baseRates' ] ?? [] );

        return [
            'provider' => 'ziptax',
            'api_version' => 'v60',
            'total_rate' => $total_rate,
            'total_rate_pct' => round( $total_rate * 100, 4 ),
            'normalized_address' => $data[ 'addressDetail' ][ 'normalizedAddress' ] ?? null,
            'incorporated' => $data[ 'addressDetail' ][ 'incorporated' ] ?? null,
            'geo_lat' => $data[ 'addressDetail' ][ 'geoLat' ] ?? null,
            'geo_lng' => $data[ 'addressDetail' ][ 'geoLng' ] ?? null,
            'freight_taxable' => ( $data[ 'shipping' ][ 'taxable' ] ?? 'N' ) === 'Y',
            'service_taxable' => ( $data[ 'service' ][ 'taxable' ] ?? 'N' ) === 'Y',
            'sourcing' => $data[ 'sourcingRules' ][ 'adjustmentType' ] ?? null,
            'breakdown' => $breakdown,
            'raw' => $data,
        ];
    }

    private function parseBaseRates(array $rates): array
    {
        $state_rate = null;
        $county_rate = null;
        $city_rate = null;
        $district_rate = 0.0;
        $districts = [];

        foreach ( $rates as $rate ) {
            $value = (float)( $rate[ 'rate' ] ?? 0 );

            match ( $rate[ 'jurType' ] ?? '' ) {
                'US_STATE_SALES_TAX' => $state_rate = $value,
                'US_COUNTY_SALES_TAX' => $county_rate = $value,
                'US_CITY_SALES_TAX' => $city_rate = $value,
                'US_DISTRICT_SALES_TAX' => $districts[] = [
                    'name' => $rate[ 'jurName' ] ?? '',
                    'code' => $rate[ 'jurTaxCode' ] ?? null,
                    'rate' => $value,
                ],
                default => null,
            };

            if ( ( $rate[ 'jurType' ] ?? '' ) === 'US_DISTRICT_SALES_TAX' ) {
                $district_rate += $value;
            }
        }

        return [
            'state' => $state_rate,
            'county' => $county_rate,
            'city' => $city_rate,
            'district' => $district_rate > 0 ? $district_rate : null,
            'districts' => $districts,
        ];
    }

    private function parseV50(array $data): ?array
    {
        $code = (int)( $data[ 'rCode' ] ?? $data[ 'responseCode' ] ?? 0 );

        if ( $code !== self::SUCCESS_CODE ) {
            Log::warning( 'ZipTax API error', [
                'version' => 'v50',
                'code' => $code,
                'message' => $data[ 'rMessage' ] ?? $data[ 'responseMessage' ] ?? 'Unknown error',
            ] );
            return null;
        }

        $result = collect( $data[ 'results' ] ?? [] )->first() ?? $data;
        $districts = $this->extractLegacyDistricts( $result );
        $total_rate = $this->normalizeRate( $result[ 'taxSales' ] ?? $result[ 'taxUse' ] ?? $this->extractLegacyRate( $data ) );
        $breakdown = [
            'state' => $this->extractLegacyJurisdictionRate( $result, [ 'stateSalesTax', 'stateSalesTaxRate', 'stateRate', 'state_tax_rate' ] ),
            'county' => $this->extractLegacyJurisdictionRate( $result, [ 'countySalesTax', 'countySalesTaxRate', 'countyRate', 'county_tax_rate' ] ),
            'city' => $this->extractLegacyJurisdictionRate( $result, [ 'citySalesTax', 'citySalesTaxRate', 'cityRate', 'city_tax_rate' ] ),
            'district' => $this->extractLegacyJurisdictionRate( $result, [ 'districtSalesTax', 'districtSalesTaxRate', 'districtRate', 'district_tax_rate' ] ),
            'districts' => $districts,
        ];

        return [
            'provider' => 'ziptax',
            'api_version' => 'v50',
            'total_rate' => $total_rate,
            'total_rate_pct' => round( $total_rate * 100, 4 ),
            'normalized_address' => $data[ 'addressDetail' ][ 'normalizedAddress' ] ?? $data[ 'normalizedAddress' ] ?? $data[ 'address' ] ?? null,
            'incorporated' => $data[ 'addressDetail' ][ 'incorporated' ] ?? $data[ 'incorporated' ] ?? null,
            'geo_lat' => $data[ 'addressDetail' ][ 'geoLat' ] ?? $data[ 'lat' ] ?? $data[ 'geoLat' ] ?? null,
            'geo_lng' => $data[ 'addressDetail' ][ 'geoLng' ] ?? $data[ 'lng' ] ?? $data[ 'geoLng' ] ?? null,
            'freight_taxable' => $this->extractLegacyBoolean( $result, [ 'txbFreight', 'shippingTaxable', 'freightTaxable', 'shipping_taxable' ] ),
            'service_taxable' => $this->extractLegacyBoolean( $result, [ 'txbService', 'serviceTaxable', 'service_taxable' ] ),
            'sourcing' => match ( strtoupper( (string)( $result[ 'originDestination' ] ?? $data[ 'sourcing' ] ?? $data[ 'sourcingRules' ] ?? '' ) ) ) {
                'D' => 'DESTINATION',
                'O' => 'ORIGIN',
                default => $result[ 'originDestination' ] ?? $data[ 'sourcing' ] ?? $data[ 'sourcingRules' ] ?? null,
            },
            'breakdown' => $breakdown,
            'raw' => $data,
        ];
    }

    private function extractLegacyDistricts(array $data): array
    {
        $districts = [];

        foreach ( range( 1, 5 ) as $index ) {
            $code = (string)( $data[ "district{$index}Code" ] ?? '' );
            $rate = $this->normalizeRate( $data[ "district{$index}SalesTax" ] ?? 0 );

            if ( $code === '' && $rate <= 0 ) {
                continue;
            }

            $districts[] = [
                'name' => null,
                'code' => $code !== '' ? $code : null,
                'rate' => $rate,
            ];
        }

        return $districts;
    }

    private function normalizeRate(mixed $value): float
    {
        $rate = (float)$value;

        if ( $rate > 1 ) {
            $rate /= 100;
        }

        return $rate;
    }

    private function extractLegacyRate(array $data): float
    {
        foreach ( [
                      'totalSalesTax',
                      'totalRate',
                      'salesTaxRate',
                      'rate',
                      'taxRate',
                  ] as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                return $this->normalizeRate( $data[ $key ] );
            }
        }

        return 0.0;
    }

    private function extractLegacyJurisdictionRate(array $data, array $keys): ?float
    {
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                return $this->normalizeRate( $data[ $key ] );
            }
        }

        return null;
    }

    private function extractLegacyBoolean(array $data, array $keys): ?bool
    {
        foreach ( $keys as $key ) {
            if ( !array_key_exists( $key, $data ) ) {
                continue;
            }

            $value = $data[ $key ];

            if ( is_bool( $value ) ) {
                return $value;
            }

            if ( is_string( $value ) ) {
                return in_array( strtoupper( $value ), [ 'Y', 'YES', 'TRUE', '1' ], true );
            }

            if ( is_numeric( $value ) ) {
                return (float)$value > 0;
            }
        }

        return null;
    }

    public function by_postal_code(string $postal_code): ?array
    {
        return $this->lookup( [ 'postal_code' => $postal_code ] );
    }

    public function lookup(array $location): ?array
    {
        $normalizedLocation = [
            'address_1' => trim( (string)( $location[ 'address_1' ] ?? '' ) ),
            'address_2' => trim( (string)( $location[ 'address_2' ] ?? '' ) ),
            'address' => trim( (string)( $location[ 'address' ] ?? '' ) ),
            'city' => trim( (string)( $location[ 'city' ] ?? '' ) ),
            'state' => strtoupper( trim( (string)( $location[ 'state' ] ?? '' ) ) ),
            'postalcode' => trim( (string)( $location[ 'postalcode' ] ?? $location[ 'postal_code' ] ?? '' ) ),
            'country' => strtoupper( trim( (string)( $location[ 'country' ] ?? 'US' ) ) ),
        ];

        return $this->resolve(
            $this->cache_key( 'location', "{$this->version}:" . json_encode( $normalizedLocation ) ),
            fn() => $this->fetchWithFallback( $normalizedLocation )
        );
    }

    public function calculate(string $address, float $subtotal): ?float
    {
        $rate = $this->get_rate( $address );

        if ( $rate === null ) {
            return null;
        }

        return round( $subtotal * $rate, 2 );
    }

    public function get_rate(string $address): ?float
    {
        return $this->by_address( $address )[ 'total_rate' ] ?? null;
    }

    public function by_address(string $address): ?array
    {
        return $this->lookup( [ 'address' => $address ] );
    }

    public function get_breakdown(string $address): ?array
    {
        return $this->by_address( $address )[ 'breakdown' ] ?? null;
    }

    public function is_freight_taxable(string $address): ?bool
    {
        return $this->by_address( $address )[ 'freight_taxable' ] ?? null;
    }

    public function bust(string $address): void
    {
        Cache::forget( $this->cache_key( 'addr', "v50:{$address}" ) );
        Cache::forget( $this->cache_key( 'addr', "v60:{$address}" ) );
    }
}
