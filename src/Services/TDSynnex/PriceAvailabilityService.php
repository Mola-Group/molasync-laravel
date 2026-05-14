<?php

namespace Molaprise\Molasync\Services\TDSynnex;


use Cache;
use Http;
use Log;
use Molaprise\Molasync\Contracts\PriceAvailabilityServiceInterface;
use Throwable;

class PriceAvailabilityService implements PriceAvailabilityServiceInterface
{
    private const VERSION = '2.8';
    private const TTL = 1; // minutes
    private const CACHE_PA = 'tds_pa_';      // price + availability
    private const CACHE_AV = 'tds_avail_';   // availability only

    private string $base_url;
    private string $account;
    private string $username;
    private string $password;

    public function __construct()
    {
        $env = config( 'molasync.tdsynnex.environment', 'test' );
        $this->account = config( 'molasync.tdsynnex.account' );
        $this->username = config( 'molasync.tdsynnex.email' );
        $this->password = config( 'molasync.tdsynnex.password' );
        $this->base_url = config( "molasync.tdsynnex.{$env}.base_url" );
    }

    // ── Interface implementation ─────────────────────────────────

    public function is_available(string $sku): bool
    {
        return $this->availability_single( $sku )[ 'is_available' ] ?? false;
    }

    public function availability_single(string $sku): array
    {
        return $this->availability( [ $sku ] )[ $sku ] ?? $this->not_found_result( $sku );
    }

    public function availability(array $skus): array
    {
        return $this->resolve( $skus, self::CACHE_AV, 'Availability' );
    }

    private function resolve(array $skus, string $cache_prefix, string $path): array
    {
        $results = [];
        $to_fetch = [];

        foreach ( $skus as $sku ) {
            $cached = Cache::get( $cache_prefix . md5( $sku ) );
            if ( $cached !== null ) {
                $results[ $sku ] = $cached;
            } else {
                $to_fetch[] = $sku;
            }
        }

        if ( !empty( $to_fetch ) ) {
            $fresh = $this->fetch( $to_fetch, $path );
            foreach ( $fresh as $sku => $data ) {
                Cache::put( $cache_prefix . md5( $sku ), $data, now()->addMinutes( self::TTL ) );
                $results[ $sku ] = $data;
            }
        }

        return $results;
    }

    private function fetch(array $skus, string $path): array
    {
        try {
            $endpoint = rtrim( $this->base_url, '/' ) . '/SynnexXML/' . ltrim( $path, '/' );
            $response = Http::withBody($this->build_xml( $skus ), 'text/xml; charset=UTF-8' )
                ->timeout( 15 )
                ->post( $endpoint );

            if ( !$response->successful() ) {
                Log::warning( 'TD Synnex P&A non-200', [
                    'url' => $endpoint,
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ] );
                return [];
            }

            return $this->parse( $response->body() );

        } catch ( Throwable $e ) {
            Log::error( 'TD Synnex P&A fetch failed', [
                'url' => $endpoint ?? null,
                'path' => $path,
                'error' => $e->getMessage(),
            ] );
            return [];
        }
    }

    private function build_xml(array $skus): string
    {
        $sku_list = '';
        foreach ( $skus as $index => $sku ) {
            $line = $index + 1;
            $sku_clean = htmlspecialchars( (string)$sku, ENT_XML1 );
            $sku_list .= <<<XML
<skuList>
    <synnexSKU>{$sku_clean}</synnexSKU>
    <lineNumber>{$line}</lineNumber>
</skuList>
XML;
        }

        $version = self::VERSION;
        $account = htmlspecialchars( $this->account, ENT_XML1 );
        $username = htmlspecialchars( $this->username, ENT_XML1 );
        $password = htmlspecialchars( $this->password, ENT_XML1 );

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<priceRequest version="{$version}">
    <customerNo>{$account}</customerNo>
    <userName>{$username}</userName>
    <password>{$password}</password>
    {$sku_list}
</priceRequest>
XML;
    }

    private function parse(string $body): array
    {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );

        if ( !$xml ) {
            Log::warning( 'TD Synnex P&A parse failed', [ 'body' => $body ] );
            return [];
        }

        $results = [];

        foreach ( $xml->PriceAvailabilityList as $item ) {
            $sku = (string)$item->synnexSKU;
            $status = strtolower( trim( (string)$item->status ) );

            $normalised = match ( $status ) {
                'active' => 'active',
                'discontinued' => 'discontinued',
                'notauthorized', 'not authorized' => 'not_authorized',
                'not found', 'notfound' => 'not_found',
                default => 'unknown',
            };

            $warehouses = [];
            foreach ( $item->AvailabilityByWarehouse as $wh ) {
                $warehouses[] = [
                    'number' => (string)$wh->warehouseInfo->number,
                    'zipcode' => (string)$wh->warehouseInfo->zipcode,
                    'city' => (string)$wh->warehouseInfo->city,
                    'address' => (string)$wh->warehouseInfo->addr,
                    'qty' => (int)$wh->qty,
                    'on_order' => (int)( $wh->onOrderQuantity ?? 0 ),
                    'eta' => $wh->estimatedArrivalDate
                        ? (string)$wh->estimatedArrivalDate
                        : null,
                ];
            }

            $total_qty = (int)$item->totalQuantity;

            $results[ $sku ] = [
                'sku' => $sku,
                'mfg_part' => (string)$item->mfgPN,
                'status' => $normalised,
                'description' => (string)$item->description,
                'price' => $item->price ? (float)$item->price : null,
                'msrp' => $item->msrp ? (float)$item->msrp : null,
                'weight' => $item->weight ? (float)$item->weight : null,
                'total_qty' => $total_qty,
                'eu_required' => ( (string)$item->EURequired ) === 'Y',
                'is_available' => $normalised === 'active' && $total_qty > 0,
                'warehouses' => $warehouses,
            ];
        }

        return $results;
    }

    private function not_found_result(string $sku): array
    {
        return [
            'sku' => $sku,
            'status' => 'not_found',
            'is_available' => false,
            'total_qty' => 0,
            'price' => null,
            'msrp' => null,
            'weight' => null,
            'warehouses' => [],
        ];
    }

    public function is_discontinued(string $sku): bool
    {
        return $this->availability_single( $sku )[ 'status' ] === 'discontinued';
    }

    public function get_price(string $sku): ?float
    {
        return $this->query_single( $sku )[ 'price' ] ?? null;
    }

    public function query_single(string $sku): array
    {
        return $this->query( [ $sku ] )[ $sku ] ?? $this->not_found_result( $sku );
    }

    public function query(array $skus): array
    {
        return $this->resolve( $skus, self::CACHE_PA, 'PriceAvailability' );
    }

    public function get_msrp(string $sku): ?float
    {
        return $this->query_single( $sku )[ 'msrp' ] ?? null;
    }

    // ── Core resolver — shared by query() and availability() ─────

    public function get_total_qty(string $sku): int
    {
        return $this->availability_single( $sku )[ 'total_qty' ] ?? 0;
    }

    // ── HTTP transport ───────────────────────────────────────────

    public function nearest_warehouse_with_stock(string $sku, string $zip): ?array
    {
        $warehouses = collect( $this->get_warehouses( $sku ) )
            ->filter( fn($wh) => $wh[ 'qty' ] > 0 );

        if ( $warehouses->isEmpty() ) return null;

        // Score by zip proximity using numeric distance as a rough estimate
        return $warehouses
            ->sortBy( fn($wh) => abs( (int)$wh[ 'zipcode' ] - (int)$zip ) )
            ->first();
    }

    // ── XML builder ──────────────────────────────────────────────

    public function get_warehouses(string $sku): array
    {
        return $this->query_single( $sku )[ 'warehouses' ] ?? [];
    }

    // ── Response parser ──────────────────────────────────────────

    public function bust_many(array $skus): void
    {
        foreach ( $skus as $sku ) {
            $this->bust( $sku );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function bust(string $sku): void
    {
        Cache::forget( self::CACHE_PA . md5( $sku ) );
        Cache::forget( self::CACHE_AV . md5( $sku ) );
    }
}
