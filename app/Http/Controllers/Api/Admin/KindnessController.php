<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\KindnessController as PublicKindnessController;
use App\Http\Controllers\Controller;
use App\Models\KindnessItem;
use App\Models\KindnessRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KindnessController extends Controller
{
    private const ITEM_STATUSES = ['pending', 'approved', 'rejected', 'reserved', 'given'];

    private const REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'completed'];

    public function items(Request $request)
    {
        $status = $request->query('status');
        $query = KindnessItem::query()->with('user:id,name,mobile')->withCount([
            'requests',
            'requests as pending_requests_count' => fn ($q) => $q->where('status', 'pending'),
        ])->latest('id');

        if (in_array($status, self::ITEM_STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%');
        }

        $page = $query->paginate(15)->withQueryString();
        $page->getCollection()->transform(fn (KindnessItem $i) => $this->itemPayload($i));

        $counts = KindnessItem::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json($page->toArray() + [
            'counts' => collect(self::ITEM_STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function showItem(KindnessItem $item)
    {
        $item->load(['user', 'requests.user']);

        return response()->json([
            'data' => $this->itemPayload($item, true) + [
                'requests' => $item->requests->sortByDesc('id')->map(fn (KindnessRequest $r) => $this->requestPayload($r, $item, true))->values(),
            ],
        ]);
    }

    /** ویرایش مشخصات آگهی توسط مدیر؛ وضعیت تغییر نمی‌کند و صاحب آگهی خبردار می‌شود. */
    public function updateItem(Request $request, KindnessItem $item)
    {
        PublicKindnessController::applyEdit($request, $item);
        $item->save();

        UserNotification::notify(
            $item->user_id,
            'آگهی‌ات ویرایش شد',
            "مدیر مشخصات آگهی «{$item->title}» را ویرایش کرد.",
            'info',
            '/dashboard/kindness',
        );

        return response()->json(['message' => 'آگهی ویرایش شد.', 'data' => $this->itemPayload($item->fresh('user'), true)]);
    }

    public function itemImage(KindnessItem $item)
    {
        return PublicKindnessController::imageResponse($item);
    }

    public function updateItemStatus(Request $request, KindnessItem $item)
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ]);

        $allowed = ['pending' => ['approved', 'rejected'], 'approved' => ['rejected']];

        if (! in_array($data['status'], $allowed[$item->status] ?? [], true)) {
            return response()->json(['message' => 'تغییر وضعیت از حالت فعلی ممکن نیست.'], 422);
        }

        DB::transaction(function () use ($item, $data) {
            $item->update([
                'status' => $data['status'],
                'review_note' => $data['status'] === 'rejected' ? $data['review_note'] : null,
                'reviewed_at' => now(),
            ]);

            // با حذف آگهی، درخواست‌های در انتظار آن هم بسته می‌شوند.
            if ($data['status'] === 'rejected') {
                $item->requests()->where('status', 'pending')->update([
                    'status' => 'rejected',
                    'review_note' => 'آگهی از دیوار مهربانی حذف شد.',
                    'reviewed_at' => now(),
                ]);
            }
        });

        $approved = $data['status'] === 'approved';

        UserNotification::notify(
            $item->user_id,
            $approved ? 'آگهی‌ات تأیید شد' : 'آگهی‌ات تأیید نشد',
            $approved
                ? "آگهی «{$item->title}» در دیوار مهربانی نمایش داده می‌شود."
                : "آگهی «{$item->title}» تأیید نشد. ".($data['review_note'] ?? ''),
            $approved ? 'success' : 'warning',
            '/dashboard/kindness',
        );

        return response()->json(['message' => 'وضعیت آگهی به‌روز شد.', 'data' => $this->itemPayload($item->fresh('user'))]);
    }

    public function requests(Request $request)
    {
        $status = $request->query('status');
        $query = KindnessRequest::query()->with(['item.user:id,name,mobile', 'user:id,name,mobile'])->latest('id');

        if (in_array($status, self::REQUEST_STATUSES, true)) {
            $query->where('status', $status);
        }

        $page = $query->paginate(15)->withQueryString();
        $page->getCollection()->transform(fn (KindnessRequest $r) => $this->requestPayload($r, $r->item));

        $counts = KindnessRequest::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json($page->toArray() + [
            'counts' => collect(self::REQUEST_STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function updateRequestStatus(Request $request, KindnessRequest $kindnessRequest)
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,completed'],
            'review_note' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ]);

        $allowed = ['pending' => ['approved', 'rejected'], 'approved' => ['completed', 'rejected']];

        if (! in_array($data['status'], $allowed[$kindnessRequest->status] ?? [], true)) {
            return response()->json(['message' => 'تغییر وضعیت از حالت فعلی ممکن نیست.'], 422);
        }

        $notifications = [];

        $error = DB::transaction(function () use ($kindnessRequest, $data, &$notifications) {
            $item = KindnessItem::query()->lockForUpdate()->findOrFail($kindnessRequest->kindness_item_id);
            $status = $data['status'];

            if ($status === 'approved') {
                if ($item->status !== 'approved') {
                    return 'این کالا دیگر آزاد نیست.';
                }

                $item->update(['status' => 'reserved']);

                $others = $item->requests()->where('status', 'pending')->where('id', '!=', $kindnessRequest->id)->get();

                foreach ($others as $other) {
                    $other->update([
                        'status' => 'rejected',
                        'review_note' => 'این کالا به درخواست دیگری اختصاص یافت.',
                        'reviewed_at' => now(),
                    ]);
                    $notifications[] = [$other->user_id, 'درخواستت پذیرفته نشد', "«{$item->title}» به درخواست دیگری اختصاص یافت.", 'warning'];
                }

                $notifications[] = [$kindnessRequest->user_id, 'درخواستت تأیید شد', "درخواست «{$item->title}» تأیید شد؛ مدیر برای هماهنگی تحویل با تو تماس می‌گیرد.", 'success'];
                $notifications[] = [$item->user_id, 'درخواستی برای کالایت تأیید شد', "برای «{$item->title}» یک درخواست تأیید شد؛ مدیر برای هماهنگی تحویل با تو تماس می‌گیرد.", 'info'];
            }

            if ($status === 'rejected') {
                // کالای رزروشده با رد درخواست دوباره آزاد می‌شود.
                if ($kindnessRequest->status === 'approved' && $item->status === 'reserved') {
                    $item->update(['status' => 'approved']);
                }

                $notifications[] = [$kindnessRequest->user_id, 'درخواستت تأیید نشد', "درخواست «{$item->title}» تأیید نشد. ".($data['review_note'] ?? ''), 'warning'];
            }

            if ($status === 'completed') {
                $item->update(['status' => 'given']);
                $kindnessRequest->completed_at = now();

                $notifications[] = [$kindnessRequest->user_id, 'تحویل کالا ثبت شد', "تحویل «{$item->title}» ثبت شد. ممنون که در دیوار مهربانی بودی.", 'success'];
                $notifications[] = [$item->user_id, 'ممنون از مهربانی‌ات', "تحویل «{$item->title}» ثبت شد. ممنون که بخشیدی.", 'success'];
            }

            $kindnessRequest->fill([
                'status' => $status,
                'review_note' => $status === 'rejected' ? $data['review_note'] : $kindnessRequest->review_note,
                'reviewed_at' => $kindnessRequest->reviewed_at ?? now(),
            ])->save();

            return null;
        });

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        foreach ($notifications as [$userId, $title, $body, $type]) {
            UserNotification::notify($userId, $title, $body, $type, '/dashboard/kindness');
        }

        $kindnessRequest->load(['item.user:id,name,mobile', 'user:id,name,mobile']);

        return response()->json([
            'message' => 'وضعیت درخواست به‌روز شد.',
            'data' => $this->requestPayload($kindnessRequest, $kindnessRequest->item),
        ]);
    }

    /** مشخصات آگهی‌دهنده/درخواست‌کننده؛ با $full پروفایل و سابقه مهربانی هم می‌آید. */
    private function person(?User $u, bool $full = false): ?array
    {
        if (! $u) {
            return null;
        }

        $base = ['id' => $u->id, 'name' => $u->name, 'mobile' => $u->mobile];

        if (! $full) {
            return $base;
        }

        return $base + [
            'email' => $u->email,
            'city' => $u->city,
            'bio' => $u->bio,
            'joined_at' => $u->created_at,
            'items_count' => KindnessItem::where('user_id', $u->id)->count(),
            'given_count' => KindnessItem::where('user_id', $u->id)->where('status', 'given')->count(),
            'received_count' => KindnessRequest::where('user_id', $u->id)->where('status', 'completed')->count(),
        ];
    }

    private function itemPayload(KindnessItem $i, bool $full = false): array
    {
        return [
            'id' => $i->id,
            'title' => $i->title,
            'category' => $i->category,
            'description' => $i->description,
            'condition' => $i->condition,
            'condition_label' => KindnessItem::CONDITIONS[$i->condition] ?? $i->condition,
            'city' => $i->city,
            'status' => $i->status,
            'review_note' => $i->review_note,
            'has_image' => (bool) $i->image_path,
            'created_at' => $i->created_at,
            'reviewed_at' => $i->reviewed_at,
            'requests_count' => $i->requests_count ?? null,
            'pending_requests_count' => $i->pending_requests_count ?? null,
            'owner' => $this->person($i->user, $full),
        ];
    }

    /** مدیر شماره هر دو طرف را برای هماهنگی تحویل می‌بیند. */
    private function requestPayload(KindnessRequest $r, ?KindnessItem $item, bool $full = false): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'message' => $r->message,
            'review_note' => $r->review_note,
            'created_at' => $r->created_at,
            'completed_at' => $r->completed_at,
            'item' => $item ? ['id' => $item->id, 'title' => $item->title, 'status' => $item->status] : null,
            'owner' => $this->person($item?->user),
            'requester' => $this->person($r->user, $full),
        ];
    }
}
