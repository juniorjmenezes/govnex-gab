<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE = 'SECRETARIA_LEGISLATIVA';

    private const CITY_COUNCIL = 'CAMARA_MUNICIPAL';

    /**
     * Só backfill: o módulo não tem tabela própria. Câmaras já existentes
     * recebem a Secretaria legislativa contratada, mas desligada — é recurso
     * novo e opcional, não comportamento que elas já tinham (por isso não segue
     * o `ativo => true` do backfill dos demais módulos). Prefeituras e
     * gabinetes independentes não recebem a linha. Em bancos recriados do zero
     * não há entidades ainda e o insert não faz nada; as novas nascem pelo
     * `EntityEntitlementService`, com a mesma regra.
     *
     * Nenhum gabinete recebe o módulo aqui: só o gabinete do tipo Secretaria
     * legislativa pode ativá-lo, e nenhum existe antes desta versão.
     */
    public function up(): void
    {
        $now = now();

        DB::table('entidades')
            ->where('tipo', self::CITY_COUNCIL)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($entidades) use ($now): void {
                DB::table('entidade_modulos')->insertOrIgnore($entidades->map(fn ($entidade): array => [
                    'entidade_id' => $entidade->id,
                    'modulo' => self::MODULE,
                    'escopo' => 'AMBOS',
                    'contratado' => true,
                    'ativo' => false,
                    'ativado_em' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        DB::table('gabinete_modulos')->where('modulo', self::MODULE)->delete();
        DB::table('entidade_modulos')->where('modulo', self::MODULE)->delete();
    }
};
