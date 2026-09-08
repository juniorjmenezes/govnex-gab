<?php

namespace App\Models;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $gabinete_id
 * @property int|null $solicitado_por_id
 * @property ReportExportFormat $formato
 * @property array<string, mixed> $filtros
 * @property ReportExportStatus $status
 * @property string $disk
 * @property string|null $caminho
 * @property string|null $nome_arquivo
 * @property string|null $mime_type
 * @property int|null $tamanho
 * @property string|null $erro
 * @property Carbon|null $iniciado_em
 * @property Carbon|null $concluido_em
 * @property Carbon|null $expira_em
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportExport extends TenantModel
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'relatorio_exportacoes';

    protected $keyType = 'string';

    /** @return BelongsTo<User, $this> */
    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_id');
    }

    protected function casts(): array
    {
        return [
            'formato' => ReportExportFormat::class,
            'filtros' => 'array',
            'status' => ReportExportStatus::class,
            'tamanho' => 'integer',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'expira_em' => 'datetime',
        ];
    }
}
