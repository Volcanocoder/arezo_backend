<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishes', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('applicant_relation', 80)->nullable();
            $table->string('wish_image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishes', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'applicant_relation',
                'wish_image_path',
            ]);
        });
    }
};
