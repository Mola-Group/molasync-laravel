<?php

namespace Molaprise\Molasync\Models\Etilize;

use Illuminate\Database\Eloquent\Model;

abstract class BaseEtilizeModel extends Model
{
    protected $guarded = [];

    protected $connection = 'etilize';

    public $timestamps = false;
}
