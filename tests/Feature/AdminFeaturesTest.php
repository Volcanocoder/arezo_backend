<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'user'): User
    {
        $this->n++;
        $user = User::create([
            'name' => 'کاربر '.$this->n,
            'email' => "u{$this->n}@example.com",
            'mobile' => '0912000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'password' => 'password-123',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function wishFor(User $owner, array $o = []): Wish
    {
        return Wish::create(array_merge([
            'title' => 'آرزوی تست',
            'category' => 'تحصیلی',
            'description' => 'توضیح',
            'story' => 'داستان',
            'city' => 'تهران',
            'requested_amount' => 1000000,
            'urgency' => 'normal',
            'applicant_name' => 'متقاضی',
            'applicant_mobile' => '09121234567',
            'applicant_relation' => 'خودم',
            'user_id' => $owner->id,
            'status' => 'pending',
        ], $o));
    }

    // ---- دسته‌بندی‌ها ----

    public function test_public_categories_list_only_active_of_type(): void
    {
        Category::query()->ofType('wish')->where('name', 'سایر')->update(['is_active' => false]);

        $names = collect($this->getJson('/api/categories?type=wish')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('تحصیلی', $names);
        $this->assertNotContains('سایر', $names);
        $this->assertNotContains('راهنما', $names);
    }

    public function test_admin_category_crud_rename_syncs_wishes_and_delete_is_blocked_when_used(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();
        $this->wishFor($owner, ['category' => 'خانواده']);

        $created = $this->actingAs($admin)->postJson('/api/admin/categories', [
            'type' => 'wish', 'name' => 'ورزشی', 'icon' => '⚽', 'color' => '#EEF0FF',
        ])->assertCreated()->json('category');

        $this->actingAs($admin)->postJson('/api/admin/categories', ['type' => 'wish', 'name' => 'ورزشی'])
            ->assertStatus(422);

        $family = Category::query()->ofType('wish')->where('name', 'خانواده')->first();

        $this->actingAs($admin)->putJson("/api/admin/categories/{$family->id}", ['name' => 'خانواده و دوستان'])->assertOk();
        $this->assertDatabaseHas('wishes', ['category' => 'خانواده و دوستان']);

        $this->actingAs($admin)->deleteJson("/api/admin/categories/{$family->id}")->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/admin/categories/{$created['id']}")->assertOk();
    }

    public function test_wish_submission_rejects_inactive_category(): void
    {
        Category::query()->ofType('wish')->where('name', 'سایر')->update(['is_active' => false]);

        $this->actingAs($this->user())->postJson('/api/wishes', [
            'title' => 'x', 'category' => 'سایر', 'description' => 'x', 'story' => 'x', 'city' => 'تهران',
            'requested_amount' => 1000, 'urgency' => 'عادی', 'applicant_name' => 'x',
            'applicant_mobile' => '09120000000', 'applicant_relation' => 'خودم',
        ])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_category_admin_requires_admin(): void
    {
        $this->actingAs($this->user())->getJson('/api/admin/categories?type=wish')->assertForbidden();
    }

    // ---- اعلان‌ها ----

    public function test_admin_broadcast_reaches_users_and_read_state_is_per_user(): void
    {
        $admin = $this->user('admin');
        $a = $this->user();
        $b = $this->user();

        $this->actingAs($admin)->postJson('/api/admin/notifications', [
            'audience' => 'all', 'title' => 'اطلاعیه', 'body' => 'متن', 'type' => 'info',
        ])->assertCreated();

        $this->actingAs($a)->getJson('/api/notifications/unread-count')->assertJson(['unread_count' => 1]);

        $id = $this->actingAs($a)->getJson('/api/notifications')->json('data.0.id');
        $this->actingAs($a)->postJson("/api/notifications/{$id}/read")->assertJson(['unread_count' => 0]);

        // برای کاربر دیگر هنوز خوانده‌نشده است.
        $this->actingAs($b)->getJson('/api/notifications/unread-count')->assertJson(['unread_count' => 1]);
    }

    public function test_targeted_notification_is_private_and_mark_all_read_works(): void
    {
        $admin = $this->user('admin');
        $a = $this->user();
        $b = $this->user();

        $this->actingAs($admin)->postJson('/api/admin/notifications', [
            'audience' => 'user', 'user_id' => $a->id, 'title' => 'فقط برای تو', 'body' => 'متن', 'type' => 'success', 'link' => '/dashboard',
        ])->assertCreated();
        UserNotification::notify($a->id, 'دوم', 'متن');

        $this->actingAs($b)->getJson('/api/notifications')->assertJsonCount(0, 'data');

        $this->actingAs($a)->getJson('/api/notifications')->assertJsonCount(2, 'data');
        $this->actingAs($a)->postJson('/api/notifications/read-all')->assertJson(['unread_count' => 0]);
        $this->actingAs($a)->getJson('/api/notifications?filter=unread')->assertJsonCount(0, 'data');

        // کاربر دیگر نمی‌تواند اعلان غیرخودش را علامت بزند.
        $id = UserNotification::query()->where('user_id', $a->id)->value('id');
        $this->actingAs($b)->postJson("/api/notifications/{$id}/read")->assertNotFound();
    }

    public function test_broadcast_before_signup_is_not_shown_to_new_user(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/api/admin/notifications', [
            'audience' => 'all', 'title' => 'قدیمی', 'body' => 'متن', 'type' => 'info',
        ]);

        $this->travel(1)->day();
        $newcomer = $this->user();

        $this->actingAs($newcomer)->getJson('/api/notifications/unread-count')->assertJson(['unread_count' => 0]);
    }

    public function test_notification_validation_and_admin_only(): void
    {
        $this->actingAs($this->user())->postJson('/api/admin/notifications', [])->assertForbidden();

        $this->actingAs($this->user('admin'))->postJson('/api/admin/notifications', [
            'audience' => 'user', 'title' => 't', 'body' => 'b', 'type' => 'info', 'link' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id', 'link']);
    }

    public function test_wish_status_change_notifies_owner(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();
        $wish = $this->wishFor($owner);

        $this->actingAs($admin)->patchJson("/api/admin/wishes/{$wish->id}/status", ['status' => 'approved'])->assertOk();

        $this->actingAs($owner)->getJson('/api/notifications')
            ->assertJsonPath('data.0.type', 'success')
            ->assertJsonPath('data.0.title', 'آرزوی تو تأیید شد');
    }

    // ---- کاربران ----

    public function test_users_list_supports_sort_status_and_counts(): void
    {
        $admin = $this->user('admin');
        $active = $this->user();
        $banned = $this->user();
        $banned->forceFill(['suspended_at' => now()])->save();
        $this->wishFor($active);
        $this->wishFor($active);

        $res = $this->actingAs($admin)->getJson('/api/admin/users?status=suspended')->assertOk();
        $this->assertSame([$banned->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(1, $res->json('stats.suspended'));

        $top = $this->actingAs($admin)->getJson('/api/admin/users?sort=wishes')->json('data.0');
        $this->assertSame($active->id, $top['id']);
        $this->assertSame(2, $top['wishes_count']);
    }

    public function test_suspend_blocks_login_kills_sessions_and_unsuspend_restores(): void
    {
        $admin = $this->user('admin');
        $target = $this->user();

        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/suspend", ['reason' => 'تخلف'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        $this->postJson('/api/auth/login', ['mobile' => $target->mobile, 'password' => 'password-123'])->assertForbidden();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/unsuspend")->assertOk();
        // ورود واقعی از فرانت (نشست stateful) با هدر Referer انجام می‌شود.
        config(['sanctum.stateful' => ['localhost:3000']]);
        $this->withHeaders(['Referer' => 'http://localhost:3000', 'Origin' => 'http://localhost:3000'])
            ->postJson('/api/auth/login', ['mobile' => $target->mobile, 'password' => 'password-123'])
            ->assertOk();
        $this->assertNotNull($target->fresh()->last_login_at);
    }

    public function test_cannot_suspend_self_and_plain_admin_cannot_suspend_admins(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');

        $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/suspend")->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/admin/users/{$other->id}/suspend")->assertForbidden();
    }

    public function test_user_detail_and_csv_export(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $u->forceFill(['name' => '=cmd()'])->save();
        $this->wishFor($u);

        $this->actingAs($admin)->getJson("/api/admin/users/{$u->id}")
            ->assertOk()
            ->assertJsonPath('user.wishes_count', 1)
            ->assertJsonCount(1, 'recent_wishes');

        $csv = $this->actingAs($admin)->get('/api/admin/users/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd()", $csv);
        $this->assertStringContainsString($u->mobile, $csv);
    }

    // ---- آرزوها و آمار ----

    public function test_admin_wish_list_filters_search_and_counts(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();
        $this->wishFor($owner, ['title' => 'لپ‌تاپ', 'category' => 'تحصیلی']);
        $this->wishFor($owner, ['title' => 'دارو', 'category' => 'درمانی', 'status' => 'approved', 'urgency' => 'urgent']);

        $res = $this->actingAs($admin)->getJson('/api/admin/wishes?q=دارو')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame(1, $res->json('counts.approved'));
        $this->assertSame(2, $res->json('counts.all'));

        $this->assertCount(1, $this->actingAs($admin)->getJson('/api/admin/wishes?category='.rawurlencode('تحصیلی'))->json('data'));
        $this->assertSame('دارو', $this->actingAs($admin)->getJson('/api/admin/wishes?sort=urgent')->json('data.0.title'));
    }

    public function test_dashboard_stats_include_breakdowns_and_days_param(): void
    {
        $admin = $this->user('admin');
        $this->wishFor($this->user());

        $json = $this->actingAs($admin)->getJson('/api/admin/dashboard/stats?days=14')->assertOk()->json();

        $this->assertCount(14, $json['daily_activity']);
        $this->assertSame('تحصیلی', $json['categories'][0]['name']);
        $this->assertCount(1, $json['recent_wishes']);
        $this->assertNotNull($json['oldest_pending_at']);
    }

    // ---- تصویر وبلاگ ----

    public function test_blog_image_upload_and_public_serving(): void
    {
        Storage::fake('local');

        $url = $this->actingAs($this->user('admin'))->postJson('/api/admin/blog/images', [
            'image' => UploadedFile::fake()->image('cover.jpg', 800, 450),
        ])->assertCreated()->json('url');

        $this->assertStringContainsString('/api/media/blog/', $url);

        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_blog_image_upload_rejects_non_images_and_non_admins(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user())->postJson('/api/admin/blog/images', [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ])->assertForbidden();

        $this->actingAs($this->user('admin'))->postJson('/api/admin/blog/images', [
            'image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
        ])->assertStatus(422);

        $this->get('/api/media/blog/..%2F.env')->assertNotFound();
    }

    public function test_sample_blog_seeder_publishes_seo_article(): void
    {
        $this->seed(\Database\Seeders\BlogSampleSeeder::class);

        $json = $this->getJson('/api/blog/posts/'.rawurlencode('چطور-آرزو-ثبت-کنیم'))->assertOk()->json();

        $this->assertStringContainsString('<h2>', $json['body_html']);
        $this->assertStringContainsString('<img', $json['body_html']);
        $this->assertNotEmpty($json['meta_description']);
    }
}
