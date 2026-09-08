<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comparecimentos_eleitorais_municipio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipio_eleitoral_id');
            $table->foreign('municipio_eleitoral_id', 'comparecimento_municipio_fk')
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
            $table->unsignedBigInteger('eleitores_aptos');
            $table->unsignedBigInteger('comparecimento');
            $table->unsignedBigInteger('abstencoes');
            $table->text('fonte_url');
            $table->timestamp('fonte_gerada_em')->nullable();
            $table->timestamps();

            $table->unique(
                ['municipio_eleitoral_id', 'codigo_eleicao_tse', 'turno'],
                'comparecimento_municipio_eleicao_turno_unique',
            );
            $table->index(
                ['municipio_eleitoral_id', 'data_eleicao', 'turno'],
                'comparecimento_municipio_data_turno_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparecimentos_eleitorais_municipio');
    }
};
