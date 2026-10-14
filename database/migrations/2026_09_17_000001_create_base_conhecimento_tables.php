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
        // Visibilidade em três níveis (`HasTieredVisibility`): gabinete_id
        // preenchido = só aquele gabinete; gabinete_id nulo e entidade_id
        // preenchido = todos os gabinetes da entidade (publicado pela
        // Secretaria legislativa); ambos nulos = biblioteca da plataforma.
        // Documento de gabinete também guarda a entidade dele, só para
        // consistência e auditoria — isso não amplia a visibilidade.
        Schema::create('conhecimento_documentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('entidade_id')->nullable()->constrained('entidades')->cascadeOnDelete();
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
        $this->backfillDocumentEntidade();
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

    /**
     * Documento de gabinete sem `entidade_id` recebe a entidade do gabinete
     * dono. Não muda a visibilidade: `gabinete_id` continua preenchido, então
     * ele segue privado daquele gabinete. Logo após o `create` a tabela está
     * vazia e nada acontece; o método é público para reprocessar bases locais
     * que já tinham documentos e para o teste conferir a regra. Idempotente.
     */
    public function backfillDocumentEntidade(): void
    {
        DB::table('conhecimento_documentos')
            ->whereNotNull('gabinete_id')
            ->whereNull('entidade_id')
            ->update([
                'entidade_id' => DB::table('gabinetes')
                    ->select('entidade_id')
                    ->whereColumn('gabinetes.id', 'conhecimento_documentos.gabinete_id'),
            ]);
    }
};
