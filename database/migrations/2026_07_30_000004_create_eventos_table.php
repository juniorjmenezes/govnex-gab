<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('criado_por_id')->constrained('users')->restrictOnDelete();
            $table->string('titulo');
            $table->string('tipo', 30);
            $table->string('status', 30)->default('planejado');
            $table->string('duracao', 30)->default('unico_dia');
            $table->dateTime('inicio_em');
            $table->dateTime('fim_em');
            $table->string('local')->nullable();
            $table->text('descricao')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gabinete_id', 'inicio_em']);
            $table->index(['gabinete_id', 'status', 'inicio_em']);
            $table->index(['gabinete_id', 'tipo', 'inicio_em']);
            $table->index(['gabinete_id', 'responsavel_id', 'inicio_em']);
        });

        Schema::create('evento_participantes_usuarios', function (Blueprint $table) {
            $table->foreignId('evento_id')
                ->constrained('eventos')
                ->cascadeOnDelete();
            $table->foreignId('usuario_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['evento_id', 'usuario_id']);
        });

        Schema::create('evento_participantes_cidadaos', function (Blueprint $table) {
            $table->foreignId('evento_id')
                ->constrained('eventos')
                ->cascadeOnDelete();
            $table->foreignId('cidadao_id')
                ->constrained('cidadaos')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['evento_id', 'cidadao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_participantes_cidadaos');
        Schema::dropIfExists('evento_participantes_usuarios');
        Schema::dropIfExists('eventos');
    }
};
