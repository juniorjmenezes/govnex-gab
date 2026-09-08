<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relatorio_exportacoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('solicitado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('formato', 10);
            $table->json('filtros');
            $table->string('status', 20)->default('pendente');
            $table->string('disk', 40)->default('local');
            $table->string('caminho')->nullable();
            $table->string('nome_arquivo')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->timestamps();

            $table->index(['gabinete_id', 'status', 'created_at'], 'report_exports_status_idx');
            $table->index(['gabinete_id', 'solicitado_por_id', 'created_at'], 'report_exports_requester_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relatorio_exportacoes');
    }
};
