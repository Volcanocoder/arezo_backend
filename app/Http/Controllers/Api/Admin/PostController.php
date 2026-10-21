<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'draft', 'published'])],
        ]);

        $query = Post::query()->select([
            'id', 'title', 'slug', 'category', 'status', 'published_at', 'updated_at',
        ]);

        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($validated['q'])).'%';
            $query->where('title', 'like', $like);
        }

        if (($validated['status'] ?? 'all') !== 'all') {
            $query->where('status', $validated['status']);
        }

        $posts = $query->latest('id')->paginate(15)->withQueryString();

        return response()->json([
            'data' => $posts->items(),
            'current_page' => $posts->currentPage(),
            'last_page' => $posts->lastPage(),
            'total' => $posts->total(),
        ]);
    }

    public function show(Post $post)
    {
        return response()->json(['post' => $post->makeHidden('body_html')]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $post = Post::create($this->prepare($data) + ['author_id' => $request->user()->id]);

        return response()->json([
            'message' => 'مقاله ذخیره شد.',
            'post' => $post->makeHidden('body_html'),
        ], 201);
    }

    public function update(Request $request, Post $post)
    {
        $data = $this->validated($request, $post);

        $post->update($this->prepare($data, $post));

        return response()->json([
            'message' => 'مقاله به‌روز شد.',
            'post' => $post->fresh()->makeHidden('body_html'),
        ]);
    }

    /** پیش‌نمایش Markdown با همان پاک‌سازی نسخه نهایی. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:100000'],
        ]);

        return response()->json(['html' => Post::renderMarkdown($data['body'])]);
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->json(['message' => 'مقاله حذف شد.']);
    }

    private function validated(Request $request, ?Post $post = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => [
                'nullable', 'string', 'max:190', 'regex:/^[\p{L}\p{N}-]+$/u',
                Rule::unique('posts', 'slug')->ignore($post?->id),
            ],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:100000'],
            'category' => ['nullable', 'string', 'max:80'],
            'cover_image_url' => ['nullable', 'url:http,https', 'max:500'],
            'cover_image_alt' => ['nullable', 'string', 'max:200'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => ['nullable', 'date'],
        ]);
    }

    private function prepare(array $data, ?Post $post = null): array
    {
        $data['slug'] = ! empty($data['slug'])
            ? $data['slug']
            : Post::makeSlug($data['title'], $post?->id);

        $data['body_html'] = Post::renderMarkdown($data['body']);

        if ($data['status'] === 'published') {
            $data['published_at'] = $data['published_at'] ?? $post?->published_at ?? now();
        } else {
            $data['published_at'] = null;
        }

        return $data;
    }
}
