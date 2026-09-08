<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesquisas_eleitorais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleicao_id')->constrained('eleicoes')->cascadeOnDelete();
            $table->uuid('external_id')->unique();
            $table->uuid('external_election_id');
            $table->string('registro_tse', 30)->nullable()->index();
            $table->unsignedSmallInteger('ano');
            $table->char('uf', 2);
            $table->string('municipio', 120)->nullable();
            $table->string('cargo', 30);
            $table->unsignedTinyInteger('turno')->default(1);
            $table->string('cenario', 30)->default('estimulado_1t');
            $table->unsignedInteger('cenario_id')->nullable();
            $table->text('cenario_nome')->nullable();
            $table->string('instituto', 150)->nullable();
            $table->date('publicada_em');
            $table->date('coleta_inicio_em')->nullable();
            $table->date('coleta_fim_em')->nullable();
            $table->unsignedInteger('tamanho_amostra')->nullable();
            $table->decimal('margem_erro', 5, 2)->nullable();
            $table->decimal('nivel_confianca', 5, 2)->nullable();
            $table->string('metodologia', 30)->nullable();
            $table->string('abrangencia', 80)->nullable();
            $table->string('tipo', 50)->nullable();
            $table->text('fonte_url');
            $table->timestamp('fonte_atualizada_em')->nullable();
            $table->unsignedTinyInteger('confianca')->nullable();
            $table->string('origem_provider', 60)->nullable();
            $table->timestamps();

            $table->index(['eleicao_id', 'uf', 'cargo', 'publicada_em'], 'pesquisas_eleicao_uf_cargo_data_index');
            $table->index(['external_election_id', 'publicada_em']);
            $table->index(
                ['eleicao_id', 'uf', 'municipio', 'cargo', 'publicada_em'],
                'pesquisas_eleicao_local_cargo_data_index',
            );
        });

        Schema::create('resultados_pesquisas_eleitorais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesquisa_eleitoral_id')
                ->constrained('pesquisas_eleitorais')
                ->cascadeOnDelete();
            $table->uuid('external_candidate_id');
            $table->foreignId('candidato_politico_id')
                ->nullable()
                ->constrained('candidatos_politicos')
                ->nullOnDelete();
            $table->string('candidato_nome');
            $table->string('partido_sigla', 30)->nullable();
            $table->decimal('percentual', 6, 2);
            $table->boolean('nao_valido')->default(false);
            $table->timestamps();

            $table->unique(
                ['pesquisa_eleitoral_id', 'external_candidate_id'],
                'resultados_pesquisa_candidato_unique',
            );
            $table->index('candidato_politico_id');
        });

        Schema::create('medias_pesquisas_eleitorais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleicao_id')->constrained('eleicoes')->cascadeOnDelete();
            $table->uuid('external_id')->unique();
            $table->uuid('external_election_id');
            $table->uuid('external_candidate_id');
            $table->foreignId('candidato_politico_id')
                ->nullable()
                ->constrained('candidatos_politicos')
                ->nullOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->char('uf', 2);
            $table->string('municipio', 120)->nullable();
            $table->string('cargo', 30);
            $table->string('candidato_nome');
            $table->string('partido_sigla', 30)->nullable();
            $table->decimal('media_ponderada', 6, 2);
            $table->decimal('intervalo_confianca_min', 6, 2)->nullable();
            $table->decimal('intervalo_confianca_max', 6, 2)->nullable();
            $table->unsignedInteger('pesquisas_incluidas')->default(0);
            $table->unsignedBigInteger('amostra_total')->default(0);
            $table->timestamp('calculada_em')->nullable();
            $table->text('fonte_url');
            $table->timestamps();

            $table->unique(
                ['external_election_id', 'external_candidate_id'],
                'medias_eleicao_candidato_unique',
            );
            $table->index(['eleicao_id', 'uf', 'cargo'], 'medias_eleicao_uf_cargo_index');
            $table->index(['eleicao_id', 'uf', 'municipio', 'cargo'], 'medias_eleicao_local_cargo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medias_pesquisas_eleitorais');
        Schema::dropIfExists('resultados_pesquisas_eleitorais');
        Schema::dropIfExists('pesquisas_eleitorais');
    }
};
