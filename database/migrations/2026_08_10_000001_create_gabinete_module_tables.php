<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const MODULES = [
        'RELACIONAMENTO',
        'DEMANDAS',
        'ATENDIMENTOS',
        'AGENDA',
        'EVENTOS',
        'POLITICA',
        'RELATORIOS',
        'WHATSAPP',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('gabinete_modulos')) {
            Schema::create('gabinete_modulos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
                $table->string('modulo', 40);
                $table->boolean('ativo')->default(false);
                $table->timestamp('ativado_em')->nullable();
                $table->timestamp('desativado_em')->nullable();
                $table->foreignId('administrador_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['gabinete_id', 'modulo']);
                $table->index(['modulo', 'ativo']);
            });
        }

        if (! Schema::hasTable('gabinete_modulo_eventos')) {
            Schema::create('gabinete_modulo_eventos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
                $table->string('modulo', 40);
                $table->string('acao', 20);
                $table->foreignId('administrador_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('contexto')->nullable();
                $table->timestamp('ocorrido_em');
                $table->index(['gabinete_id', 'ocorrido_em']);
                $table->index(['modulo', 'acao']);
            });
        }

        $now = now();
        DB::table('gabinetes')->select('id')->orderBy('id')->chunkById(100, function ($offices) use ($now): void {
            $rows = [];

            foreach ($offices as $office) {
                foreach (self::MODULES as $module) {
                    $rows[] = [
                        'gabinete_id' => $office->id,
                        'modulo' => $module,
                        'ativo' => true,
                        'ativado_em' => $now,
                        'desativado_em' => null,
                        'administrador_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('gabinete_modulos')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gabinete_modulo_eventos');
        Schema::dropIfExists('gabinete_modulos');
    }
};
