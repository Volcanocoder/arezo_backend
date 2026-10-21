<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** گزارش تراکنش‌ها و حمایت‌های مالی. */
class TransactionController extends Controller
{
    private const STATUSES = ['pending', 'paid', 'failed', 'cancelled'];

    public function index(Request $request)
    {
        $filters = $this->validated($request);

        $page = $this->filtered($filters)
            ->with(['wish:id,title', 'user:id,name,mobile'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $paid = Donation::query()->where('status', 'paid');

        return response()->json([
            'data' => collect($page->items())->map(fn (Donation $d) => $this->present($d))->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'summary' => [
                'paid_amount' => (int) (clone $paid)->sum('amount'),
                'paid_count' => (int) (clone $paid)->count(),
                'today_amount' => (int) (clone $paid)->where('paid_at', '>=', now()->startOfDay())->sum('amount'),
                'month_amount' => (int) (clone $paid)->where('paid_at', '>=', now()->subDays(30))->sum('amount'),
                'average' => (int) round((clone $paid)->avg('amount') ?? 0),
                'pending_count' => Donation::query()->where('status', 'pending')->count(),
                'failed_count' => Donation::query()->whereIn('status', ['failed', 'cancelled'])->count(),
                // مجموع مبلغ در بازه/فیلتر فعلی (فقط پرداخت‌های موفق)
                'filtered_paid_amount' => (int) $this->filtered($filters)->where('status', 'paid')->sum('amount'),
            ],
            'gateways' => Donation::query()->whereNotNull('gateway')->distinct()->pluck('gateway'),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $this->validated($request);
        $query = $this->filtered($filters)->with(['wish:id,title', 'user:id,name,mobile'])->orderByDesc('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['شناسه', 'آرزو', 'کاربر', 'موبایل', 'مبلغ (تومان)', 'وضعیت', 'درگاه', 'شماره پیگیری', 'تاریخ ایجاد', 'تاریخ پرداخت']);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $d) {
                    fputcsv($out, array_map([$this, 'safe'], [
                        $d->id,
                        $d->wish?->title,
                        $d->user?->name ?? 'مهمان',
                        $d->user?->mobile,
                        $d->amount,
                        $d->status,
                        $d->gateway,
                        $d->gateway_reference,
                        $d->created_at?->toDateTimeString(),
                        $d->paid_at?->toDateTimeString(),
                    ]));
                }
            });

            fclose($out);
        }, 'transactions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** جلوگیری از اجرای فرمول در اکسل (CSV injection). */
    public function safe($value)
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'gateway' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'wish' => ['nullable', 'integer'],
        ]);
    }

    private function filtered(array $f): Builder
    {
        $query = Donation::query();

        if (! empty($f['status'])) {
            $query->where('status', $f['status']);
        }

        if (! empty($f['gateway'])) {
            $query->where('gateway', $f['gateway']);
        }

        if (! empty($f['wish'])) {
            $query->where('wish_id', $f['wish']);
        }

        if (! empty($f['from'])) {
            $query->where('created_at', '>=', $f['from'].' 00:00:00');
        }

        if (! empty($f['to'])) {
            $query->where('created_at', '<=', $f['to'].' 23:59:59');
        }

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($f['q'])).'%';

            $query->where(function ($sub) use ($like) {
                $sub->where('gateway_reference', 'like', $like)
                    ->orWhereHas('wish', fn ($w) => $w->where('title', 'like', $like))
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('mobile', 'like', $like));
            });
        }

        return $query;
    }

    private function present(Donation $d): array
    {
        return [
            'id' => $d->id,
            'amount' => (int) $d->amount,
            'status' => $d->status,
            'gateway' => $d->gateway,
            'reference' => $d->gateway_reference,
            'created_at' => $d->created_at?->toIso8601String(),
            'paid_at' => $d->paid_at?->toIso8601String(),
            'wish' => $d->wish ? ['id' => $d->wish->id, 'title' => $d->wish->title] : null,
            'user' => $d->user ? ['id' => $d->user->id, 'name' => $d->user->name, 'mobile' => $d->user->mobile] : null,
        ];
    }
}
