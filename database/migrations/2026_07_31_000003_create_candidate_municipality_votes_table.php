<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gabinetes', function (Blueprint $table) {
            $table->string('numero_eleitoral', 20)
                ->nullable()
                ->after('vereador_nome');
            $table->foreignId('candidato_titular_id')
                ->nullable()
                ->after('municipio_eleitoral_id');
            $table->foreign('candidato_titular_id', 'gabinete_candidato_titular_fk')
                ->references('id')
                ->on('candidatos_politicos')
                ->nullOnDelete();

            $table->index('numero_eleitoral');
        });

        Schema::create('votacoes_candidatos_municipio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_politico_id');
            $table->foreign('candidato_politico_id', 'votacao_candidato_fk')
                ->references('id')
                ->on('candidatos_politicos')
                ->cascadeOnDelete();
            $table->foreignId('municipio_eleitoral_id');
            $table->foreign('municipio_eleitoral_id', 'votacao_municipio_fk')
                ->references('id')
                ->on('municipios_eleitorais')
                ->cascadeOnDelete();
            $table->foreignId('eleicao_id')
                ->nullable()
                ->constrained('eleicoes')
                ->nullOnDelete();
            $table->string('codigo_eleicao_tse', 30);
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('turno');
            $table->date('data_eleicao');
            $table->unsignedBigInteger('votos_nominais');
            $table->unsignedBigInteger('votos_nominais_validos');
            $table->string('situacao_totalizacao', 100)->nullable();
            $table->boolean('eleito')->default(false);
            $table->text('fonte_url');
            $table->timestamp('fonte_gerada_em')->nullable();
            $table->timestamps();

            $table->unique(
                ['candidato_politico_id', 'municipio_eleitoral_id', 'codigo_eleicao_tse', 'turno'],
                'votacao_candidato_municipio_eleicao_turno_unique',
            );
            $table->index(
                ['municipio_eleitoral_id', 'ano', 'eleito'],
                'votacao_municipio_ano_eleito_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votacoes_candidatos_municipio');

        Schema::table('gabinetes', function (Blueprint $table) {
            $table->dropForeign('gabinete_candidato_titular_fk');
            $table->dropIndex(['numero_eleitoral']);
            $table->dropColumn(['numero_eleitoral', 'candidato_titular_id']);
        });
    }
};
