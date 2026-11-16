<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\KindnessItem;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KindnessWallTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'user'): User
    {
        $this->n++;
        $user = User::create([
            'name' => 'کاربر '.$this->n,
            'email' => "k{$this->n}@example.com",
            'mobile' => '0914000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'password' => 'password-123',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function itemPayload(array $o = []): array
    {
        return array_merge([
            'title' => 'کتری برقی', 'category' => 'لوازم برقی', 'description' => 'سالم و تمیز',
            'condition' => 'good', 'city' => 'اصفهان',
        ], $o);
    }

    private function item(User $owner, string $status = 'approved'): KindnessItem
    {
        return KindnessItem::create($this->itemPayload() + ['user_id' => $owner->id, 'status' => $status]);
    }

    public function test_item_is_hidden_until_admin_approves_and_admins_are_notified(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();

        $id = $this->actingAs($owner)->postJson('/api/kindness', $this->itemPayload())
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'link' => '/admin/kindness']);
        $this->getJson('/api/kindness')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/kindness/{$id}")->assertNotFound();

        $this->actingAs($admin)->patchJson("/api/admin/kindness/items/{$id}/status", ['status' => 'approved'])->assertOk();

        $this->getJson('/api/kindness')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.user_id');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id, 'title' => 'آگهی‌ات تأیید شد']);
    }

    public function test_reject_requires_note_and_guests_cannot_post(): void
    {
        $admin = $this->user('admin');
        $item = $this->item($this->user(), 'pending');

        $this->postJson('/api/kindness', $this->itemPayload())->assertUnauthorized();
        $this->actingAs($admin)->patchJson("/api/admin/kindness/items/{$item->id}/status", ['status' => 'rejected'])
            ->assertStatus(422)->assertJsonValidationErrors('review_note');
    }

    public function test_request_rules(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $item = $this->item($owner);
        $pending = $this->item($owner, 'pending');

        $this->actingAs($owner)->postJson("/api/kindness/{$item->id}/requests", ['message' => 'لطفا بدهید'])->assertForbidden();
        $this->actingAs($other)->postJson("/api/kindness/{$pending->id}/requests", ['message' => 'لطفا بدهید'])->assertNotFound();
        $this->actingAs($other)->postJson("/api/kindness/{$item->id}/requests", ['message' => 'لطفا بدهید'])->assertCreated();
        $this->actingAs($other)->postJson("/api/kindness/{$item->id}/requests", ['message' => 'دوباره'])->assertStatus(422);
    }

    public function test_approving_request_reserves_item_rejects_others_and_completion_counts_for_leaderboard(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();
        $a = $this->user();
        $b = $this->user();
        $item = $this->item($owner);

        $ra = $this->actingAs($a)->postJson("/api/kindness/{$item->id}/requests", ['message' => 'نیاز دارم'])->json('data.id');
        $rb = $this->actingAs($b)->postJson("/api/kindness/{$item->id}/requests", ['message' => 'من هم نیاز دارم'])->json('data.id');

        $this->actingAs($admin)->patchJson("/api/admin/kindness/requests/{$ra}/status", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.owner.mobile', $owner->mobile)->assertJsonPath('data.requester.mobile', $a->mobile);

        $this->assertSame('reserved', $item->fresh()->status);
        $this->assertDatabaseHas('kindness_requests', ['id' => $rb, 'status' => 'rejected']);

        $this->actingAs($admin)->patchJson("/api/admin/kindness/requests/{$ra}/status", ['status' => 'completed'])->assertOk();
        $this->assertSame('given', $item->fresh()->status);

        $row = $this->actingAs($admin)->getJson('/api/admin/leaderboard?sort=items')->assertOk()->json('data.0');
        $this->assertSame($owner->id, $row['user_id']);
        $this->assertSame(1, $row['items_given']);
        $this->assertSame(1, $row['rank']);
    }

    public function test_rejecting_approved_request_releases_item(): void
    {
        $admin = $this->user('admin');
        $item = $this->item($this->user());
        $req = $this->actingAs($this->user())->postJson("/api/kindness/{$item->id}/requests", ['message' => 'نیاز دارم'])->json('data.id');

        $this->actingAs($admin)->patchJson("/api/admin/kindness/requests/{$req}/status", ['status' => 'approved'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/admin/kindness/requests/{$req}/status", ['status' => 'rejected'])
            ->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/admin/kindness/requests/{$req}/status", ['status' => 'rejected', 'review_note' => 'منصرف شد'])
            ->assertOk();

        $this->assertSame('approved', $item->fresh()->status);
    }

    public function test_leaderboard_ranks_by_paid_amount_and_is_admin_only(): void
    {
        $admin = $this->user('admin');
        $big = $this->user();
        $small = $this->user();
        $wish = Wish::create([
            'title' => 'آرزو', 'category' => 'سایر', 'description' => 'd', 'story' => 's', 'city' => 'تهران',
            'requested_amount' => 1000000, 'urgency' => 'normal', 'applicant_name' => 'x',
            'applicant_mobile' => '09120000000', 'applicant_relation' => 'خودم', 'user_id' => $admin->id, 'status' => 'approved',
        ]);

        Model::unguarded(function () use ($wish, $big, $small) {
            Donation::create(['wish_id' => $wish->id, 'user_id' => $small->id, 'amount' => 5000, 'status' => 'paid']);
            Donation::create(['wish_id' => $wish->id, 'user_id' => $big->id, 'amount' => 90000, 'status' => 'paid']);
            Donation::create(['wish_id' => $wish->id, 'user_id' => $small->id, 'amount' => 999999, 'status' => 'pending']);
        });

        $this->actingAs($this->user())->getJson('/api/admin/leaderboard')->assertForbidden();

        $res = $this->actingAs($admin)->getJson('/api/admin/leaderboard')->assertOk();
        $this->assertSame($big->id, $res->json('data.0.user_id'));
        $this->assertSame(90000, $res->json('data.0.total_amount'));
        $this->assertSame(5000, $res->json('data.1.total_amount'));
        $this->assertSame(95000, $res->json('totals.total_amount'));
    }
}
