<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nomor HP/WhatsApp untuk pengiriman informasi akun.
            $table->string('phone', 30)->nullable()->after('email');

            // Token pengaturan password awal (disimpan dalam bentuk hash,
            // bukan teks biasa) + masa berlaku. Password asli tidak pernah
            // dikirim admin dan tidak pernah dikirim sebagai teks biasa.
            $table->string('password_setup_token')->nullable()->after('password');
            $table->timestamp('password_setup_expires_at')->nullable()->after('password_setup_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'password_setup_token', 'password_setup_expires_at']);
        });
    }
};
