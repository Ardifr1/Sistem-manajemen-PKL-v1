<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail publikasi/pembatalan nilai akhir (siapa, kapan, tindakan apa).
        Schema::create('final_assessment_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('final_assessment_id')
                ->constrained('final_assessments')
                ->cascadeOnDelete();
            $table->string('action', 20); // published | unpublished
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('final_assessment_status_logs');
    }
};
