<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /** دسته‌بندی‌های فعال برای فرم‌ها و فیلترها. */
    public function index(Request $request)
    {
        $type = $request->validate([
            'type' => ['required', 'in:wish,blog'],
        ])['type'];

        return response()->json([
            'data' => Category::query()
                ->ofType($type)
                ->active()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'icon', 'color', 'description']),
        ]);
    }
}
