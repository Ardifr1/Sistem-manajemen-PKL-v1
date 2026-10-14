<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_assessments', function (Blueprint $table) {
            $table->foreignId('published_by')
                ->nullable()
                ->after('finalized_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('published_at')->nullable()->after('published_by');
            $table->text('unpublish_reason')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('final_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['published_at', 'unpublish_reason']);
        });
    }
};
