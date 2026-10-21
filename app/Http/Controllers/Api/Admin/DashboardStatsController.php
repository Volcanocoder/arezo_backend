<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardStatsController extends Controller
{
    public function index(Request $request)
    {
        $days = (int) $request->validate([
            'days' => ['nullable', 'integer', 'in:7,14,30'],
        ])['days'] ?? 7;

        $today = Carbon::today();
        $startDate = $today->copy()->subDays($days - 1);

        // آمار کلی آرزوها
        $wishCounts = Wish::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // ثبت‌نام روزانه کاربران در هفت روز اخیر
        $dailyUsers = User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->where('created_at', '>=', $startDate)
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        // آرزوهای ثبت‌شده در هفت روز اخیر
        $dailyWishes = Wish::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->where('created_at', '>=', $startDate)
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        // ساخت روزهای نمودار، حتی برای روزهایی که فعالیت نداشته‌اند
        $dailyActivity = collect(range($days - 1, 0))
            ->map(function ($daysAgo) use (
                $today,
                $dailyUsers,
                $dailyWishes
            ) {
                $date = $today->copy()
                    ->subDays($daysAgo)
                    ->toDateString();

                return [
                    'date' => $date,
                    'users' => (int) ($dailyUsers[$date] ?? 0),
                    'wishes' => (int) ($dailyWishes[$date] ?? 0),
                ];
            })
            ->values();

        // فقط کمک‌هایی که پرداخت موفق آن‌ها ثبت شده است
        $paidDonations = Donation::query()
            ->whereNotNull('paid_at');

        return response()->json([
            'users' => [
                'total' => User::count(),
                'today' => User::whereDate(
                    'created_at',
                    $today
                )->count(),
            ],

            'wishes' => [
                'total' => Wish::count(),
                'pending' => (int) ($wishCounts['pending'] ?? 0),
                'approved' => (int) ($wishCounts['approved'] ?? 0),
                'rejected' => (int) ($wishCounts['rejected'] ?? 0),
                'needs_info' => (int) ($wishCounts['needs_info'] ?? 0),
                'today' => Wish::whereDate(
                    'created_at',
                    $today
                )->count(),
            ],

            'donations' => [
                'paid_count' => (clone $paidDonations)->count(),
                'paid_amount' => (int) (clone $paidDonations)
                    ->sum('amount'),
            ],

            'daily_activity' => $dailyActivity,

            'categories' => Wish::query()
                ->selectRaw('category, COUNT(*) as aggregate')
                ->groupBy('category')
                ->orderByDesc('aggregate')
                ->limit(8)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->category,
                    'count' => (int) $row->aggregate,
                ]),

            'recent_wishes' => Wish::query()
                ->latest('id')
                ->limit(6)
                ->get(['id', 'title', 'category', 'status', 'requested_amount', 'applicant_name', 'created_at']),

            'recent_users' => User::query()
                ->latest('id')
                ->limit(5)
                ->get(['id', 'name', 'mobile', 'role', 'created_at']),

            'oldest_pending_at' => Wish::query()
                ->where('status', 'pending')
                ->min('created_at'),

            'blog' => [
                'published' => \App\Models\Post::query()->published()->count(),
                'drafts' => \App\Models\Post::query()->where('status', 'draft')->count(),
            ],
        ]);
    }
}