<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // گفتگویی که پیام به آن تعلق دارد
            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            // فرستنده پیام
            $table->foreignId('sender_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // متن پیام
            $table->text('body');

            // زمان خوانده شدن پیام
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};