<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // آرزویی که گفتگو درباره آن است
            $table->foreignId('wish_id')
                ->constrained('wishes')
                ->cascadeOnDelete();

            // کاربری که می‌خواهد کمک کند
            $table->foreignId('donor_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // صاحب آرزو
            $table->foreignId('wish_owner_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // آخرین زمان فعالیت گفتگو
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            // هر کمک‌کننده برای هر آرزو فقط یک گفتگوی اصلی دارد
            $table->unique(['wish_id', 'donor_id']);
            $table->index(['wish_owner_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};