<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alinha os valores persistidos da coluna de escopo de módulo com o
     * backing value atual do enum (Entidade/Gabinete), que já havia sido
     * renomeado nos casos PHP mas continuava gravado como ORGANIZACAO/UNIDADE
     * no banco.
     */
    public function up(): void
    {
        DB::table('entidade_modulos')->where('escopo', 'ORGANIZACAO')->update(['escopo' => 'ENTIDADE']);
        DB::table('entidade_modulos')->where('escopo', 'UNIDADE')->update(['escopo' => 'GABINETE']);
    }

    public function down(): void
    {
        DB::table('entidade_modulos')->where('escopo', 'ENTIDADE')->update(['escopo' => 'ORGANIZACAO']);
        DB::table('entidade_modulos')->where('escopo', 'GABINETE')->update(['escopo' => 'UNIDADE']);
    }
};
