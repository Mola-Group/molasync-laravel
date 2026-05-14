<?php

namespace Molaprise\Molasync\Models\Etilize;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends BaseEtilizeModel
{
    protected $table = 'productattribute';

    protected $connection = 'etilize';

    public function name(): BelongsTo
    {
        return $this->belongsTo( AttributeName::class, 'attributeid' );
    }
}
