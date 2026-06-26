<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_applications', function (Blueprint $table) {
            $table->id();
            $table->string('church_name');
            $table->string('service_name')->nullable();
            $table->string('priest_name');
            $table->string('priest_phone', 20);
            $table->text('address')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('description')->nullable();
            $table->string('front_id_path')->nullable();
            $table->string('back_id_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_applications');
    }
};
