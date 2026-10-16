<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keanggotaan perusahaan yang eksplisit.
     *
     * Sebelumnya kewenangan sisi industri hanya disimpulkan dari tabel
     * company_supervisors (user_id -> company_id). Struktur itu tidak dapat
     * menyatakan kapabilitas (company_admin, review_applications,
     * verify_journals) maupun status aktif/nonaktif sebuah keanggotaan,
     * sehingga sulit diaudit dan sulit dipakai untuk mendeteksi konflik
     * kepentingan. Tabel ini dibuat ADITIF: company_supervisors tetap
     * berjalan (lihat App\Policies\Concerns\ResolvesCompanyAccess).
     */
    public function up(): void
    {
        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // active | inactive
            $table->string('status', 20)->default('active');

            // Daftar kapabilitas eksplisit, mis.
            // ["company_admin","review_applications","verify_journals"].
            $table->json('capabilities')->nullable();

            // Siapa yang memberi/mencabut akses (jejak audit di tingkat baris).
            $table->foreignId('granted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // Satu keanggotaan per user per perusahaan.
            $table->unique(['user_id', 'company_id'], 'unique_user_company_membership');
            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');
    }
};
