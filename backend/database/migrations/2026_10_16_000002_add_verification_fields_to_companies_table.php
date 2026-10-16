<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom untuk alur pengajuan perusahaan NONMITRA oleh siswa dan
     * verifikasinya oleh Admin Sekolah.
     *
     * Perusahaan lama (mitra) memiliki verification_status NULL dan tetap
     * dianggap sah — tidak ada data lama yang diubah, sehingga aturan bisnis
     * sebelumnya tidak beregresi.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // pending_school_verification | verified | rejected (NULL = data lama/instan admin)
            $table->string('verification_status', 40)
                ->nullable()
                ->after('is_active');

            $table->string('contact_person', 255)->nullable()->after('email');

            // Siswa yang mengajukan perusahaan ini (NULL = dimasukkan admin).
            $table->foreignId('submitted_by')
                ->nullable()
                ->after('verification_status')
                ->constrained('users')
                ->nullOnDelete();

            // Bukti opsional (dokumen legalitas/proposal) pada disk lokal.
            $table->string('proof_path')->nullable()->after('submitted_by');

            // Catatan verifikasi / alasan penolakan.
            $table->text('verification_note')->nullable()->after('proof_path');

            $table->foreignId('verified_by')
                ->nullable()
                ->after('verification_note')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'verification_status',
                'contact_person',
                'proof_path',
                'verification_note',
                'verified_at',
            ]);
        });
    }
};
