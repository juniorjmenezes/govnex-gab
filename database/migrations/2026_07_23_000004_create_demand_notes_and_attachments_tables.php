<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demanda_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('demanda_id')->constrained('demandas')->cascadeOnDelete();
            $table->foreignId('demanda_evento_id')->nullable()->constrained('demanda_eventos')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('caminho');
            $table->string('nome_original');
            $table->string('nome_armazenado');
            $table->string('mime_type', 150);
            $table->string('extensao', 12);
            $table->unsignedBigInteger('tamanho');
            $table->boolean('imagem')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gabinete_id', 'demanda_id', 'created_at']);
            $table->index(['gabinete_id', 'demanda_evento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demanda_anexos');
    }
};
