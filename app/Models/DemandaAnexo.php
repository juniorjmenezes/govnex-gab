<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $demanda_id
 * @property int|null $demanda_evento_id
 * @property int|null $usuario_id
 * @property string $disk
 * @property string $caminho
 * @property string $nome_original
 * @property string $nome_armazenado
 * @property string $mime_type
 * @property string $extensao
 * @property int $tamanho
 * @property bool $imagem
 * @property Carbon|null $created_at
 */
#[Hidden(['disk', 'caminho', 'nome_armazenado', 'deleted_at'])]
class DemandaAnexo extends TenantModel
{
    use SoftDeletes;

    protected $table = 'demanda_anexos';

    /** @return BelongsTo<Demanda, $this> */
    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class);
    }

    /** @return BelongsTo<DemandaEvento, $this> */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(DemandaEvento::class, 'demanda_evento_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'imagem' => 'boolean',
            'tamanho' => 'integer',
        ];
    }
}
