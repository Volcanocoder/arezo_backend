<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wish;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class WishDocumentController extends Controller
{
    public function show(Wish $wish, string $documentType): Response
    {
        // کاربر نمی‌تواند مسیر فایل دلخواهی را درخواست کند.
        $documentFields = [
            'image' => 'wish_image_path',
            'identity' => 'identity_document_path',
            'evidence' => 'evidence_document_path',
        ];

        abort_unless(
            array_key_exists($documentType, $documentFields),
            404
        );

        $field = $documentFields[$documentType];
        $path = $wish->{$field};

        abort_if(
            ! is_string($path) || $path === '',
            404,
            'برای این پرونده فایلی ثبت نشده است.'
        );

        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404);

        $mimeType = $disk->mimeType($path);

        $allowedTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        // فقط فایل‌های مورد انتظار قابل ارائه هستند.
        abort_unless(
            is_string($mimeType)
                && array_key_exists($mimeType, $allowedTypes),
            415,
            'نوع فایل برای نمایش یا دریافت مجاز نیست.'
        );

        $filename = sprintf(
            'wish-%d-%s.%s',
            $wish->id,
            $documentType,
            $allowedTypes[$mimeType]
        );

        // تصویر در مرورگر نمایش داده می‌شود؛ PDF به‌صورت فایل دریافت می‌شود.
        $disposition = $mimeType === 'application/pdf'
            ? 'attachment'
            : 'inline';

        return response()->file(
            $disk->path($path),
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' =>
                    $disposition . '; filename="' . $filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
            ]
        );
    }
}