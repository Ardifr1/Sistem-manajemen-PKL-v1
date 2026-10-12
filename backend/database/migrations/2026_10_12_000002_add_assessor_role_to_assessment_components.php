<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            // Peran penilai yang wajib mengisi komponen ini: teacher | supervisor
            $table->string('assessor_role', 20)->default('teacher')->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropColumn('assessor_role');
        });
    }
};
