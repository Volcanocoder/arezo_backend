<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    /** تصویرهای وبلاگ؛ بدون نیاز به storage:link و فقط فایل‌های تصویری. */
    public function blog(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]+\.(jpe?g|png|webp|gif)$/', $file) === 1, 404);

        $path = 'blog-images/'.$file;
        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path);

        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=604800, immutable',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    /** تصویر پروفایل کاربران. */
    public function avatar(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]+\.(jpe?g|png|webp)$/', $file) === 1, 404);

        $path = 'avatars/'.$file;
        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path);

        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
