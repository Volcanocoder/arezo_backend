<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // دیوار مهربانی: آگهی وسایل دست‌دوم
        Schema::create('kindness_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('category', 60);
            $table->text('description');
            $table->string('condition', 20);
            $table->string('city', 120);
            $table->string('image_path')->nullable();

            // pending، approved، rejected، reserved یا given
            $table->string('status', 20)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // درخواست کاربران برای یک کالا
        Schema::create('kindness_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kindness_item_id')->constrained('kindness_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');

            // pending، approved، rejected یا completed
            $table->string('status', 20)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['kindness_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kindness_requests');
        Schema::dropIfExists('kindness_items');
    }
};
