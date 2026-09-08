<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demanda_protocol_sequences', function (Blueprint $table) {
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->unsignedBigInteger('proximo_numero')->default(1);
            $table->timestamps();
            $table->primary(['gabinete_id', 'ano']);
        });

        Schema::create('demandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->string('protocolo', 40);
            $table->foreignId('cidadao_id')->constrained('cidadaos')->restrictOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('bairro_id')->nullable()->constrained('bairros')->nullOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('criado_por_id')->constrained('users')->restrictOnDelete();
            $table->string('titulo');
            $table->text('descricao');
            $table->string('endereco')->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento')->nullable();
            $table->string('ponto_referencia')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->char('estado', 2)->nullable();
            $table->string('municipio', 120)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('prioridade', 20)->default('normal');
            $table->string('status', 30)->default('nova');
            $table->string('origem', 30);
            $table->string('resultado', 40)->nullable();
            $table->timestamp('aberta_em')->useCurrent();
            $table->timestamp('prazo')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamp('ultima_atividade_em')->nullable();
            $table->string('proxima_acao_descricao')->nullable();
            $table->date('proxima_acao_data')->nullable();
            $table->foreignId('proxima_acao_responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proxima_acao_concluida_em')->nullable();
            $table->timestamp('favoritada_em')->nullable();
            $table->foreignId('favoritada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gabinete_id', 'protocolo']);
            $table->index(['gabinete_id', 'status', 'created_at']);
            $table->index(['gabinete_id', 'prioridade', 'prazo']);
            $table->index(['gabinete_id', 'responsavel_id', 'status']);
            $table->index(['gabinete_id', 'categoria_id', 'status']);
            $table->index(['gabinete_id', 'bairro_id', 'status']);
            $table->index(['gabinete_id', 'cidadao_id', 'created_at']);
            $table->index(['gabinete_id', 'proxima_acao_responsavel_id', 'proxima_acao_data'], 'demandas_proxima_acao_responsavel_idx');
            $table->index(['gabinete_id', 'proxima_acao_data', 'proxima_acao_concluida_em'], 'demandas_proxima_acao_pendente_idx');
            $table->index(['gabinete_id', 'favoritada_em'], 'demandas_favoritada_idx');
        });

        Schema::create('demanda_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('demanda_id')->constrained('demandas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 40);
            $table->text('descricao')->nullable();
            $table->json('dados')->nullable();
            $table->string('destino')->nullable();
            $table->string('setor')->nullable();
            $table->string('referencia_externa', 100)->nullable();
            $table->date('prazo_esperado')->nullable();
            $table->timestamp('retorno_recebido_em')->nullable();
            $table->foreignId('retorno_de_evento_id')->nullable()->constrained('demanda_eventos')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gabinete_id', 'demanda_id', 'created_at']);
            $table->index(['gabinete_id', 'demanda_id', 'tipo']);
            $table->index(['gabinete_id', 'tipo', 'prazo_esperado', 'retorno_recebido_em'], 'demanda_eventos_pendentes_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demanda_eventos');
        Schema::dropIfExists('demandas');
        Schema::dropIfExists('demanda_protocol_sequences');
    }
};
