<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishOwnerEditTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(): User
    {
        $this->n++;

        return User::create([
            'name' => 'کاربر '.$this->n,
            'email' => "o{$this->n}@example.com",
            'mobile' => '0913000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'password' => 'password-123',
        ]);
    }

    private function wish(User $owner, string $status): Wish
    {
        return Wish::create([
            'title' => 'قدیمی',
            'category' => 'سایر',
            'description' => 'توضیح',
            'story' => 'داستان',
            'city' => 'اصفهان',
            'requested_amount' => 5000,
            'urgency' => 'normal',
            'applicant_name' => 'نام',
            'applicant_mobile' => '09130000000',
            'applicant_relation' => 'خودم',
            'user_id' => $owner->id,
            'status' => $status,
            'review_note' => 'لطفا تصویر را بارگذاری کنید',
        ]);
    }

    private function payload(): array
    {
        return [
            'title' => 'جدید', 'category' => 'سایر', 'description' => 'توضیح جدید', 'story' => 'داستان جدید',
            'city' => 'تهران', 'requested_amount' => 7000, 'urgency' => 'فوری',
            'applicant_name' => 'نام', 'applicant_mobile' => '09130000000', 'applicant_relation' => 'خودم',
        ];
    }

    public function test_owner_can_edit_needs_info_wish_and_it_returns_to_pending(): void
    {
        $owner = $this->user();
        $admin = $this->user();
        $admin->forceFill(['role' => 'admin'])->save();
        $wish = $this->wish($owner, 'needs_info');

        $this->actingAs($owner)->getJson("/api/my/wishes/{$wish->id}/edit")
            ->assertOk()->assertJsonPath('wish.title', 'قدیمی');

        $this->actingAs($owner)->postJson("/api/my/wishes/{$wish->id}", $this->payload())->assertOk();

        $wish->refresh();
        $this->assertSame('pending', $wish->status);
        $this->assertSame('جدید', $wish->title);
        $this->assertSame('urgent', $wish->urgency);
        $this->assertNull($wish->review_note);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $admin->id,
            'link' => '/admin/wishes/'.$wish->id,
        ]);
    }

    public function test_edit_is_refused_for_other_statuses(): void
    {
        $owner = $this->user();
        $wish = $this->wish($owner, 'approved');

        $this->actingAs($owner)->postJson("/api/my/wishes/{$wish->id}", $this->payload())->assertForbidden();
        $this->actingAs($owner)->getJson("/api/my/wishes/{$wish->id}/edit")->assertForbidden();
    }

    public function test_other_user_cannot_edit(): void
    {
        $wish = $this->wish($this->user(), 'needs_info');

        $this->actingAs($this->user())->postJson("/api/my/wishes/{$wish->id}", $this->payload())->assertNotFound();
    }
}
