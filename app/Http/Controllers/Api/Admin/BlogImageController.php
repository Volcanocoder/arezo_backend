<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BlogImageController extends Controller
{
    /** آپلود تصویر وبلاگ (شاخص یا داخل متن)؛ آدرس عمومی برمی‌گردد. */
    public function store(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
        ]);

        $path = $request->file('image')->store('blog-images', 'local');

        return response()->json([
            'url' => url('/api/media/blog/'.basename($path)),
        ], 201);
    }
}
