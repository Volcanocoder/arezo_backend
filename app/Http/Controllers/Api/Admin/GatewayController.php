<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** مدیریت درگاه‌های پرداخت؛ فقط مدیر ارشد. */
class GatewayController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PaymentGateway::query()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn ($g) => $this->present($g))
                ->values(),
            'drivers' => $this->allowedDrivers(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $gateway = DB::transaction(function () use ($data) {
            if (! empty($data['is_default'])) {
                PaymentGateway::query()->update(['is_default' => false]);
            }

            return PaymentGateway::create($data);
        });

        $this->ensureDefault();

        return response()->json(['data' => $this->present($gateway->fresh())], 201);
    }

    public function update(Request $request, PaymentGateway $gateway)
    {
        $data = $this->validated($request, $gateway);

        // خالی‌گذاشتن مرچنت‌کد یعنی بدون تغییر.
        if (empty($data['merchant_id'])) {
            unset($data['merchant_id']);
        }

        DB::transaction(function () use ($gateway, $data) {
            if (! empty($data['is_default'])) {
                PaymentGateway::query()->whereKeyNot($gateway->id)->update(['is_default' => false]);
            }

            $gateway->update($data);
        });

        $this->ensureDefault();

        return response()->json(['data' => $this->present($gateway->fresh())]);
    }

    public function destroy(PaymentGateway $gateway)
    {
        $gateway->delete();
        $this->ensureDefault();

        return response()->json(['message' => 'درگاه حذف شد.']);
    }

    private function allowedDrivers(): array
    {
        return app()->isProduction() ? ['zarinpal'] : ['zarinpal', 'test'];
    }

    private function validated(Request $request, ?PaymentGateway $gateway): array
    {
        $data = $request->validate([
            'driver' => [$gateway ? 'sometimes' : 'required', Rule::in($this->allowedDrivers())],
            'title' => ['required', 'string', 'max:100'],
            'merchant_id' => ['nullable', 'string', 'max:100'],
            'is_sandbox' => ['boolean'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $driver = $data['driver'] ?? $gateway?->driver;

        if ($driver === 'zarinpal') {
            $hasMerchant = ! empty($data['merchant_id']) || ($gateway && $gateway->merchant_id);

            if (! $hasMerchant) {
                abort(response()->json([
                    'message' => 'مرچنت‌کد را وارد کن.',
                    'errors' => ['merchant_id' => ['مرچنت‌کد را وارد کن.']],
                ], 422));
            }

            if (! empty($data['merchant_id']) && ! preg_match('/^[A-Za-z0-9-]{16,64}$/', $data['merchant_id'])) {
                abort(response()->json([
                    'message' => 'قالب مرچنت‌کد معتبر نیست.',
                    'errors' => ['merchant_id' => ['قالب مرچنت‌کد معتبر نیست (حداقل ۱۶ نویسه انگلیسی/عدد).']],
                ], 422));
            }
        }

        // درگاه غیرفعال نمی‌تواند پیش‌فرض باشد.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $data['is_default'] = false;
        }

        return $data;
    }

    /** همیشه یک درگاه فعال پیش‌فرض داشته باش (اگر درگاه فعالی هست). */
    private function ensureDefault(): void
    {
        if (PaymentGateway::query()->active()->where('is_default', true)->exists()) {
            return;
        }

        $first = PaymentGateway::query()->active()->orderBy('id')->first();
        $first?->forceFill(['is_default' => true])->save();
    }

    private function present(PaymentGateway $g): array
    {
        return [
            'id' => $g->id,
            'driver' => $g->driver,
            'title' => $g->title,
            'merchant_masked' => $g->maskedMerchant(),
            'has_merchant' => (bool) $g->merchant_id,
            'is_sandbox' => $g->is_sandbox,
            'is_active' => $g->is_active,
            'is_default' => $g->is_default,
            'description' => $g->description,
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }
}
