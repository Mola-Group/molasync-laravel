<?php

namespace Molaprise\Molasync\Services\Etilize;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Molaprise\Molasync\Contracts\EtilizeCatalogServiceInterface;
use Molaprise\Molasync\Models\Etilize\ProductSimilar;
use Throwable;

class EtilizeCatalogService implements EtilizeCatalogServiceInterface
{
    private const SAMPLE_MODE_CACHE_KEY = 'molasync.etilize.sample_mode.used_product_ids';
    private const SAMPLE_MODE_RANGE_CACHE_KEY = 'molasync.etilize.sample_mode.product_id_range';

    private array $columnCache = [];

    public function resolveProductId(array $identifiers): ?int
    {
        if ( $this->sampleModeEnabled() ) {
            return $this->resolveSampleModeProductId();
        }

        $manufacturerPartCandidates = $this->identifierCandidates( $identifiers[ 'manufacturer_part_number' ] ?? null );

        foreach ( $manufacturerPartCandidates as $candidate ) {
            $match = $this->resolveProductIdByManufacturerPartNumber( $candidate );
            if ( $match !== null ) return $match;
        }

        $upcCandidates = $this->identifierCandidates( $identifiers[ 'upc' ] ?? null, true );
        foreach ( $upcCandidates as $candidate ) {
            $match = $this->resolveProductIdByUpc( $candidate );
            if ( $match !== null ) return $match;
        }

        foreach ( $manufacturerPartCandidates as $candidate ) {
            $match = $this->resolveProductIdFromSearchAttributes( $candidate );
            if ( $match !== null ) return $match;
        }

        foreach ( $upcCandidates as $candidate ) {
            $match = $this->resolveProductIdFromSearchAttributes( $candidate );
            if ( $match !== null ) return $match;
        }

        return null;
    }

    private function identifierCandidates(?string $value, bool $digitsOnly = false): array
    {
        if ( $value === null ) return [];

        $trimmed = trim( $value );

        if ( $trimmed === '' ) return [];

        $candidates = [ $trimmed ];

        if ( $digitsOnly ) {
            $digits = preg_replace( '/\D+/', '', $trimmed );

            if ( $digits !== null && $digits !== '' ) {
                $candidates[] = $digits;
            }
        } else {
            $upper = strtoupper( $trimmed );
            $alphanumeric = preg_replace( '/[^A-Z0-9]+/', '', $upper );

            $candidates[] = $upper;

            if ( $alphanumeric !== null && $alphanumeric !== '' ) {
                $candidates[] = $alphanumeric;
            }
        }

        return array_values( array_unique( array_filter( $candidates ) ) );
    }

    public function resolveProductIdByUpc(?string $upc): ?int
    {
        $candidates = $this->identifierCandidates( $upc, true );
        if ( $candidates === [] ) {
            return null;
        }

        foreach ( $candidates as $candidate ) {
            $match = $this->resolveFromTableColumns( 'product', 'productid', [ 'upc', 'gtin', 'ean' ], $candidate, true );

            if ( $match !== null ) return $match;

            $match = $this->resolveFromTableColumns(
                'productskus',
                'productid',
                [ 'upc', 'upccode', 'gtin', 'ean', 'ean13', 'barcode' ],
                $candidate,
                false
            );

            if ( $match !== null ) return $match;
        }

        return null;
    }

    private function resolveFromTableColumns(
        string $table,
        string $idColumn,
        array  $candidateColumns,
        string $value,
        bool   $activeProductsOnly = false
    ): ?int
    {
        try {
            if ( !$this->tableExists( $table ) ) {
                return null;
            }

            $availableColumns = $this->getColumnListing( $table );
            $matchColumns = array_values( array_intersect( $availableColumns, array_map( 'strtolower', $candidateColumns ) ) );

            if ( $matchColumns === [] || !in_array( strtolower( $idColumn ), $availableColumns, true ) ) {
                return null;
            }

            $query = DB::connection( 'etilize' )->table( $table );

            if ( $activeProductsOnly && in_array( 'isactive', $availableColumns, true ) ) {
                $query->where( 'isactive', '=', 1 );
            }

            $query->where( function (Builder $builder) use ($matchColumns, $value) {
                foreach ( $matchColumns as $index => $column ) {
                    if ( $index === 0 ) {
                        $builder->where( $column, '=', $value );
                    } else {
                        $builder->orWhere( $column, '=', $value );
                    }
                }
            } );

            $matches = $query
                ->limit( 5 )
                ->pluck( $idColumn )
                ->map( fn($id) => (int)$id )
                ->filter()
                ->unique()
                ->values();

            return $matches->count() === 1 ? $matches->first() : null;
        } catch ( Throwable ) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::connection( 'etilize' )->hasTable( $table );
        } catch ( Throwable ) {
            return false;
        }
    }

    private function getColumnListing(string $table): array
    {
        if ( isset( $this->columnCache[ $table ] ) ) {
            return $this->columnCache[ $table ];
        }

        try {
            $this->columnCache[ $table ] = collect( Schema::connection( 'etilize' )->getColumnListing( $table ) )
                ->map( fn(string $column) => strtolower( $column ) )
                ->values()
                ->all();
        } catch ( Throwable ) {
            $this->columnCache[ $table ] = [];
        }

        return $this->columnCache[ $table ];
    }

    public function resolveProductIdByManufacturerPartNumber(?string $manufacturerPartNumber): ?int
    {
        $candidates = $this->identifierCandidates( $manufacturerPartNumber );

        if ( $candidates === [] ) return null;

        foreach ( $candidates as $candidate ) {
            $match = $this->resolveFromTableColumns( 'product', 'productid', [ 'mfgpartno' ], $candidate, true );

            if ( $match !== null ) return $match;

            $match = $this->resolveFromTableColumns(
                'productskus',
                'productid',
                [ 'sku', 'vendorpartno', 'vendorpartnumber', 'partno', 'mfgpartno', 'manufactpartno', 'manufacturerpartnumber' ],
                $candidate,
                false
            );

            if ( $match !== null ) return $match;
        }

        return null;
    }

    private function resolveProductIdFromSearchAttributes(string $value): ?int
    {
        try {
            if ( !$this->tableExists( 'search_attribute' ) || !$this->tableExists( 'search_attribute_values' ) ) return null;

            $matches = DB::connection( 'etilize' )->table( 'search_attribute' )
                ->join( 'search_attribute_values', 'search_attribute_values.valueid', '=', 'search_attribute.valueid' )
                ->join( 'product', 'search_attribute.productid', '=', 'product.productid' )
                ->where( 'search_attribute_values.value', '=', $value )
                ->where( 'product.isactive', '=', 1 )
                ->limit( 5 )
                ->pluck( 'product.productid' )
                ->map( fn($productId) => (int)$productId )
                ->unique()
                ->values();

            return $matches->count() === 1 ? $matches->first() : null;
        } catch ( Throwable ) {
            return null;
        }
    }

    private function sampleModeEnabled(): bool
    {
        return (bool)config( 'molasync.etilize.sample_mode', false );
    }

    private function resolveSampleModeProductId(): ?int
    {
        try {
            if ( !$this->tableExists( 'product' ) ) return null;

            $availableColumns = $this->getColumnListing( 'product' );

            if ( !in_array( 'productid', $availableColumns, true ) ) return null;

            $usedProductIds = collect( Cache::get( self::SAMPLE_MODE_CACHE_KEY, [] ) )
                ->map( fn($id) => (int)$id )
                ->filter()
                ->unique()
                ->values();

            $productId = $this->randomActiveProductIdExcluding( $usedProductIds->all(), $availableColumns );

            if ( $productId === null && $usedProductIds->isNotEmpty() ) {
                $usedProductIds = collect();
                $productId = $this->randomActiveProductIdExcluding( [], $availableColumns );
            }

            if ( $productId === null ) return null;

            Cache::forever(
                self::SAMPLE_MODE_CACHE_KEY,
                $usedProductIds
                    ->push( $productId )
                    ->unique()
                    ->values()
                    ->all()
            );

            return $productId;
        } catch ( Throwable ) {
            return null;
        }
    }

    private function randomActiveProductIdExcluding(array $excludedProductIds, array $availableColumns): ?int
    {
        $range = $this->sampleModeProductIdRange( $availableColumns );

        if ( $range === null ) {
            return null;
        }

        [ $minProductId, $maxProductId ] = $range;
        $randomStart = random_int( $minProductId, $maxProductId );

        $productId = $this->firstActiveProductIdAtOrAfter( $randomStart, $excludedProductIds, $availableColumns );

        if ( $productId !== null ) {
            return $productId;
        }

        return $this->firstActiveProductIdAtOrAfter( $minProductId, $excludedProductIds, $availableColumns, $randomStart );
    }

    private function sampleModeProductIdRange(array $availableColumns): ?array
    {
        $cachedRange = Cache::get( self::SAMPLE_MODE_RANGE_CACHE_KEY );

        if ( is_array( $cachedRange ) && count( $cachedRange ) === 2 ) {
            return [ (int)$cachedRange[ 0 ], (int)$cachedRange[ 1 ] ];
        }

        $query = DB::connection( 'etilize' )->table( 'product' );

        if ( in_array( 'isactive', $availableColumns, true ) ) {
            $query->where( 'isactive', '=', 1 );
        }

        $range = $query
            ->selectRaw( 'MIN(productid) as min_product_id, MAX(productid) as max_product_id' )
            ->first();

        $minProductId = isset( $range->min_product_id ) ? (int)$range->min_product_id : null;
        $maxProductId = isset( $range->max_product_id ) ? (int)$range->max_product_id : null;

        if ( !$minProductId || !$maxProductId ) {
            return null;
        }

        Cache::forever( self::SAMPLE_MODE_RANGE_CACHE_KEY, [ $minProductId, $maxProductId ] );

        return [ $minProductId, $maxProductId ];
    }

    private function firstActiveProductIdAtOrAfter(
        int $startProductId,
        array $excludedProductIds,
        array $availableColumns,
        ?int $beforeProductId = null
    ): ?int
    {
        $query = DB::connection( 'etilize' )->table( 'product' )
            ->where( 'productid', '>=', $startProductId );

        if ( $beforeProductId !== null ) {
            $query->where( 'productid', '<', $beforeProductId );
        }

        if ( in_array( 'isactive', $availableColumns, true ) ) {
            $query->where( 'isactive', '=', 1 );
        }

        if ( $excludedProductIds !== [] ) {
            $query->whereNotIn( 'productid', $excludedProductIds );
        }

        $productId = $query
            ->orderBy( 'productid' )
            ->value( 'productid' );

        return $productId !== null ? (int)$productId : null;
    }

    public function similarProductIds(int $productId): array
    {
        try {
            [ $sourceColumn, $relatedColumn ] = $this->resolveProductSimilarColumns();

            if ( $sourceColumn === null || $relatedColumn === null ) return [];


            return ProductSimilar::query()
                ->where( $sourceColumn, $productId )
                ->pluck( $relatedColumn )
                ->merge(
                    ProductSimilar::query()
                        ->where( $relatedColumn, $productId )
                        ->pluck( $sourceColumn )
                )
                ->filter()
                ->map( fn($value) => (int)$value )
                ->unique()
                ->values()
                ->all();
        } catch ( Throwable ) {
            return [];
        }
    }

    private function resolveProductSimilarColumns(): array
    {
        $columns = collect( Schema::connection( 'etilize' )->getColumnListing( 'productsimilar' ) )
            ->map( fn(string $column) => strtolower( $column ) )
            ->values();

        $sourceColumn = $this->firstMatchingColumn( $columns, [
            'productid',
            'product_id',
            'masterproductid',
            'sourceproductid',
        ] );

        $relatedColumn = $this->firstMatchingColumn( $columns, [
            'similarproductid',
            'similar_product_id',
            'relatedproductid',
            'related_product_id',
            'targetproductid',
        ] );

        if ( $sourceColumn !== null && $relatedColumn !== null ) {
            return [ $sourceColumn, $relatedColumn ];
        }

        $productIdColumns = $columns
            ->filter( fn(string $column) => str_contains( $column, 'product' ) && str_contains( $column, 'id' ) )
            ->values();

        if ( $productIdColumns->count() >= 2 ) {
            return [ $productIdColumns[ 0 ], $productIdColumns[ 1 ] ];
        }

        return [ null, null ];
    }

    private function firstMatchingColumn(Collection $columns, array $candidates): ?string
    {
        foreach ( $candidates as $candidate ) {
            if ( $columns->contains( $candidate ) ) return $candidate;
        }

        return null;
    }

    public function searchProductIds(string $query, int $limit = 500): array
    {
        $term = trim( $query );
        if ( $term === '' ) return [];

        return \DB::connection( 'etilize' )->table( 'search_attribute' )
            ->join( 'search_attribute_values', 'search_attribute_values.valueid', '=', 'search_attribute.valueid' )
            ->join( 'product', 'search_attribute.productid', '=', 'product.productid' )
            ->where( 'search_attribute_values.value', 'like', "%{$term}%" )
            ->where( 'product.isactive', '=', 1 )
            ->limit( $limit )
            ->pluck( 'product.productid' )
            ->map( fn($value) => (int)$value )
            ->values()
            ->all();
    }
}
