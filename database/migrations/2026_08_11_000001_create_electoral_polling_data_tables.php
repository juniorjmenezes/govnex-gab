<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locais_votacao_eleitorais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleicao_id')
                ->constrained('eleicoes')
                ->cascadeOnDelete();
            $table->foreignId('municipio_eleitoral_id')
                ->constrained('municipios_eleitorais')
                ->cascadeOnDelete();
            $table->string('nr_zona', 10);
            $table->string('nr_local_votacao', 20);
            $table->string('nome');
            $table->string('tipo_local', 100)->nullable();
            $table->string('endereco')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cep', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('latitude_fonte', 20)->nullable();
            $table->timestamp('geocodificado_em')->nullable();
            $table->text('fonte_url');
            $table->timestamp('fonte_gerada_em')->nullable();
            $table->timestamps();

            $table->unique(
                ['eleicao_id', 'municipio_eleitoral_id', 'nr_zona', 'nr_local_votacao'],
                'locais_votacao_eleicao_municipio_zona_local_unique',
            );
        });

        Schema::create('secoes_eleitorais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleicao_id')
                ->constrained('eleicoes')
                ->cascadeOnDelete();
            $table->foreignId('municipio_eleitoral_id')
                ->constrained('municipios_eleitorais')
                ->cascadeOnDelete();
            $table->foreignId('local_votacao_eleitoral_id')
                ->constrained('locais_votacao_eleitorais')
                ->cascadeOnDelete();
            $table->string('nr_zona', 10);
            $table->string('nr_secao', 10);
            $table->unsignedInteger('eleitores_secao')->nullable();
            $table->timestamps();

            $table->unique(
                ['eleicao_id', 'municipio_eleitoral_id', 'nr_zona', 'nr_secao'],
                'secoes_eleicao_municipio_zona_secao_unique',
            );
        });

        Schema::create('votos_secao_candidato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secao_eleitoral_id')
                ->constrained('secoes_eleitorais')
                ->cascadeOnDelete();
            $table->foreignId('candidato_politico_id')
                ->constrained('candidatos_politicos')
                ->cascadeOnDelete();
            $table->unsignedInteger('votos');
            $table->text('fonte_url');
            $table->timestamp('fonte_gerada_em')->nullable();
            $table->timestamps();

            $table->unique(
                ['secao_eleitoral_id', 'candidato_politico_id'],
                'votos_secao_candidato_unique',
            );
            $table->index('candidato_politico_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votos_secao_candidato');
        Schema::dropIfExists('secoes_eleitorais');
        Schema::dropIfExists('locais_votacao_eleitorais');
    }
};
