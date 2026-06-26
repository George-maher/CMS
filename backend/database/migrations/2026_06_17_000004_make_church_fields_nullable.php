<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->string('priest_phone', 20)->nullable()->change();
            $table->string('service_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->string('priest_phone', 20)->nullable(false)->change();
            $table->string('service_name')->nullable(false)->change();
        });
    }
};
