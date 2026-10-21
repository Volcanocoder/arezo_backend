<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';

        $query = ContactMessage::query()->latest('id');

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        $page = $query->paginate(15)->withQueryString();

        return response()->json($page->toArray() + [
            'unread_count' => ContactMessage::query()->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(ContactMessage $message)
    {
        $message->forceFill(['read_at' => $message->read_at ?? now()])->save();

        return response()->json(['message' => 'خوانده شد.']);
    }

    public function destroy(ContactMessage $message)
    {
        $message->delete();

        return response()->json(['message' => 'حذف شد.']);
    }
}
