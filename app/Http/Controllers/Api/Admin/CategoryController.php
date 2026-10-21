<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Wish;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->validate([
            'type' => ['required', 'in:wish,blog'],
        ])['type'];

        $usage = $type === 'wish'
            ? Wish::query()->selectRaw('category, COUNT(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category')
            : Post::query()->selectRaw('category, COUNT(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category');

        $items = Category::query()
            ->ofType($type)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category) => $category->toArray() + [
                'usage_count' => (int) ($usage[$category->name] ?? 0),
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:wish,blog'],
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('categories', 'name')->where('type', $request->input('type')),
            ],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['name'] = trim($data['name']);
        $data['sort_order'] = (int) Category::query()->ofType($data['type'])->max('sort_order') + 1;

        $category = Category::create($data);

        return response()->json([
            'message' => 'دسته‌بندی ساخته شد.',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('categories', 'name')
                    ->where('type', $category->type)
                    ->ignore($category->id),
            ],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['name'] = trim($data['name']);

        DB::transaction(function () use ($category, $data) {
            $oldName = $category->name;
            $category->update($data);

            // نام دسته در جدول آرزوها/مقاله‌ها به‌صورت متن ذخیره می‌شود؛ با تغییر نام هماهنگ می‌شود.
            if ($oldName !== $category->name) {
                $model = $category->type === 'wish' ? Wish::query() : Post::query();
                $model->where('category', $oldName)->update(['category' => $category->name]);
            }
        });

        return response()->json([
            'message' => 'دسته‌بندی به‌روز شد.',
            'category' => $category->fresh(),
        ]);
    }

    /** ترتیب نمایش: آرایه‌ای از شناسه‌ها به ترتیب دلخواه. */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:wish,blog'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $position => $id) {
                Category::query()
                    ->ofType($data['type'])
                    ->whereKey($id)
                    ->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(['message' => 'ترتیب ذخیره شد.']);
    }

    public function destroy(Category $category)
    {
        $model = $category->type === 'wish' ? Wish::query() : Post::query();

        if ($model->where('category', $category->name)->exists()) {
            return response()->json([
                'message' => 'این دسته‌بندی در حال استفاده است. به‌جای حذف، آن را غیرفعال کن.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'دسته‌بندی حذف شد.']);
    }
}
