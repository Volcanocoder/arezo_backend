<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Post extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'body_html',
        'category',
        'cover_image_url',
        'cover_image_alt',
        'meta_title',
        'meta_description',
        'status',
        'published_at',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** فقط مقاله‌هایی که منتشر شده‌اند و زمان انتشارشان رسیده است. */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('published_at', '<=', now());
    }

    /** تبدیل Markdown به HTML امن؛ HTML خام و لینک‌های خطرناک حذف می‌شوند. */
    public static function renderMarkdown(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /** نشانی یکتا؛ حروف فارسی حفظ می‌شود. */
    public static function makeSlug(string $text, ?int $ignoreId = null): string
    {
        $base = mb_strtolower(trim($text));
        $base = str_replace("\u{200C}", '', $base);
        $base = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $base) ?? '';
        $base = preg_replace('/[\s_-]+/u', '-', $base) ?? '';
        $base = trim($base, '-');
        $base = mb_substr($base === '' ? 'post' : $base, 0, 170);

        $slug = $base;
        $i = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function readingTimeMinutes(): int
    {
        $words = preg_split('/\s+/u', trim(strip_tags($this->body_html)), -1, PREG_SPLIT_NO_EMPTY);

        return max(1, (int) ceil(count($words) / 200));
    }

    public function excerptText(): string
    {
        if ($this->excerpt) {
            return $this->excerpt;
        }

        return Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($this->body_html)) ?? ''), 160);
    }

    /** خلاصه برای فهرست‌ها. */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerptText(),
            'category' => $this->category,
            'cover_image_url' => $this->cover_image_url,
            'cover_image_alt' => $this->cover_image_alt ?: $this->title,
            'published_at' => $this->published_at?->toIso8601String(),
            'reading_time' => $this->readingTimeMinutes(),
        ];
    }
}
