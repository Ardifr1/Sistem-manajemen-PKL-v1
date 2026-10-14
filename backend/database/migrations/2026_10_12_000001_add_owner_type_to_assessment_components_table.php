<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            // Pemilik komponen: 'industri' (Pembimbing Industri) atau
            // 'guru' (Guru Pembimbing). Baris lama dianggap milik industri
            // agar penilaian perusahaan yang sudah ada tetap valid.
            $table->string('owner_type', 20)->default('industri')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropColumn('owner_type');
        });
    }
};
