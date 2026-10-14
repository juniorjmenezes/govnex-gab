<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasTieredVisibility;
use Illuminate\Database\Eloquent\Model;

/**
 * Model de teste para `HasTieredVisibility`, que ainda não tem consumidor real.
 * A tabela é criada pelo próprio teste.
 *
 * @property int $id
 * @property string $titulo
 */
class TieredVisibilityRecord extends Model
{
    use HasTieredVisibility;

    public const TABLE = 'tiered_visibility_records';

    protected $table = self::TABLE;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gabinete_id' => 'integer',
            'entidade_id' => 'integer',
        ];
    }
}
