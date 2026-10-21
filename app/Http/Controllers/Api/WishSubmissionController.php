<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wish;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class WishSubmissionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
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
        ]);

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
}
