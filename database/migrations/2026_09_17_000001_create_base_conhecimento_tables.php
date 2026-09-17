<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE = 'BASE_CONHECIMENTO';

    public function up(): void
    {
        // gabinete_id nulo = biblioteca da plataforma, visível a todos os
        // gabinetes com o módulo ativo; preenchido = só aquele gabinete.
        Schema::create('conhecimento_documentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->cascadeOnDelete();
            $table->string('titulo', 180);
            $table->text('descricao')->nullable();
            $table->string('disk', 20)->default('local');
            $table->string('caminho');
            $table->string('nome_original');
            $table->unsignedBigInteger('tamanho');
            // Informado pelo leitor na primeira abertura: o servidor não abre PDFs.
            $table->unsignedInteger('total_paginas')->nullable();
            // Pessoas que já leram todas as páginas (cada uma conta uma vez).
            $table->unsignedInteger('leituras_completas')->default(0);
            $table->foreignId('enviado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['gabinete_id', 'created_at']);
        });

        Schema::create('conhecimento_leituras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('documento_id')->constrained('conhecimento_documentos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->json('paginas_lidas');
            $table->unsignedInteger('ultima_pagina')->default(1);
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();
            $table->unique(['documento_id', 'usuario_id']);
        });

        $this->enableForExistingTenants();
    }

    public function down(): void
    {
        Schema::dropIfExists('conhecimento_leituras');
        Schema::dropIfExists('conhecimento_documentos');
        DB::table('gabinete_modulos')->where('modulo', self::MODULE)->delete();
        DB::table('entidade_modulos')->where('modulo', self::MODULE)->delete();
    }

    /**
     * Como na criação dos demais módulos, entidades e gabinetes já existentes
     * recebem o módulo contratado e ativo. Em bancos recriados do zero as
     * linhas já existem (as migrations anteriores leem os enums) e o insert é
     * ignorado.
     */
    private function enableForExistingTenants(): void
    {
        $now = now();

        DB::table('entidades')->select('id')->orderBy('id')->chunkById(100, function ($entidades) use ($now): void {
            DB::table('entidade_modulos')->insertOrIgnore($entidades->map(fn ($entidade): array => [
                'entidade_id' => $entidade->id,
                'modulo' => self::MODULE,
                'escopo' => 'AMBOS',
                'contratado' => true,
                'ativo' => true,
                'ativado_em' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        DB::table('gabinetes')->select('id')->orderBy('id')->chunkById(100, function ($gabinetes) use ($now): void {
            DB::table('gabinete_modulos')->insertOrIgnore($gabinetes->map(fn ($gabinete): array => [
                'gabinete_id' => $gabinete->id,
                'modulo' => self::MODULE,
                'ativo' => true,
                'ativado_em' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }
};
