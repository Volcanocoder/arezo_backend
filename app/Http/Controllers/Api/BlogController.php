<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Post::query()->published();

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($validated['q'])).'%';

            $query->where(function ($sub) use ($like) {
                $sub->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });
        }

        $posts = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(9)
            ->withQueryString();

        $categories = Post::query()
            ->published()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return response()->json([
            'data' => collect($posts->items())->map->toSummary()->values(),
            'current_page' => $posts->currentPage(),
            'last_page' => $posts->lastPage(),
            'total' => $posts->total(),
            'categories' => $categories,
        ]);
    }

    public function show(string $slug)
    {
        $post = Post::query()
            ->published()
            ->with('author:id,name')
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Post::query()
            ->published()
            ->whereKeyNot($post->id)
            ->when($post->category, fn ($q) => $q->where('category', $post->category))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map->toSummary()
            ->values();

        return response()->json([
            ...$post->toSummary(),
            'body_html' => $post->body_html,
            'meta_title' => $post->meta_title ?: $post->title,
            'meta_description' => $post->meta_description ?: $post->excerptText(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'author_name' => $post->author?->name,
            'related' => $related,
        ]);
    }

    /** فهرست کامل برای sitemap. */
    public function sitemap()
    {
        return Post::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at'])
            ->map(fn (Post $post) => [
                'slug' => $post->slug,
                'updated_at' => $post->updated_at?->toIso8601String(),
            ]);
    }
}
