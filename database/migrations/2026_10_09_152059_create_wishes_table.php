
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishes', function (Blueprint $table) {
            $table->id();

            // اطلاعات درخواست
            $table->string('title', 180);
            $table->string('category', 80);
            $table->longText('story');
            $table->string('city', 120);
            $table->unsignedBigInteger('requested_amount');
            $table->string('urgency', 30)->default('normal');

            // اطلاعات متقاضی
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('applicant_name', 150);
            $table->string('applicant_mobile', 30)->index();
            $table->string('applicant_email')->nullable();

            // مسیر خصوصی مدارک
            $table->string('identity_document_path')->nullable();
            $table->string('evidence_document_path')->nullable();

            // بررسی توسط مدیر
            $table->string('status', 30)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishes');
    }
};
