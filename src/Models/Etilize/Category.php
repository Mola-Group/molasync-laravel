<?php

namespace Molaprise\Molasync\Models\Etilize;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends BaseEtilizeModel
{
    protected $table = 'category';

    protected $primaryKey = 'categoryid';

    public $incrementing = false;

    protected $casts = [
        'categoryid' => 'int',
        'parentcategoryid' => 'int',
        'catlevel' => 'int',
        'isactive' => 'int',
        'ordernumber' => 'int',
    ];

    public function names(): HasMany
    {
        return $this->hasMany( CategoryName::class, 'categoryid', 'categoryid' );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo( self::class, 'parentcategoryid', 'categoryid' );
    }

    public function scopeActive( $query )
    {
        return $query->where( 'isactive', 1 );
    }

    public function resolvedName(): string
    {
        $names = $this->relationLoaded( 'names' ) ? $this->names : collect();

        if ( $names->isNotEmpty() ) {
            $default = $names
                ->sortBy( fn(CategoryName $name): int => (int) ( $name->getAttribute( 'localeid' ) ?? 0 ) === 1 ? 0 : 1 )
                ->first();

            $resolved = trim( (string) ( $default?->getAttribute( 'name' ) ?? '' ) );

            if ( $resolved !== '' ) {
                return $resolved;
            }
        }

        return trim( (string) ( $this->getAttribute( 'name' ) ?? '' ) );
    }

    public function resolvedParentCategoryId(): int
    {
        return (int) ( $this->getAttribute( 'parentcategoryid' ) ?: 0 );
    }

    public function resolvedLevel(): int
    {
        return (int) ( $this->getAttribute( 'catlevel' ) ?: 0 );
    }
}
