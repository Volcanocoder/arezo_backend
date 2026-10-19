<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();

            // آرزویی که قرار است برای آن کمک شود
            $table->foreignId('wish_id')
                ->constrained('wishes')
                ->cascadeOnDelete();

            // کاربری که کمک را انجام می‌دهد
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // مبلغ کمک به تومان
            $table->unsignedBigInteger('amount');

            // pending، paid، failed یا cancelled
            $table->string('status', 20)
                ->default('pending')
                ->index();

            // اطلاعات درگاه پرداخت
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_authority')->nullable()->unique();
            $table->string('gateway_reference')->nullable()->unique();

            // زمان تأیید پرداخت
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['wish_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};