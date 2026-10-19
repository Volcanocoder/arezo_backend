
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // آیا پیام از دید کاربران مخفی شده است؟
            $table->boolean('is_hidden')
                ->default(false)
                ->index();

            // دلیل مخفی شدن پیام، برای بررسی بعدی
            $table->text('moderation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'is_hidden',
                'moderation_reason',
            ]);
        });
    }
};