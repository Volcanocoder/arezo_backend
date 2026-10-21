<?php

namespace App\Services\Payment;

use App\Models\Donation;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;

class ZarinpalDriver implements PaymentDriver
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    private function host(): string
    {
        return $this->gateway->is_sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    public function request(Donation $donation, string $callbackUrl, string $description): array
    {
        $response = Http::acceptJson()->timeout(15)->post($this->host().'/pg/v4/payment/request.json', [
            'merchant_id' => $this->gateway->merchant_id,
            'amount' => $donation->amount,
            'currency' => 'IRT',
            'callback_url' => $callbackUrl,
            'description' => $description,
        ]);

        $authority = $response->json('data.authority');

        if (! $response->ok() || ! $authority) {
            report(new \RuntimeException('Zarinpal request failed: '.$response->body()));
            throw new PaymentException('اتصال به درگاه برقرار نشد. کمی بعد دوباره تلاش کن.');
        }

        return [
            'authority' => $authority,
            'url' => $this->host().'/pg/StartPay/'.$authority,
        ];
    }

    public function verify(Donation $donation, string $authority): string
    {
        $response = Http::acceptJson()->timeout(15)->post($this->host().'/pg/v4/payment/verify.json', [
            'merchant_id' => $this->gateway->merchant_id,
            'amount' => $donation->amount,
            'authority' => $authority,
        ]);

        $code = (int) $response->json('data.code');

        // ۱۰۰ = موفق، ۱۰۱ = قبلاً تأیید شده
        if (! in_array($code, [100, 101], true)) {
            throw new PaymentException('پرداخت توسط درگاه تأیید نشد.');
        }

        return (string) $response->json('data.ref_id');
    }
}
