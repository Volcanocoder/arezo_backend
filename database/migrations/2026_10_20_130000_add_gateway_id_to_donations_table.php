<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            // درگاهی که این پرداخت با آن شروع شد؛ برای تأیید با همان مرچنت‌کد
            $table->unsignedBigInteger('payment_gateway_id')->nullable()->after('gateway');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('payment_gateway_id');
        });
    }
};
