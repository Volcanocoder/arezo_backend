<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarAndTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'user'): User
    {
        $this->n++;
        $u = User::create(['name' => 'ک'.$this->n, 'email' => "t{$this->n}@e.com", 'mobile' => '0914000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT), 'password' => 'password-123']);
        $u->forceFill(['role' => $role])->save();

        return $u;
    }

    public function test_avatar_upload_replace_and_delete(): void
    {
        Storage::fake('local');
        $u = $this->user();

        $url = $this->actingAs($u)->postJson('/api/account/settings/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 200, 200),
        ])->assertOk()->json('avatar_url');

        $this->assertStringContainsString('/api/media/avatar/', $url);
        $first = $u->fresh()->avatar_path;
        Storage::disk('local')->assertExists($first);

        $this->actingAs($u)->getJson('/api/auth/me')->assertJsonPath('user.avatar_url', $url);

        $this->actingAs($u)->postJson('/api/account/settings/avatar', [
            'avatar' => UploadedFile::fake()->image('b.jpg', 300, 300),
        ])->assertOk();
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($u)->deleteJson('/api/account/settings/avatar')->assertOk()->assertJsonPath('avatar_url', null);
        $this->assertNull($u->fresh()->avatar_path);
    }

    public function test_avatar_rejects_non_images_and_big_files(): void
    {
        Storage::fake('local');
        $u = $this->user();

        $this->actingAs($u)->postJson('/api/account/settings/avatar', ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/account/settings/avatar', ['avatar' => UploadedFile::fake()->image('big.png', 400, 400)->size(3000)])->assertStatus(422);
    }

    public function test_avatar_is_served_publicly_with_safe_headers(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $this->actingAs($u)->postJson('/api/account/settings/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)]);

        $file = basename($u->fresh()->avatar_path);
        $this->get("/api/media/avatar/{$file}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/media/avatar/..%2F..%2F.env')->assertNotFound();
        $this->get('/api/media/avatar/nope.png')->assertNotFound();
    }

    private function donation(User $owner, string $status, int $amount, array $extra = []): Donation
    {
        $wish = Wish::first() ?? Wish::create([
            'title' => 'آرزوی گزارش', 'category' => 'تحصیلی', 'description' => 'd', 'story' => 's', 'city' => 'تهران',
            'requested_amount' => 1000000, 'urgency' => 'normal', 'applicant_name' => 'م', 'applicant_mobile' => '09121234567',
            'applicant_relation' => 'خودم', 'user_id' => $owner->id, 'status' => 'approved',
        ]);

        return Donation::create(array_merge([
            'wish_id' => $wish->id, 'user_id' => $owner->id, 'amount' => $amount, 'status' => $status, 'gateway' => 'zarinpal',
            'paid_at' => $status === 'paid' ? now() : null,
        ], $extra));
    }

    public function test_transactions_report_summary_filters_and_access(): void
    {
        $owner = $this->user();
        $this->donation($owner, 'paid', 100000, ['gateway_reference' => 'R1']);
        $this->donation($owner, 'paid', 50000, ['gateway_reference' => 'R2']);
        $this->donation($owner, 'failed', 70000);
        $this->donation($owner, 'pending', 10000);

        $this->getJson('/api/admin/transactions')->assertUnauthorized();
        $this->actingAs($owner)->getJson('/api/admin/transactions')->assertForbidden();

        $admin = $this->user('admin');
        $res = $this->actingAs($admin)->getJson('/api/admin/transactions')->assertOk();
        $res->assertJsonPath('total', 4)->assertJsonPath('summary.paid_amount', 150000)->assertJsonPath('summary.paid_count', 2)
            ->assertJsonPath('summary.average', 75000)->assertJsonPath('summary.pending_count', 1)->assertJsonPath('summary.failed_count', 1);

        $this->actingAs($admin)->getJson('/api/admin/transactions?status=paid')->assertJsonPath('total', 2);
        $this->actingAs($admin)->getJson('/api/admin/transactions?q=R2')->assertJsonPath('total', 1);
        $this->actingAs($admin)->getJson('/api/admin/transactions?status=bogus')->assertStatus(422);
    }

    public function test_transactions_csv_export_guards_formulas(): void
    {
        $owner = $this->user();
        $owner->forceFill(['name' => '=CMD()'])->save();
        $this->donation($owner, 'paid', 5000, ['gateway_reference' => 'R9']);

        $csv = $this->actingAs($this->user('admin'))->get('/api/admin/transactions/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=CMD()", $csv);
        $this->assertStringContainsString('R9', $csv);
    }
}
