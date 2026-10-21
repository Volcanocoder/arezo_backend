<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('slug', 190)->unique();
            $table->string('excerpt', 300)->nullable();
            // متن مقاله به‌صورت Markdown ذخیره می‌شود و نسخه HTML پاک‌سازی‌شده جدا نگه داشته می‌شود.
            $table->longText('body');
            $table->longText('body_html');
            $table->string('category', 80)->nullable()->index();
            $table->string('cover_image_url', 500)->nullable();
            $table->string('cover_image_alt', 200)->nullable();
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
