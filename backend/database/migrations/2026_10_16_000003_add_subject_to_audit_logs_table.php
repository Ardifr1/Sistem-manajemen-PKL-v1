<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log sebelumnya hanya dapat mencatat peristiwa seputar penempatan
     * dan penilaian akhir. Untuk mencatat perubahan akses (pemberian/
     * pencabutan keanggotaan perusahaan, verifikasi perusahaan nonmitra)
     * ditambahkan kolom subjek generik + metadata.
     *
     * Semua kolom baru nullable sehingga baris audit lama tetap valid.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('subject_type', 100)->nullable()->after('action');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->json('metadata')->nullable()->after('reason');

            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_subject_index');
            $table->dropColumn(['subject_type', 'subject_id', 'metadata']);
        });
    }
};
