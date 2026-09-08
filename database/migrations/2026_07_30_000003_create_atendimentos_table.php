<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atendimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('cidadao_id')->constrained('cidadaos')->restrictOnDelete();
            $table->foreignId('atendente_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('demanda_id')->nullable()->constrained('demandas')->nullOnDelete();
            $table->foreignId('criado_por_id')->constrained('users')->restrictOnDelete();
            $table->string('assunto');
            $table->text('relato');
            $table->text('providencias')->nullable();
            $table->timestamp('atendido_em');
            $table->unsignedSmallInteger('duracao_minutos')->nullable();
            $table->boolean('requer_retorno')->default(false);
            $table->date('retorno_previsto_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gabinete_id', 'atendido_em']);
            $table->index(['gabinete_id', 'cidadao_id', 'atendido_em']);
            $table->index(['gabinete_id', 'atendente_id', 'atendido_em']);
            $table->index(['gabinete_id', 'requer_retorno', 'retorno_previsto_em'], 'atendimentos_gabinete_retorno_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atendimentos');
    }
};
