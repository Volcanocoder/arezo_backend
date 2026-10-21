<?php

use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\Admin\WishController;
use App\Http\Controllers\Api\Admin\WishDocumentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Admin\BlogImageController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\GatewayController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\ContactMessageController;
use App\Http\Controllers\Api\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\Admin\PostController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\WishSubmissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\DashboardStatsController;

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'API آرزو فعال است',
    ]);
});

// ثبت‌نام و ورود
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // بازیابی رمز با کد پیامکی
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:5,1');

    Route::post('/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');

    // مسیرهای مخصوص کاربران واردشده
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// تنظیمات حساب؛ خارج از پیشوند auth و فقط برای کاربر واردشده
Route::middleware('auth:sanctum')
    ->prefix('account/settings')
    ->group(function () {
        Route::get('/', [AccountSettingsController::class, 'show']);

        Route::patch('/profile', [
            AccountSettingsController::class,
            'updateProfile',
        ]);

        Route::patch('/preferences', [
            AccountSettingsController::class,
            'updatePreferences',
        ]);

        Route::post('/avatar', [AccountSettingsController::class, 'updateAvatar'])
            ->middleware('throttle:10,1');
        Route::delete('/avatar', [AccountSettingsController::class, 'deleteAvatar']);

        Route::put('/password', [
            AccountSettingsController::class,
            'changePassword',
        ])->middleware('throttle:5,1');
    });

// مسیرهای مدیریت؛ فقط مدیر و مدیر ارشد دسترسی دارند
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])
    ->prefix('admin')
    ->group(function () {
        // فهرست درخواست‌ها
        Route::get('/wishes', [WishController::class, 'index']);

        // جزئیات یک پرونده
        Route::get('/wishes/{wish}', [WishController::class, 'show']);

        // تغییر وضعیت پرونده
        Route::patch(
            '/wishes/{wish}/status',
            [WishController::class, 'updateStatus']
        );

        // دریافت امن مدارک خصوصی پرونده
        Route::get(
            '/wishes/{wish}/documents/{documentType}',
            [WishDocumentController::class, 'show']
        );
        Route::get('/dashboard/stats', [DashboardStatsController::class, 'index']);

        // مدیریت وبلاگ
        Route::get('/blog/posts', [PostController::class, 'index']);
        Route::post('/blog/posts', [PostController::class, 'store']);
        Route::post('/blog/preview', [PostController::class, 'preview']);
        Route::get('/blog/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
        Route::put('/blog/posts/{post}', [PostController::class, 'update'])->whereNumber('post');
        Route::delete('/blog/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');


        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/export', [UserController::class, 'export']);
        Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
        Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->whereNumber('user');
        Route::post('/users/{user}/unsuspend', [UserController::class, 'unsuspend'])->whereNumber('user');

        // دسته‌بندی‌ها
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::put('/categories/reorder', [AdminCategoryController::class, 'reorder']);
        Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->whereNumber('category');

        // اعلان‌ها و اطلاعیه‌ها
        Route::get('/notifications', [AdminNotificationController::class, 'index']);
        Route::post('/notifications', [AdminNotificationController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('/notifications/{notification}', [AdminNotificationController::class, 'destroy'])->whereNumber('notification');

        // آپلود تصویر وبلاگ
        Route::post('/blog/images', [BlogImageController::class, 'store'])->middleware('throttle:30,1');
        // گزارش تراکنش‌ها
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/export', [TransactionController::class, 'export']);

        // پیام‌های فرم تماس
        Route::get('/contact-messages', [ContactMessageController::class, 'index']);
        Route::post('/contact-messages/{message}/read', [ContactMessageController::class, 'markRead'])->whereNumber('message');
        Route::delete('/contact-messages/{message}', [ContactMessageController::class, 'destroy'])->whereNumber('message');

        // درگاه‌های پرداخت؛ فقط مدیر ارشد
        Route::middleware('role:super_admin')->prefix('gateways')->group(function () {
            Route::get('/', [GatewayController::class, 'index']);
            Route::post('/', [GatewayController::class, 'store']);
            Route::put('/{gateway}', [GatewayController::class, 'update'])->whereNumber('gateway');
            Route::delete('/{gateway}', [GatewayController::class, 'destroy'])->whereNumber('gateway');
        });

        Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });

// فرم تماس با ما
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1');

// پرداخت آنلاین
Route::get('/payments/gateways', [PaymentController::class, 'gateways']);
Route::post('/wishes/{wish}/donate', [PaymentController::class, 'start'])
    ->whereNumber('wish')->middleware('throttle:20,1');
Route::get('/payments/callback', [PaymentController::class, 'callback']);

// دسته‌بندی‌های فعال (آرزو یا وبلاگ)
Route::get('/categories', [CategoryController::class, 'index']);

// تصویرهای وبلاگ
Route::get('/media/blog/{file}', [MediaController::class, 'blog']);
Route::get('/media/avatar/{file}', [MediaController::class, 'avatar']);

// اعلان‌های کاربر واردشده
Route::middleware('auth:sanctum')
    ->prefix('notifications')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'count']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->whereNumber('notification');
    });

// وبلاگ عمومی؛ فقط مقاله‌های منتشرشده
Route::get('/blog/posts', [BlogController::class, 'index']);
Route::get('/blog/sitemap', [BlogController::class, 'sitemap']);
Route::get('/blog/posts/{slug}', [BlogController::class, 'show']);

// فهرست عمومی آرزوهای تأییدشده؛ بدون افشای اطلاعات خصوصی متقاضی
Route::get('/wishes', function () {
    return \App\Models\Wish::query()
        ->where('status', 'approved')
        ->select([
            'id',
            'title',
            'description',
            'story',
            'category',
            'city',
            'requested_amount',
            'wish_image_path',
            'urgency',
            'created_at',
        ])
        ->latest()
        ->get()
        ->map(function ($wish) {
            return [
                'id' => $wish->id,
                'title' => $wish->title,
                'description' => $wish->description,
                'story' => $wish->story,
                'category' => $wish->category,
                'city' => $wish->city,
                'urgency' => $wish->urgency,
                'requested_amount' => $wish->requested_amount,
                'created_at' => $wish->created_at,
                'image_url' => $wish->wish_image_path
                    ? url('/api/wishes/' . $wish->id . '/image')
                    : null,
            ];
        });
});

// آرزوهای مهم و فوری برای بخش ویژه صفحه اصلی؛ فقط آرزوهای تأییدشده
// این مسیر باید قبل از مسیر پویای /wishes/{wish} تعریف شود.
Route::get('/wishes/priority', function () {
    return \App\Models\Wish::query()
        ->where('status', 'approved')
        ->whereIn('urgency', ['urgent', 'important'])
        ->select([
            'id',
            'title',
            'description',
            'story',
            'category',
            'city',
            'requested_amount',
            'wish_image_path',
            'urgency',
            'created_at',
        ])
        ->orderByRaw("CASE urgency WHEN 'urgent' THEN 0 WHEN 'important' THEN 1 ELSE 2 END")
        ->latest()
        ->limit(3)
        ->get()
        ->map(function ($wish) {
            return [
                'id' => $wish->id,
                'title' => $wish->title,
                'description' => $wish->description,
                'story' => $wish->story,
                'category' => $wish->category,
                'city' => $wish->city,
                'requested_amount' => $wish->requested_amount,
                'urgency' => $wish->urgency,
                'created_at' => $wish->created_at,
                'image_url' => $wish->wish_image_path
                    ? url('/api/wishes/' . $wish->id . '/image')
                    : null,
            ];
        });
});

// جزئیات عمومی یک آرزو؛ فقط برای آرزوهای تأییدشده
Route::get('/wishes/{wish}', function (\App\Models\Wish $wish) {
    abort_unless($wish->status === 'approved', 404);

    $wish->loadSum([
        'donations as funded_amount' => function ($query) {
            $query->where('status', 'paid');
        },
    ], 'amount');

    $wish->loadCount([
        'donations as supporters_count' => function ($query) {
            $query->where('status', 'paid');
        },
    ]);

    return response()->json([
        'id' => $wish->id,
        'title' => $wish->title,
        'description' => $wish->description,
        'story' => $wish->story,
        'category' => $wish->category,
        'city' => $wish->city,
        'requested_amount' => (int) $wish->requested_amount,
        'funded_amount' => (int) ($wish->funded_amount ?? 0),
        'supporters_count' => (int) ($wish->supporters_count ?? 0),
        'created_at' => $wish->created_at,
        'image_url' => $wish->wish_image_path
            ? url('/api/wishes/' . $wish->id . '/image')
            : null,
    ]);
})->whereNumber('wish');

// تصویر آرزوی تأییدشده؛ فقط فایل تصویری، نه مدارک خصوصی
Route::get('/wishes/{wish}/image', function (\App\Models\Wish $wish) {
    abort_unless($wish->status === 'approved', 404);
    abort_unless($wish->wish_image_path, 404);

    $disk = Storage::disk('local');
    abort_unless($disk->exists($wish->wish_image_path), 404);

    $mimeType = $disk->mimeType($wish->wish_image_path);
    abort_unless(
        in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true),
        404
    );

    return response()->file($disk->path($wish->wish_image_path), [
        'Content-Type' => $mimeType,
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->whereNumber('wish');

// ثبت آرزوی جدید؛ نیازمند ورود کاربر
Route::post('/wishes', [WishSubmissionController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:3,1']);

// آرزوهای کاربر واردشده
Route::middleware('auth:sanctum')
    ->prefix('my')
    ->group(function () {
        Route::get('/wishes', function (Request $request) {
            return \App\Models\Wish::query()
                ->where('user_id', $request->user()->id)
                ->select([
                    'id',
                    'title',
                    'description',
                    'category',
                    'city',
                    'requested_amount',
                    'status',
                    'review_note',
                    'created_at',
                    'updated_at',
                ])
                ->latest()
                ->paginate(10);
        });
    });

// مسیرهای گفتگو؛ فقط کاربران واردشده
Route::middleware('auth:sanctum')
    ->prefix('conversations')
    ->group(function () {
        // فهرست گفتگوهای کاربر
        Route::get('/', [ConversationController::class, 'index']);

        // ایجاد گفتگوی جدید یا یافتن گفتگوی قبلی
        Route::post('/', [ConversationController::class, 'store'])
            ->middleware('throttle:10,1');

        // دریافت پیام‌های یک گفتگو
        Route::get(
            '/{conversation}/messages',
            [MessageController::class, 'index']
        )->whereNumber('conversation');

        // ارسال پیام در یک گفتگو
        Route::post(
            '/{conversation}/messages',
            [MessageController::class, 'store']
        )
            ->whereNumber('conversation')
            ->middleware('throttle:30,1');

        // جزئیات گفتگو
        Route::get(
            '/{conversation}',
            [ConversationController::class, 'show']
        )->whereNumber('conversation');
    });


 // فهرست کمک‌های مالی کاربر واردشده
 Route::middleware('auth:sanctum')
 ->prefix('my')
 ->group(function () {
     Route::get('/donations', function (Request $request) {
         return \App\Models\Donation::query()
             ->where('user_id', $request->user()->id)
             ->with(['wish' => function ($query) {
                 $query
                     ->select([
                         'id',
                         'title',
                         'category',
                         'city',
                         'requested_amount',
                         'status',
                         'created_at',
                     ])
                     ->withSum([
                         'donations as funded_amount' => function ($query) {
                             $query->where('status', 'paid');
                         },
                     ], 'amount')
                     ->withCount([
                         'donations as supporters_count' => function ($query) {
                             $query->where('status', 'paid');
                         },
                     ]);
             }])
             ->latest()
             ->paginate(20);
     });
 });
