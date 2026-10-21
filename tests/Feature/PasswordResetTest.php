<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FakeSmsSender implements SmsSender
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $sent = [];

    public function sendOtp(string $mobile, string $code): void
    {
        $this->sent[] = [$mobile, $code];
    }
}

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsSender();
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function makeUser(string $mobile = '09123456789'): User
    {
        return User::create([
            'name' => 'تست',
            'email' => 'test'.$mobile.'@example.com',
            'mobile' => $mobile,
            'password' => 'old-password-1',
        ]);
    }

    public function test_forgot_sends_code_for_existing_mobile(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789'])
            ->assertOk();

        $this->assertCount(1, $this->sms->sent);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sms->sent[0][1]);
    }

    public function test_forgot_gives_same_answer_for_unknown_mobile_and_sends_nothing(): void
    {
        $known = $this->postJson('/api/auth/password/forgot', ['mobile' => '09120000000']);

        $this->makeUser();
        $exists = $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789']);

        $known->assertOk();
        $this->assertSame($known->json('message'), $exists->json('message'));
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_resend_cooldown_blocks_second_sms(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789'])->assertOk();
        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789'])->assertOk();

        $this->assertCount(1, $this->sms->sent);
    }

    public function test_reset_changes_password_and_code_is_single_use(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789']);
        $code = $this->sms->sent[0][1];

        $payload = [
            'mobile' => '09123456789',
            'code' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ];

        $this->postJson('/api/auth/password/reset', $payload)->assertOk();

        $this->assertTrue(password_verify('new-password-1', $user->fresh()->password));

        // استفاده دوباره از همان کد
        $this->postJson('/api/auth/password/reset', $payload)->assertStatus(422);
    }

    public function test_wrong_code_fails_and_locks_after_max_attempts(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789']);
        $code = $this->sms->sent[0][1];
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/password/reset', [
                'mobile' => '09123456789',
                'code' => $wrong,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertStatus(422);
        }

        // حتی کد درست هم بعد از رسیدن به سقف تلاش پذیرفته نمی‌شود.
        $this->postJson('/api/auth/password/reset', [
            'mobile' => '09123456789',
            'code' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422);

        $this->assertTrue(password_verify('old-password-1', $user->fresh()->password));
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789']);
        $code = $this->sms->sent[0][1];

        $this->travel(6)->minutes();

        $this->postJson('/api/auth/password/reset', [
            'mobile' => '09123456789',
            'code' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422);
    }

    public function test_reset_revokes_existing_sessions(): void
    {
        $user = $this->makeUser();

        DB::table('sessions')->insert([
            'id' => 'abc',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789']);

        $this->postJson('/api/auth/password/reset', [
            'mobile' => '09123456789',
            'code' => $this->sms->sent[0][1],
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_weak_or_mismatched_password_is_rejected(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['mobile' => '09123456789']);

        $this->postJson('/api/auth/password/reset', [
            'mobile' => '09123456789',
            'code' => $this->sms->sent[0][1],
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertStatus(422);
    }
}
