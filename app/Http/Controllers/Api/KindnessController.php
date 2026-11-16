<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KindnessItem;
use App\Models\KindnessRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class KindnessController extends Controller
{
    /** آگهی‌های تأییدشده (و رزروشده) برای همه؛ اطلاعات آگهی‌دهنده نمایش داده نمی‌شود. */
    public function index(Request $request)
    {
        $query = KindnessItem::query()->whereIn('status', ['approved', 'reserved'])->latest('id');

        if ($q = trim((string) $request->query('q'))) {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%');
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($city = $request->query('city')) {
            $query->where('city', $city);
        }

        $page = $query->paginate(12)->withQueryString();
        $page->getCollection()->transform(fn (KindnessItem $i) => $this->publicPayload($i));

        return response()->json($page->toArray() + [
            'categories' => KindnessItem::CATEGORIES,
            'conditions' => KindnessItem::CONDITIONS,
        ]);
    }

    public function show(KindnessItem $item)
    {
        abort_unless(in_array($item->status, ['approved', 'reserved'], true), 404);

        return response()->json(['data' => $this->publicPayload($item)]);
    }

    public function image(KindnessItem $item)
    {
        abort_unless(in_array($item->status, ['approved', 'reserved'], true), 404);

        return $this->imageResponse($item);
    }

    /** ثبت آگهی؛ پس از تأیید مدیر نمایش داده می‌شود. */
    public function store(Request $request)
    {
        $data = $request->validate(self::rules());

        $path = $request->hasFile('image')
            ? $request->file('image')->store('kindness/images', 'local')
            : null;

        $item = KindnessItem::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'category' => $data['category'],
            'description' => $data['description'],
            'condition' => $data['condition'],
            'city' => $data['city'],
            'image_path' => $path,
            'status' => 'pending',
        ]);

        KindnessItem::notifyAdmins(
            'آگهی تازه در دیوار مهربانی',
            "آگهی «{$item->title}» در انتظار بررسی است.",
            "/admin/kindness/{$item->id}",
        );

        return response()->json([
            'message' => 'آگهی ثبت شد و پس از تأیید مدیر نمایش داده می‌شود.',
            'data' => $this->ownerPayload($item),
        ], 201);
    }

    /** درخواست یک کالا؛ پس از تأیید مدیر، مدیر شماره‌ها را هماهنگ می‌کند. */
    public function requestItem(Request $request, KindnessItem $item)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        abort_unless($item->status === 'approved', 404);

        if ($item->user_id === $request->user()->id) {
            return response()->json(['message' => 'نمی‌توانی کالای خودت را درخواست کنی.'], 403);
        }

        if (KindnessRequest::query()->where('kindness_item_id', $item->id)->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'قبلاً برای این کالا درخواست داده‌ای.'], 422);
        }

        $req = KindnessRequest::create([
            'kindness_item_id' => $item->id,
            'user_id' => $request->user()->id,
            'message' => $data['message'],
            'status' => 'pending',
        ]);

        KindnessItem::notifyAdmins(
            'درخواست تازه در دیوار مهربانی',
            "برای «{$item->title}» درخواست تازه‌ای ثبت شد.",
            '/admin/kindness?tab=requests',
        );

        return response()->json([
            'message' => 'درخواستت ثبت شد و پس از بررسی مدیر خبرت می‌کنیم.',
            'data' => ['id' => $req->id, 'status' => $req->status],
        ], 201);
    }

    /** قوانین مشترک ثبت و ویرایش آگهی. */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(KindnessItem::CATEGORIES)],
            'description' => ['required', 'string', 'max:1500'],
            'condition' => ['required', Rule::in(array_keys(KindnessItem::CONDITIONS))],
            'city' => ['required', 'string', 'max:120'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /** اعتبارسنجی و اعمال ویرایش فیلدها و تصویر (بدون تغییر وضعیت)؛ مشترک بین کاربر و مدیر. */
    public static function applyEdit(Request $request, KindnessItem $item): void
    {
        $data = $request->validate(self::rules());
        $disk = Storage::disk('local');
        $path = $item->image_path;

        if ($request->hasFile('image')) {
            $new = $request->file('image')->store('kindness/images', 'local');
            if ($path) {
                $disk->delete($path);
            }
            $path = $new;
        } elseif ($request->boolean('remove_image') && $path) {
            $disk->delete($path);
            $path = null;
        }

        $item->fill([
            'title' => $data['title'],
            'category' => $data['category'],
            'description' => $data['description'],
            'condition' => $data['condition'],
            'city' => $data['city'],
            'image_path' => $path,
        ]);
    }

    /** نمایش آگهی خود کاربر (برای فرم ویرایش). */
    public function myItem(Request $request, KindnessItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->ownerPayload($item)]);
    }

    public function myItemImage(Request $request, KindnessItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);

        return self::imageResponse($item);
    }

    /** ویرایش آگهی توسط صاحبش؛ آگهی دوباره برای بررسی مدیر می‌رود. */
    public function update(Request $request, KindnessItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);

        if (! in_array($item->status, ['pending', 'approved', 'rejected'], true)) {
            return response()->json(['message' => 'آگهی رزروشده یا تحویل‌شده قابل ویرایش نیست.'], 422);
        }

        self::applyEdit($request, $item);
        $item->fill(['status' => 'pending', 'review_note' => null, 'reviewed_at' => null])->save();

        KindnessItem::notifyAdmins(
            'آگهی ویرایش شد',
            "آگهی «{$item->title}» ویرایش شد و دوباره در انتظار بررسی است.",
            "/admin/kindness/{$item->id}",
        );

        return response()->json([
            'message' => 'تغییرات ثبت شد و آگهی دوباره پس از تأیید مدیر نمایش داده می‌شود.',
            'data' => $this->ownerPayload($item),
        ]);
    }

    public function myItems(Request $request)
    {
        $page = KindnessItem::query()
            ->where('user_id', $request->user()->id)
            ->withCount('requests')
            ->latest('id')
            ->paginate(10);

        $page->getCollection()->transform(fn (KindnessItem $i) => $this->ownerPayload($i) + [
            'requests_count' => $i->requests_count,
        ]);

        return response()->json($page);
    }

    public function myRequests(Request $request)
    {
        $page = KindnessRequest::query()
            ->where('user_id', $request->user()->id)
            ->with('item')
            ->latest('id')
            ->paginate(10);

        $page->getCollection()->transform(fn (KindnessRequest $r) => [
            'id' => $r->id,
            'message' => $r->message,
            'status' => $r->status,
            'review_note' => $r->review_note,
            'created_at' => $r->created_at,
            'item' => $r->item ? ['id' => $r->item->id, 'title' => $r->item->title] : null,
        ]);

        return response()->json($page);
    }

    private function publicPayload(KindnessItem $i): array
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
            'image_url' => $i->image_path ? url('/api/kindness/'.$i->id.'/image') : null,
            'created_at' => $i->created_at,
        ];
    }

    private function ownerPayload(KindnessItem $i): array
    {
        return array_merge($this->publicPayload($i), [
            // تصویر آگهی در انتظار/ردشده فقط برای صاحبش در دسترس است.
            'image_url' => $i->image_path ? url('/api/my/kindness/items/'.$i->id.'/image') : null,
            'review_note' => $i->review_note,
        ]);
    }

    /** پاسخ فایل تصویر آگهی (فقط تصویر). */
    public static function imageResponse(KindnessItem $item)
    {
        abort_unless($item->image_path, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($item->image_path), 404);

        $mime = $disk->mimeType($item->image_path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($disk->path($item->image_path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
