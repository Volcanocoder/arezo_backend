<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            // wish = دسته‌بندی آرزوها ، blog = دسته‌بندی مقاله‌ها
            $table->string('type', 10);
            $table->string('name', 80);
            $table->string('icon', 16)->nullable();
            $table->string('color', 9)->nullable();
            $table->string('description', 200)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'name']);
            $table->index(['type', 'is_active', 'sort_order']);
        });

        $now = now();

        $defaults = [
            ['تحصیلی', '🎓', '#EEF0FF', 'کتاب، لپ‌تاپ، شهریه و آموزش'],
            ['درمانی', '❤️', '#FFF0F3', 'دارو، درمان و تجهیزات پزشکی'],
            ['وسایل ضروری', '🛍️', '#FFF6E8', 'وسایل مورد نیاز زندگی'],
            ['آموزشی', '📚', '#EEF8F2', 'کلاس، دوره و مهارت‌آموزی'],
            ['تجربه و تفریح', '✨', '#F0F9FF', 'ساختن یک خاطره زیبا'],
            ['خانواده', '🏠', '#F4F0FF', 'کمک به خانواده و عزیزان'],
            ['سایر', '💫', '#EEF8F2', 'آرزوهای متفاوت و خاص'],
        ];

        foreach ($defaults as $i => [$name, $icon, $color, $description]) {
            DB::table('categories')->insert([
                'type' => 'wish',
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                'description' => $description,
                'sort_order' => $i + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['راهنما', 'اخبار', 'داستان‌های موفق'] as $i => $name) {
            DB::table('categories')->insert([
                'type' => 'blog',
                'name' => $name,
                'sort_order' => $i + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
