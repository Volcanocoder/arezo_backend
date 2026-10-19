<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // اطلاعات تکمیلی پروفایل
            $table->string('city', 100)->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_path')->nullable();

            // تنظیمات اعلان‌ها
            $table->boolean('wish_updates')->default(true);
            $table->boolean('new_messages')->default(true);
            $table->boolean('wish_completed')->default(true);
            $table->boolean('email_updates')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'city',
                'bio',
                'avatar_path',
                'wish_updates',
                'new_messages',
                'wish_completed',
                'email_updates',
            ]);
        });
    }
};