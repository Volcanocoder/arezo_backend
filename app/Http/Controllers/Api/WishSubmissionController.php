<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wish;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class WishSubmissionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $storedFiles = [];

        try {
            if ($request->hasFile('wish_image')) {
                $storedFiles['wish_image_path'] =
                    $request->file('wish_image')
                        ->store('wish-submissions/images', 'local');
            }

            if ($request->hasFile('identity_document')) {
                $storedFiles['identity_document_path'] =
                    $request->file('identity_document')
                        ->store('wish-submissions/identity', 'local');
            }

            if ($request->hasFile('evidence_document')) {
                $storedFiles['evidence_document_path'] =
                    $request->file('evidence_document')
                        ->store('wish-submissions/evidence', 'local');
            }

            $urgencyMap = [
                'عادی' => 'normal',
                'مهم' => 'important',
                'فوری' => 'urgent',
            ];

            $wish = Wish::create([
                'title' => $data['title'],
                'category' => $data['category'],
                'description' => $data['description'],
                'story' => $data['story'],
                'city' => $data['city'],
                'requested_amount' => $data['requested_amount'],
                'urgency' => $urgencyMap[$data['urgency']],

                'applicant_name' => $data['applicant_name'],
                'applicant_mobile' => $data['applicant_mobile'],
                'applicant_email' => $data['applicant_email'] ?? null,
                'applicant_relation' => $data['applicant_relation'],

                'user_id' => $request->user()->id,

                ...$storedFiles,

                // وضعیت فقط توسط سرور تعیین می‌شود.
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'آرزویت با موفقیت ثبت شد و در انتظار بررسی است.',
                'wish' => [
                    'id' => $wish->id,
                    'title' => $wish->title,
                    'status' => $wish->status,
                    'created_at' => $wish->created_at,
                ],
            ], 201);
        } catch (Throwable $exception) {
            // اگر ذخیره پرونده ناموفق باشد، فایل‌های ذخیره‌شده پاک می‌شوند.
            Storage::disk('local')->delete(
                array_values($storedFiles)
            );

            throw $exception;
        }
    }

    /** قواعد مشترک ثبت و ویرایش آرزو. */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],

            'category' => [
                'required',
                Rule::exists('categories', 'name')
                    ->where('type', 'wish')
                    ->where('is_active', true),
            ],

            'description' => ['required', 'string', 'max:500'],
            'story' => ['required', 'string', 'max:3000'],
            'city' => ['required', 'string', 'max:120'],

            'requested_amount' => [
                'required',
                'integer',
                'min:1',
                'max:9999999999999',
            ],

            'urgency' => [
                'required',
                Rule::in(['عادی', 'مهم', 'فوری']),
            ],

            'applicant_name' => [
                'required',
                'string',
                'max:150',
            ],

            'applicant_mobile' => [
                'required',
                'string',
                'regex:/^09\d{9}$/',
            ],

            'applicant_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'applicant_relation' => [
                'required',
                Rule::in([
                    'خودم',
                    'عضو خانواده',
                    'شخص دیگری با اطلاع او',
                ]),
            ],

            'wish_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
            ],

            'identity_document' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:5120',
            ],

            'evidence_document' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    private const URGENCY_MAP = [
        'عادی' => 'normal',
        'مهم' => 'important',
        'فوری' => 'urgent',
    ];

    /** مقادیر فعلی آرزو برای پر کردن فرم ویرایش (فقط صاحب، فقط در وضعیت needs_info). */
    public function edit(Request $request, Wish $wish)
    {
        $this->authorizeEdit($request, $wish);

        return response()->json([
            'wish' => [
                'id' => $wish->id,
                'title' => $wish->title,
                'category' => $wish->category,
                'description' => $wish->description,
                'story' => $wish->story,
                'city' => $wish->city,
                'requested_amount' => $wish->requested_amount,
                'urgency' => array_search($wish->urgency, self::URGENCY_MAP, true) ?: 'عادی',
                'applicant_name' => $wish->applicant_name,
                'applicant_mobile' => $wish->applicant_mobile,
                'applicant_email' => $wish->applicant_email,
                'applicant_relation' => $wish->applicant_relation,
                'review_note' => $wish->review_note,
                'has_image' => (bool) $wish->wish_image_path,
                'has_identity_document' => (bool) $wish->identity_document_path,
                'has_evidence_document' => (bool) $wish->evidence_document_path,
            ],
        ]);
    }

    /** ویرایش آرزو توسط صاحبش؛ پس از ذخیره دوباره در صف بررسی می‌رود. */
    public function update(Request $request, Wish $wish)
    {
        $this->authorizeEdit($request, $wish);

        $data = $request->validate($this->rules());

        $fileFields = [
            'wish_image' => ['wish_image_path', 'wish-submissions/images'],
            'identity_document' => ['identity_document_path', 'wish-submissions/identity'],
            'evidence_document' => ['evidence_document_path', 'wish-submissions/evidence'],
        ];

        $newFiles = [];
        $oldFiles = [];

        try {
            // فایل جدید جایگزین قبلی می‌شود؛ بدون فایل جدید، فایل قبلی می‌ماند.
            foreach ($fileFields as $input => [$column, $dir]) {
                if ($request->hasFile($input)) {
                    $newFiles[$column] = $request->file($input)->store($dir, 'local');
                    if ($wish->{$column}) {
                        $oldFiles[] = $wish->{$column};
                    }
                }
            }

            $wish->update([
                'title' => $data['title'],
                'category' => $data['category'],
                'description' => $data['description'],
                'story' => $data['story'],
                'city' => $data['city'],
                'requested_amount' => $data['requested_amount'],
                'urgency' => self::URGENCY_MAP[$data['urgency']],
                'applicant_name' => $data['applicant_name'],
                'applicant_mobile' => $data['applicant_mobile'],
                'applicant_email' => $data['applicant_email'] ?? null,
                'applicant_relation' => $data['applicant_relation'],
                ...$newFiles,
                // وضعیت فقط توسط سرور تعیین می‌شود: دوباره به صف بررسی.
                'status' => 'pending',
                'review_note' => null,
                'reviewed_at' => null,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($newFiles));

            throw $exception;
        }

        Storage::disk('local')->delete($oldFiles);

        // به همه مدیران خبر می‌دهیم که کاربر تغییرات خواسته‌شده را انجام داده است.
        User::query()
            ->whereIn('role', ['admin', 'super_admin'])
            ->pluck('id')
            ->each(fn (int $adminId) => UserNotification::notify(
                $adminId,
                'آرزو دوباره برای بررسی ارسال شد',
                "کاربر تغییرات خواسته‌شده برای «{$wish->title}» را انجام داد و آرزو دوباره در انتظار بررسی است.",
                'info',
                '/admin/wishes/'.$wish->id,
            ));

        return response()->json([
            'message' => 'تغییرات ذخیره شد و آرزویت دوباره در انتظار بررسی است.',
            'wish' => ['id' => $wish->id, 'status' => $wish->status],
        ]);
    }

    private function authorizeEdit(Request $request, Wish $wish): void
    {
        abort_unless($wish->user_id === $request->user()->id, 404);

        abort_unless($wish->status === 'needs_info', 403, 'این آرزو فقط وقتی «نیازمند تکمیل اطلاعات» است قابل ویرایش است.');
    }
}
