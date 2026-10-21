<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $filter = $request->validate([
            'filter' => ['nullable', 'in:all,unread'],
        ])['filter'] ?? 'all';

        $query = UserNotification::query()
            ->visibleTo($user)
            ->leftJoin('user_notification_reads as r', function ($join) use ($user) {
                $join->on('r.notification_id', '=', 'user_notifications.id')
                    ->where('r.user_id', '=', $user->id);
            })
            ->select('user_notifications.*', 'r.read_at as read_at')
            ->orderByDesc('user_notifications.created_at')
            ->orderByDesc('user_notifications.id');

        if ($filter === 'unread') {
            $query->whereNull('r.read_at');
        }

        $page = $query->paginate(15);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'type' => $n->type,
                'link' => $n->link,
                'is_broadcast' => $n->user_id === null,
                'is_read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    public function unreadCount(Request $request): int
    {
        $user = $request->user();

        return UserNotification::query()
            ->visibleTo($user)
            ->whereNotExists(function ($q) use ($user) {
                $q->select(DB::raw(1))
                    ->from('user_notification_reads as r')
                    ->whereColumn('r.notification_id', 'user_notifications.id')
                    ->where('r.user_id', $user->id);
            })
            ->count();
    }

    public function count(Request $request)
    {
        return response()->json(['unread_count' => $this->unreadCount($request)]);
    }

    public function markRead(Request $request, UserNotification $notification)
    {
        $user = $request->user();

        $visible = UserNotification::query()->visibleTo($user)->whereKey($notification->id)->exists();

        abort_unless($visible, 404);

        DB::table('user_notification_reads')->insertOrIgnore([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        return response()->json(['unread_count' => $this->unreadCount($request)]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();

        $ids = UserNotification::query()
            ->visibleTo($user)
            ->whereNotExists(function ($q) use ($user) {
                $q->select(DB::raw(1))
                    ->from('user_notification_reads as r')
                    ->whereColumn('r.notification_id', 'user_notifications.id')
                    ->where('r.user_id', $user->id);
            })
            ->pluck('user_notifications.id');

        $rows = $ids->map(fn ($id) => [
            'notification_id' => $id,
            'user_id' => $user->id,
            'read_at' => now(),
        ])->all();

        if ($rows) {
            DB::table('user_notification_reads')->insertOrIgnore($rows);
        }

        return response()->json(['unread_count' => 0]);
    }
}
