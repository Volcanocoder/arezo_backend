<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'user'): User
    {
        $this->n++;
        $u = User::create([
            'name' => 'کاربر '.$this->n,
            'email' => "p{$this->n}@example.com",
            'mobile' => '0913000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'password' => 'password-123',
        ]);
        $u->forceFill(['role' => $role])->save();

        return $u;
    }

    private function wish(User $owner, string $status = 'approved'): Wish
    {
        return Wish::create([
            'title' => 'آرزوی پرداخت', 'category' => 'تحصیلی', 'description' => 'd', 'story' => 's',
            'city' => 'تهران', 'requested_amount' => 100000, 'urgency' => 'normal',
            'applicant_name' => 'م', 'applicant_mobile' => '09121234567', 'applicant_relation' => 'خودم',
            'user_id' => $owner->id, 'status' => $status,
        ]);
    }

    private const MERCHANT = '1344b5d4-0048-11e8-94db-005056a205be';

    public function test_only_super_admin_can_manage_gateways(): void
    {
        $this->actingAs($this->user('admin'))->getJson('/api/admin/gateways')->assertForbidden();
        $this->actingAs($this->user('user'))->getJson('/api/admin/gateways')->assertForbidden();
        $this->actingAs($this->user('super_admin'))->getJson('/api/admin/gateways')->assertOk();
    }

    public function test_merchant_is_encrypted_and_never_exposed(): void
    {
        $super = $this->user('super_admin');

        $res = $this->actingAs($super)->postJson('/api/admin/gateways', [
            'driver' => 'zarinpal', 'title' => 'زرین‌پال', 'merchant_id' => self::MERCHANT, 'is_sandbox' => true,
        ])->assertCreated();

        $this->assertStringNotContainsString(self::MERCHANT, $res->getContent());
        $this->assertTrue($res->json('data.is_default'));
        $this->assertNotSame(self::MERCHANT, \DB::table('payment_gateways')->value('merchant_id'));
        $this->assertSame(self::MERCHANT, PaymentGateway::first()->merchant_id);

        $this->getJson('/api/payments/gateways')->assertOk()->assertJsonMissing(['merchant_id' => self::MERCHANT]);
    }

    public function test_update_without_merchant_keeps_old_one_and_validates_format(): void
    {
        $super = $this->user('super_admin');
        $g = PaymentGateway::create(['driver' => 'zarinpal', 'title' => 'a', 'merchant_id' => self::MERCHANT, 'is_default' => true]);

        $this->actingAs($super)->putJson("/api/admin/gateways/{$g->id}", ['title' => 'جدید', 'merchant_id' => ''])->assertOk();
        $this->assertSame(self::MERCHANT, $g->fresh()->merchant_id);

        $this->actingAs($super)->putJson("/api/admin/gateways/{$g->id}", ['title' => 'x', 'merchant_id' => 'short'])->assertStatus(422);
        $this->actingAs($super)->postJson('/api/admin/gateways', ['driver' => 'zarinpal', 'title' => 'x'])->assertStatus(422);
    }

    public function test_only_one_default_gateway(): void
    {
        $super = $this->user('super_admin');
        $a = PaymentGateway::create(['driver' => 'test', 'title' => 'a', 'is_default' => true]);

        $this->actingAs($super)->postJson('/api/admin/gateways', [
            'driver' => 'zarinpal', 'title' => 'b', 'merchant_id' => self::MERCHANT, 'is_default' => true,
        ])->assertCreated();

        $this->assertSame(1, PaymentGateway::where('is_default', true)->count());
        $this->assertFalse($a->fresh()->is_default);
    }

    public function test_donate_requires_active_gateway(): void
    {
        $w = $this->wish($this->user());

        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 5000])->assertStatus(503);
    }

    public function test_full_payment_flow_with_test_gateway(): void
    {
        $owner = $this->user();
        $w = $this->wish($owner);
        PaymentGateway::create(['driver' => 'test', 'title' => 't', 'is_default' => true]);

        $url = $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 40000])->assertOk()->json('payment_url');
        $this->assertStringContainsString('/api/payments/callback', $url);

        $d = Donation::first();
        $this->assertSame('pending', $d->status);

        $this->get($url)->assertRedirectContains('/payment/result?status=success');
        $this->assertSame('paid', $d->fresh()->status);
        $this->assertNotNull($d->fresh()->gateway_reference);

        // تأیید دوباره تکرار نمی‌شود و اعلان دوباره نمی‌رود
        $this->get($url)->assertRedirectContains('status=success');
        $this->assertSame(1, \App\Models\UserNotification::where('user_id', $owner->id)->count());

        $this->getJson("/api/wishes/{$w->id}")->assertJsonPath('funded_amount', 40000)->assertJsonPath('supporters_count', 1);
    }

    public function test_cancelled_payment_and_amount_limits(): void
    {
        $w = $this->wish($this->user());
        PaymentGateway::create(['driver' => 'test', 'title' => 't', 'is_default' => true]);

        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 500])->assertStatus(422);
        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 200000])->assertStatus(422);

        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 10000])->assertOk();
        $auth = Donation::first()->gateway_authority;

        $this->get("/api/payments/callback?Authority={$auth}&Status=NOK")->assertRedirectContains('status=cancelled');
        $this->assertSame('cancelled', Donation::first()->status);
    }

    public function test_cannot_donate_to_unapproved_wish(): void
    {
        $w = $this->wish($this->user(), 'pending');
        PaymentGateway::create(['driver' => 'test', 'title' => 't']);

        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 5000])->assertNotFound();
    }

    public function test_zarinpal_driver_request_and_verify(): void
    {
        $w = $this->wish($this->user());
        PaymentGateway::create(['driver' => 'zarinpal', 'title' => 'z', 'merchant_id' => self::MERCHANT, 'is_sandbox' => true, 'is_default' => true]);

        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000012345']]),
            'sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 987654]]),
        ]);

        $url = $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 20000])->assertOk()->json('payment_url');
        $this->assertSame('https://sandbox.zarinpal.com/pg/StartPay/A0000000000000000000000000000012345', $url);

        $this->get('/api/payments/callback?Authority=A0000000000000000000000000000012345&Status=OK')
            ->assertRedirectContains('status=success');
        $this->assertSame('987654', Donation::first()->gateway_reference);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'request.json') && $r['merchant_id'] === self::MERCHANT && $r['amount'] === 20000);
    }

    public function test_failed_verification_marks_failed(): void
    {
        $w = $this->wish($this->user());
        PaymentGateway::create(['driver' => 'zarinpal', 'title' => 'z', 'merchant_id' => self::MERCHANT, 'is_default' => true]);

        Http::fake([
            '*request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH1']]),
            '*verify.json' => Http::response(['data' => [], 'errors' => ['code' => -51]]),
        ]);

        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 20000])->assertOk();
        $this->get('/api/payments/callback?Authority=AUTH1&Status=OK')->assertRedirectContains('status=failed');
        $this->assertSame('failed', Donation::first()->status);
    }

    public function test_verify_uses_the_gateway_the_payment_started_with(): void
    {
        $w = $this->wish($this->user());
        $first = PaymentGateway::create(['driver' => 'zarinpal', 'title' => 'a', 'merchant_id' => self::MERCHANT, 'is_default' => true]);
        $second = PaymentGateway::create(['driver' => 'zarinpal', 'title' => 'b', 'merchant_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']);

        Http::fake([
            '*request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTHX']]),
            '*verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 1]]),
        ]);

        $this->postJson("/api/wishes/{$w->id}/donate", ['amount' => 20000, 'gateway_id' => $second->id])->assertOk();
        $first->update(['is_default' => true]);
        $this->get('/api/payments/callback?Authority=AUTHX&Status=OK')->assertRedirectContains('status=success');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'verify.json') && $r['merchant_id'] === 'ffffffff-ffff-ffff-ffff-ffffffffffff');
    }
}
