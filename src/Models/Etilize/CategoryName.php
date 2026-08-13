<?php

namespace Molaprise\Molasync\Models\Etilize;

class CategoryName extends BaseEtilizeModel
{
    protected $table = 'categorynames';

    public $incrementing = false;

    protected $casts = [
        'categoryid' => 'int',
        'localeid' => 'int',
    ];
}
