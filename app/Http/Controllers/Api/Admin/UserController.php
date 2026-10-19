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
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()->select([
            'id',
            'name',
            'email',
            'mobile',
            'role',
            'city',
            'created_at',
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $like = '%' . $search . '%';

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

        $users = $query
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

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
            ],
        ]);
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