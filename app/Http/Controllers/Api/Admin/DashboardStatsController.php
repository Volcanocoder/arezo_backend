<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\User;
use App\Models\Wish;
use Carbon\Carbon;

class DashboardStatsController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $startDate = $today->copy()->subDays(6);

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
        $dailyActivity = collect(range(6, 0))
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
        ]);
    }
}