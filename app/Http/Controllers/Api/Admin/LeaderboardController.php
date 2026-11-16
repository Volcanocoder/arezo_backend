<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\KindnessItem;
use App\Models\User;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    /** رتبه‌بندی خیرین: مجموع کمک مالی و تعداد کالاهای اهداشده در دو ستون جدا. */
    public function index(Request $request)
    {
        $sort = $request->query('sort') === 'items' ? 'items' : 'amount';

        $money = Donation::query()
            ->where('status', 'paid')
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM(amount) as total, COUNT(*) as cnt')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $goods = KindnessItem::query()
            ->where('status', 'given')
            ->selectRaw('user_id, COUNT(*) as cnt')
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        $ids = $money->keys()->merge($goods->keys())->unique()->values();

        $rows = User::query()->whereIn('id', $ids)->get(['id', 'name', 'mobile'])->map(fn (User $u) => [
            'user_id' => $u->id,
            'name' => $u->name,
            'mobile' => $u->mobile,
            'total_amount' => (int) ($money[$u->id]->total ?? 0),
            'donations_count' => (int) ($money[$u->id]->cnt ?? 0),
            'items_given' => (int) ($goods[$u->id] ?? 0),
        ]);

        $key = $sort === 'items' ? 'items_given' : 'total_amount';
        $tie = $sort === 'items' ? 'total_amount' : 'items_given';

        $ranked = $rows
            ->sort(fn ($a, $b) => [$b[$key], $b[$tie]] <=> [$a[$key], $a[$tie]])
            ->take(50)
            ->values()
            ->map(fn ($row, $i) => ['rank' => $i + 1] + $row);

        return response()->json([
            'data' => $ranked,
            'sort' => $sort,
            'totals' => [
                'donors' => $money->count(),
                'total_amount' => (int) $money->sum('total'),
                'items_given' => (int) $goods->sum(),
            ],
        ]);
    }
}
