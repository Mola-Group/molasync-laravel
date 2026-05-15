<?php

namespace Molaprise\Molasync\Services\Etilize;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Molaprise\Molasync\Contracts\EtilizeCatalogServiceInterface;
use Molaprise\Molasync\Models\Etilize\Product;
use Molaprise\Molasync\Models\Etilize\ProductSimilar;
use Molaprise\Molasync\Models\Etilize\ProductSku;
use Molaprise\Molasync\Models\Etilize\SearchAttribute;
use Throwable;

class EtilizeCatalogService implements EtilizeCatalogServiceInterface
{
    private const SAMPLE_MODE_CACHE_KEY = 'molasync.etilize.sample_mode.used_product_ids';
    private const SAMPLE_MODE_RANGE_CACHE_KEY = 'molasync.etilize.sample_mode.product_id_range';

    private array $columnCache = [];

    public function resolveProductId(array $identifiers): ?int
    {
        return $this->resolveProductIdWithDiagnostics( $identifiers )[ 'product_id' ] ?? null;
    }

    public function resolveProductIdWithDiagnostics(array $identifiers): array
    {
//        if ( $this->sampleModeEnabled() ) {
//            return [
//                'product_id' => $this->resolveSampleModeProductId(),
//                'status' => 'sample_mode',
//                'source' => 'sample_mode',
//                'candidate' => null,
//                'match_count' => null,
//                'match_ids' => [],
//            ];
//        }

        $manufacturerPartCandidates = $this->identifierCandidates( $identifiers[ 'manufacturer_part_number' ] ?? null );

        foreach ( $manufacturerPartCandidates as $candidate ) {
            $diagnostic = $this->resolveProductIdByManufacturerPartNumberWithDiagnostics( $candidate );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }
        }

        $upcCandidates = $this->identifierCandidates( $identifiers[ 'upc' ] ?? null, true );

        foreach ( $upcCandidates as $candidate ) {
            $diagnostic = $this->resolveProductIdByUpcWithDiagnostics( $candidate );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }
        }

        foreach ( $manufacturerPartCandidates as $candidate ) {
            $diagnostic = $this->resolveProductIdFromSearchAttributesWithDiagnostics( $candidate );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }
        }

        foreach ( $upcCandidates as $candidate ) {
            $diagnostic = $this->resolveProductIdFromSearchAttributesWithDiagnostics( $candidate );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }
        }

        return [
            'product_id' => null,
            'status' => 'not_found',
            'source' => 'resolver',
            'candidate' => $manufacturerPartCandidates[ 0 ] ?? $upcCandidates[ 0 ] ?? null,
            'match_count' => 0,
            'match_ids' => [],
        ];
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
        return $this->resolveProductIdByUpcWithDiagnostics( $upc )[ 'product_id' ] ?? null;
    }

    private function resolveProductIdByUpcWithDiagnostics(?string $upc): array
    {
        $candidates = $this->identifierCandidates( $upc, true );

        if ( $candidates === [] ) {
            return $this->emptyDiagnostic( 'upc', null );
        }

        foreach ( $candidates as $candidate ) {
            $diagnostic = $this->resolveFromModelColumns(
                Product::class,
                'product',
                'productid',
                [ 'upc', 'gtin', 'ean' ],
                $candidate,
                true,
                'product.upc'
            );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }

            $diagnostic = $this->resolveFromModelColumns(
                ProductSku::class,
                'productskus',
                'productid',
                [ 'upc', 'upccode', 'gtin', 'ean', 'ean13', 'barcode' ],
                $candidate,
                false,
                'productskus.upc'
            );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }
        }

        return $this->emptyDiagnostic( 'upc', $candidates[ 0 ] ?? null );
    }

    private function resolveFromModelColumns(
        string $modelClass,
        string $table,
        string $idColumn,
        array $candidateColumns,
        string $value,
        bool $activeProductsOnly = false,
        ?string $source = null
    ): array
    {
        try {
            if ( !$this->tableExists( $table ) ) {
                return $this->emptyDiagnostic( $source ?? $table, $value, 'table_missing' );
            }

            $availableColumns = $this->getColumnListing( $table );
            $matchColumns = array_values( array_intersect( $availableColumns, array_map( 'strtolower', $candidateColumns ) ) );

            if ( $matchColumns === [] || !in_array( strtolower( $idColumn ), $availableColumns, true ) ) {
                return $this->emptyDiagnostic( $source ?? $table, $value, 'columns_missing' );
            }

            /** @var Model $modelClass */
            $query = $modelClass::query();

            if ( $activeProductsOnly && in_array( 'isactive', $availableColumns, true ) ) {
                $query->where( 'isactive', '=', 1 );
            }

            $query = $query->where( function (Builder $builder) use ($matchColumns, $value) {
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

            return match ( true ) {
                $matches->count() === 1 => [
                    'product_id' => $matches->first(),
                    'status' => 'matched',
                    'source' => $source ?? $table,
                    'candidate' => $value,
                    'match_count' => 1,
                    'match_ids' => $matches->all(),
                ],
                $matches->count() > 1 => [
                    'product_id' => null,
                    'status' => 'ambiguous',
                    'source' => $source ?? $table,
                    'candidate' => $value,
                    'match_count' => $matches->count(),
                    'match_ids' => $matches->all(),
                ],
                default => $this->emptyDiagnostic( $source ?? $table, $value ),
            };
        } catch ( Throwable ) {
            return $this->emptyDiagnostic( $source ?? $table, $value, 'query_error' );
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
        return $this->resolveProductIdByManufacturerPartNumberWithDiagnostics( $manufacturerPartNumber )[ 'product_id' ] ?? null;
    }

    private function resolveProductIdByManufacturerPartNumberWithDiagnostics(?string $manufacturerPartNumber): array
    {
        $candidates = $this->identifierCandidates( $manufacturerPartNumber );

        if ( $candidates === [] ) return $this->emptyDiagnostic( 'mpn', null );

        foreach ( $candidates as $candidate ) {
            $diagnostic = $this->resolveFromModelColumns(
                Product::class,
                'product',
                'productid',
                [ 'mfgpartno' ],
                $candidate,
                true,
                'product.mfgpartno'
            );

            if ( $diagnostic[ 'product_id' ] !== null || $diagnostic[ 'status' ] === 'ambiguous' ) {
                return $diagnostic;
            }
        }

        return $this->emptyDiagnostic( 'mpn', $candidates[ 0 ] ?? null );
    }

    private function resolveProductIdFromSearchAttributes(string $value): ?int
    {
        return $this->resolveProductIdFromSearchAttributesWithDiagnostics( $value )[ 'product_id' ] ?? null;
    }

    private function resolveProductIdFromSearchAttributesWithDiagnostics(string $value): array
    {
        try {
            if ( !$this->tableExists( 'search_attribute' ) || !$this->tableExists( 'search_attribute_values' ) ) {
                return $this->emptyDiagnostic( 'search_attribute', $value, 'table_missing' );
            }

            $matches = SearchAttribute::query()
                ->join( 'search_attribute_values', 'search_attribute_values.valueid', '=', 'search_attribute.valueid' )
                ->join( 'product', 'search_attribute.productid', '=', 'product.productid' )
                ->where( 'search_attribute_values.value', '=', $value )
                ->where( 'product.isactive', '=', 1 )
                ->limit( 5 )
                ->pluck( 'product.productid' )
                ->map( fn($productId) => (int)$productId )
                ->unique()
                ->values();

            return match ( true ) {
                $matches->count() === 1 => [
                    'product_id' => $matches->first(),
                    'status' => 'matched',
                    'source' => 'search_attribute',
                    'candidate' => $value,
                    'match_count' => 1,
                    'match_ids' => $matches->all(),
                ],
                $matches->count() > 1 => [
                    'product_id' => null,
                    'status' => 'ambiguous',
                    'source' => 'search_attribute',
                    'candidate' => $value,
                    'match_count' => $matches->count(),
                    'match_ids' => $matches->all(),
                ],
                default => $this->emptyDiagnostic( 'search_attribute', $value ),
            };
        } catch ( Throwable ) {
            return $this->emptyDiagnostic( 'search_attribute', $value, 'query_error' );
        }
    }

    private function emptyDiagnostic(string $source, ?string $candidate, string $status = 'not_found'): array
    {
        return [
            'product_id' => null,
            'status' => $status,
            'source' => $source,
            'candidate' => $candidate,
            'match_count' => 0,
            'match_ids' => [],
        ];
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

        $query = Product::query();

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
        $query = Product::query()
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

        return SearchAttribute::query()
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
