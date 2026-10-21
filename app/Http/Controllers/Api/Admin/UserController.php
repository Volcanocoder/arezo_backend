<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List users for the admin dashboard.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'role' => [
                'nullable',
                Rule::in(['all', 'user', 'admin', 'super_admin']),
            ],
            'status' => ['nullable', Rule::in(['all', 'active', 'suspended'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name', 'wishes'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->filteredQuery($validated)
            ->select([
                'id', 'name', 'email', 'mobile', 'role', 'city',
                'created_at', 'last_login_at', 'suspended_at',
            ])
            ->withCount(['wishes', 'donations']);

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'wishes' => $query->orderByDesc('wishes_count')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $users = $query->paginate(15)->withQueryString();

        $roleCounts = User::query()
            ->selectRaw('role, COUNT(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        return response()->json([
            'data' => $users->items(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
            'stats' => [
                'all' => (int) User::query()->count(),
                'user' => (int) ($roleCounts['user'] ?? 0),
                'admin' => (int) ($roleCounts['admin'] ?? 0),
                'super_admin' => (int) ($roleCounts['super_admin'] ?? 0),
                'suspended' => (int) User::query()->whereNotNull('suspended_at')->count(),
                'new_this_week' => (int) User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    /** جزئیات یک کاربر برای پنجره مشاهده. */
    public function show(User $user)
    {
        $user->loadCount(['wishes', 'donations']);

        return response()->json([
            'user' => $user->only([
                'id', 'name', 'email', 'mobile', 'role', 'city', 'bio',
                'created_at', 'last_login_at', 'suspended_at', 'suspension_reason',
                'wishes_count', 'donations_count',
            ]),
            'recent_wishes' => $user->wishes()
                ->latest()
                ->limit(5)
                ->get(['id', 'title', 'status', 'created_at']),
            'paid_total' => (int) $user->donations()->whereNotNull('paid_at')->sum('amount'),
        ]);
    }

    /** تعلیق حساب؛ نشست‌های فعال بسته می‌شود و ورود دوباره ممکن نیست. */
    public function suspend(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json(['message' => 'نمی‌توانی حساب خودت را تعلیق کنی.'], 422);
        }

        // مدیر عادی فقط کاربران عادی را تعلیق می‌کند؛ مدیرها را فقط مدیر ارشد.
        if ($user->role !== 'user' && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'تعلیق مدیران فقط با مدیر ارشد است.'], 403);
        }

        $user->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'] ?? null,
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        return response()->json(['message' => 'حساب تعلیق شد.']);
    }

    public function unsuspend(User $user)
    {
        $user->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        return response()->json(['message' => 'تعلیق حساب برداشته شد.']);
    }

    /** خروجی CSV؛ با فیلترهای فهرست یکسان است. */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(['all', 'user', 'admin', 'super_admin'])],
            'status' => ['nullable', Rule::in(['all', 'active', 'suspended'])],
        ]);

        $query = $this->filteredQuery($validated)
            ->select(['id', 'name', 'email', 'mobile', 'role', 'city', 'created_at', 'suspended_at'])
            ->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM برای باز شدن فارسی در Excel
            fputcsv($out, ['شناسه', 'نام', 'موبایل', 'ایمیل', 'نقش', 'شهر', 'وضعیت', 'تاریخ عضویت']);

            $query->chunkById(500, function ($users) use ($out) {
                foreach ($users as $user) {
                    fputcsv($out, [
                        $user->id,
                        self::csvSafe($user->name),
                        $user->mobile,
                        self::csvSafe($user->email),
                        $user->role,
                        self::csvSafe($user->city),
                        $user->suspended_at ? 'تعلیق' : 'فعال',
                        $user->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, 'users-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** جلوگیری از اجرای فرمول در Excel (CSV injection). */
    private static function csvSafe(?string $value): ?string
    {
        if ($value !== null && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function filteredQuery(array $validated)
    {
        $query = User::query();

        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

            $query->where(function ($subQuery) use ($like) {
                $subQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('mobile', 'like', $like);
            });
        }

        $role = $validated['role'] ?? 'all';

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        match ($validated['status'] ?? 'all') {
            'active' => $query->whereNull('suspended_at'),
            'suspended' => $query->whereNotNull('suspended_at'),
            default => null,
        };

        return $query;
    }

    /**
     * Change a user's role.
     * Only super admins can perform this action.
     */
    public function updateRole(Request $request, User $user)
    {
        $this->authorizeSuperAdmin();

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'message' => 'نمی‌توانید نقش حساب خودتان را از این بخش تغییر دهید.',
            ], 422);
        }

        $validated = $request->validate([
            'role' => [
                'required',
                Rule::in(['user', 'admin', 'super_admin']),
            ],
        ]);

        $result = DB::transaction(function () use ($user, $validated) {
            $target = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $target->role === 'super_admin'
                && $validated['role'] !== 'super_admin'
            ) {
                $superAdminCount = User::where('role', 'super_admin')->count();

                if ($superAdminCount <= 1) {
                    return [
                        'error' => 'نقش آخرین سوپر ادمین قابل تغییر نیست.',
                    ];
                }
            }

            $target->role = $validated['role'];
            $target->save();

            return [
                'data' => $target->only([
                    'id',
                    'name',
                    'email',
                    'mobile',
                    'role',
                    'city',
                    'created_at',
                ]),
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'message' => 'نقش کاربر با موفقیت تغییر کرد.',
            'data' => $result['data'],
        ]);
    }

    /**
     * Delete a user.
     * Only super admins can perform this action.
     */
    public function destroy(Request $request, User $user)
    {
        $this->authorizeSuperAdmin();

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'message' => 'امکان حذف حساب خودتان از این بخش وجود ندارد.',
            ], 422);
        }

        $result = DB::transaction(function () use ($user) {
            $target = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->role === 'super_admin') {
                $superAdminCount = User::where('role', 'super_admin')->count();

                if ($superAdminCount <= 1) {
                    return [
                        'error' => 'امکان حذف آخرین سوپر ادمین وجود ندارد.',
                        'status' => 422,
                    ];
                }
            }

            // Preserve wishes, donations and chat history.
            $hasRelatedRecords =
                DB::table('wishes')
                    ->where('user_id', $target->id)
                    ->exists()
                || DB::table('donations')
                    ->where('user_id', $target->id)
                    ->exists()
                || DB::table('conversations')
                    ->where(function ($query) use ($target) {
                        $query->where('donor_id', $target->id)
                            ->orWhere('wish_owner_id', $target->id);
                    })
                    ->exists()
                || DB::table('messages')
                    ->where('sender_id', $target->id)
                    ->exists();

            if ($hasRelatedRecords) {
                return [
                    'error' => 'این کاربر سابقه آرزو، کمک مالی یا گفتگو دارد. برای حفظ سوابق، ابتدا نقش او را تغییر دهید.',
                    'status' => 422,
                ];
            }

            $target->delete();

            return ['success' => true];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'کاربر با موفقیت حذف شد.',
        ]);
    }

    /**
     * Restrict sensitive user-management actions to super admins.
     */
    private function authorizeSuperAdmin(): void
    {
        abort_unless(
            auth()->user()?->role === 'super_admin',
            403,
            'فقط سوپر ادمین اجازه انجام این عملیات را دارد.'
        );
    }
}