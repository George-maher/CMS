<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['church_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
