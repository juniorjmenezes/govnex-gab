<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $gabinete_id
 */
abstract class TenantModel extends Model
{
    use BelongsToOffice;

    /** @var list<string> */
    protected $guarded = ['gabinete_id'];
}
