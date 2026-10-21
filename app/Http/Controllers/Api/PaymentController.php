<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\PaymentGateway;
use App\Models\UserNotification;
use App\Models\Wish;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private PaymentManager $payments)
    {
    }

    /** درگاه‌های فعال برای نمایش در فرم حمایت (بدون هیچ اطلاعات محرمانه). */
    public function gateways()
    {
        return response()->json([
            'data' => PaymentGateway::query()->active()
                ->orderByDesc('is_default')->orderBy('id')
                ->get(['id', 'driver', 'title', 'is_default', 'description']),
        ]);
    }

    public function start(Request $request, Wish $wish)
    {
        abort_unless($wish->status === 'approved', 404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:500000000'],
            'gateway_id' => ['nullable', 'integer'],
        ], [
            'amount.min' => 'حداقل مبلغ کمک ۱٬۰۰۰ تومان است.',
        ]);

        $funded = (int) $wish->donations()->where('status', 'paid')->sum('amount');
        $remaining = max(0, (int) $wish->requested_amount - $funded);

        if ($remaining <= 0) {
            return response()->json(['message' => 'مبلغ موردنیاز این آرزو تأمین شده است.'], 422);
        }

        if ($data['amount'] > $remaining) {
            return response()->json(['message' => 'مبلغ بیشتر از مبلغ باقی‌مانده است.'], 422);
        }

        $gateway = $this->payments->resolve($data['gateway_id'] ?? null);

        if (! $gateway) {
            return response()->json(['message' => 'پرداخت آنلاین فعلاً فعال نیست.'], 503);
        }

        $donation = Donation::create([
            'wish_id' => $wish->id,
            'user_id' => $request->user('sanctum')?->id,
            'amount' => $data['amount'],
            'status' => 'pending',
            'gateway' => $gateway->driver,
            'payment_gateway_id' => $gateway->id,
        ]);

        try {
            $result = $this->payments->driverFor($gateway)->request(
                $donation,
                url('/api/payments/callback'),
                'حمایت از آرزو: '.mb_substr($wish->title, 0, 60),
            );
        } catch (PaymentException $e) {
            $donation->update(['status' => 'failed']);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $donation->update(['gateway_authority' => $result['authority']]);

        return response()->json(['payment_url' => $result['url']]);
    }

    /** بازگشت مرورگر کاربر از درگاه؛ پرداخت را تأیید می‌کند و به فرانت می‌فرستد. */
    public function callback(Request $request)
    {
        $authority = (string) $request->query('Authority', '');
        $ok = $request->query('Status') === 'OK';

        $donation = $authority !== ''
            ? Donation::query()->where('gateway_authority', $authority)->first()
            : null;

        if (! $donation) {
            return $this->toFrontend('failed', null);
        }

        // تأیید دوباره‌ی یک پرداخت موفق، همان نتیجه را نشان می‌دهد.
        if ($donation->status === 'paid') {
            return $this->toFrontend('success', $donation);
        }

        if ($donation->status !== 'pending') {
            return $this->toFrontend('failed', $donation);
        }

        if (! $ok) {
            $donation->update(['status' => 'cancelled']);

            return $this->toFrontend('cancelled', $donation);
        }

        $gateway = ($donation->payment_gateway_id ? PaymentGateway::query()->find($donation->payment_gateway_id) : null)
            ?? PaymentGateway::query()->where('driver', $donation->gateway)->orderByDesc('is_default')->first();

        try {
            $ref = $this->payments->driverFor($gateway)->verify($donation, $authority);
        } catch (\Throwable $e) {
            $donation->update(['status' => 'failed']);

            return $this->toFrontend('failed', $donation);
        }

        DB::transaction(function () use ($donation, $ref) {
            $donation->update([
                'status' => 'paid',
                'gateway_reference' => $ref,
                'paid_at' => now(),
            ]);
        });

        $wish = $donation->wish;

        if ($wish && $wish->user_id) {
            UserNotification::notify(
                (int) $wish->user_id,
                'کمک جدید برای آرزوی تو',
                'مبلغ '.number_format($donation->amount).' تومان برای «'.$wish->title.'» پرداخت شد.',
                'success',
                '/dashboard/my-wishes',
            );
        }

        return $this->toFrontend('success', $donation);
    }

    private function toFrontend(string $status, ?Donation $donation)
    {
        $base = rtrim(trim(explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))[0]), '/');
        $query = http_build_query(array_filter([
            'status' => $status,
            'wish' => $donation?->wish_id,
            'ref' => $donation?->gateway_reference,
            'amount' => $donation?->amount,
        ]));

        return redirect()->away($base.'/payment/result?'.$query);
    }
}
