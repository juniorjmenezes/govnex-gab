<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGabinete;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $gabinete_id
 */
abstract class TenantModel extends Model
{
    use BelongsToGabinete;

    /** @var list<string> */
    protected $guarded = ['gabinete_id'];
}
