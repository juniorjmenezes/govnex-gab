<?php

use App\Enums\GabineteTransferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gabinete_transferencias', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('entidade_origem_id')->constrained('entidades')->restrictOnDelete();
            $table->foreignId('entidade_destino_id')->constrained('entidades')->restrictOnDelete();
            $table->string('status', 30)->default(GabineteTransferStatus::PendingDestination->value);
            $table->timestamp('agendada_para')->nullable();
            $table->foreignId('solicitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aceita_origem_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aceita_origem_em')->nullable();
            $table->foreignId('aceita_destino_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aceita_destino_em')->nullable();
            $table->foreignId('aprovada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->foreignId('encerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('encerrada_em')->nullable();
            $table->text('motivo_encerramento')->nullable();
            $table->json('manifesto')->nullable();
            $table->char('manifesto_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['gabinete_id', 'status'], 'gabinete_transferencias_gabinete_status_idx');
            $table->index(['entidade_origem_id', 'status'], 'gabinete_transferencias_origem_status_idx');
            $table->index(['entidade_destino_id', 'status'], 'gabinete_transferencias_destino_status_idx');
        });

        Schema::create('gabinete_transferencia_eventos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('transferencia_id');
            $table->foreign('transferencia_id')->references('id')->on('gabinete_transferencias')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evento', 45);
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_novo', 30)->nullable();
            $table->json('contexto')->nullable();
            $table->timestamp('ocorrido_em');
            $table->timestamps();
            $table->index(['transferencia_id', 'ocorrido_em'], 'gabinete_transferencia_eventos_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gabinete_transferencia_eventos');
        Schema::dropIfExists('gabinete_transferencias');
    }
};
