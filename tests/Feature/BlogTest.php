<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'user', string $mobile = '09120000001'): User
    {
        $user = User::create([
            'name' => 'تست',
            'email' => $mobile.'@example.com',
            'mobile' => $mobile,
            'password' => 'password-123',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function makePost(array $overrides = []): Post
    {
        $body = $overrides['body'] ?? "## تیتر\n\nمتن مقاله";

        return Post::create(array_merge([
            'title' => 'مقاله تست',
            'slug' => 'maqale-test-'.uniqid(),
            'body' => $body,
            'body_html' => Post::renderMarkdown($body),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_public_list_shows_only_published_and_released_posts(): void
    {
        $this->makePost(['title' => 'منتشرشده']);
        $this->makePost(['title' => 'پیش‌نویس', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['title' => 'آینده', 'published_at' => now()->addDay()]);

        $titles = collect($this->getJson('/api/blog/posts')->assertOk()->json('data'))->pluck('title');

        $this->assertSame(['منتشرشده'], $titles->all());
    }

    public function test_draft_and_future_posts_are_404_by_slug(): void
    {
        $draft = $this->makePost(['status' => 'draft', 'published_at' => null]);
        $future = $this->makePost(['published_at' => now()->addDay()]);

        $this->getJson('/api/blog/posts/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/blog/posts/'.$future->slug)->assertNotFound();
    }

    public function test_show_works_with_persian_slug_and_returns_seo_fields_and_related(): void
    {
        $main = $this->makePost(['title' => 'اصلی', 'slug' => 'راهنمای-ثبت-آرزو', 'category' => 'راهنما']);
        $this->makePost(['title' => 'مرتبط', 'category' => 'راهنما']);
        $this->makePost(['title' => 'نامرتبط', 'category' => 'خبر']);

        $json = $this->getJson('/api/blog/posts/'.rawurlencode($main->slug))->assertOk()->json();

        $this->assertSame('اصلی', $json['title']);
        $this->assertSame('اصلی', $json['meta_title']);
        $this->assertStringContainsString('<h2>', $json['body_html']);
        $this->assertSame(['مرتبط'], collect($json['related'])->pluck('title')->all());
    }

    public function test_search_and_category_filters(): void
    {
        $this->makePost(['title' => 'درباره پرداخت', 'category' => 'راهنما']);
        $this->makePost(['title' => 'خبر جدید', 'category' => 'خبر']);

        $this->assertCount(1, $this->getJson('/api/blog/posts?q=پرداخت')->json('data'));
        $this->assertCount(1, $this->getJson('/api/blog/posts?category='.rawurlencode('خبر'))->json('data'));
        $this->assertEqualsCanonicalizing(['راهنما', 'خبر'], $this->getJson('/api/blog/posts')->json('categories'));
    }

    public function test_admin_endpoints_require_admin_role(): void
    {
        $this->getJson('/api/admin/blog/posts')->assertUnauthorized();

        $this->actingAs($this->user('user'))
            ->postJson('/api/admin/blog/posts', [])
            ->assertForbidden();
    }

    public function test_admin_creates_post_with_unicode_slug_sanitized_html_and_unique_suffix(): void
    {
        $admin = $this->user('admin');

        $payload = [
            'title' => 'چطور آرزو ثبت کنیم؟',
            'body' => "متن\n\n<script>alert(1)</script>\n\n[لینک](javascript:alert(1))",
            'status' => 'published',
        ];

        $first = $this->actingAs($admin)->postJson('/api/admin/blog/posts', $payload)->assertCreated();
        $second = $this->actingAs($admin)->postJson('/api/admin/blog/posts', $payload)->assertCreated();

        $this->assertSame('چطور-آرزو-ثبت-کنیم', $first->json('post.slug'));
        $this->assertSame('چطور-آرزو-ثبت-کنیم-2', $second->json('post.slug'));

        $html = Post::query()->find($first->json('post.id'))->body_html;
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        // مقاله منتشرشده بلافاصله در سایت دیده می‌شود.
        $this->getJson('/api/blog/posts/'.rawurlencode('چطور-آرزو-ثبت-کنیم'))->assertOk();
    }

    public function test_admin_validation_rejects_bad_slug_and_cover_url(): void
    {
        $this->actingAs($this->user('admin'))->postJson('/api/admin/blog/posts', [
            'title' => 'x',
            'body' => 'y',
            'status' => 'draft',
            'slug' => 'bad slug!',
            'cover_image_url' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug', 'cover_image_url']);
    }

    public function test_admin_can_update_unpublish_and_delete(): void
    {
        $admin = $this->user('admin');
        $post = $this->makePost(['title' => 'قدیمی']);

        $this->actingAs($admin)->putJson('/api/admin/blog/posts/'.$post->id, [
            'title' => 'جدید',
            'slug' => $post->slug,
            'body' => 'متن جدید',
            'status' => 'draft',
        ])->assertOk();

        $this->getJson('/api/blog/posts/'.$post->slug)->assertNotFound();
        $this->assertNull($post->fresh()->published_at);

        $this->actingAs($admin)->deleteJson('/api/admin/blog/posts/'.$post->id)->assertOk();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_sitemap_lists_only_published(): void
    {
        $live = $this->makePost();
        $this->makePost(['status' => 'draft', 'published_at' => null]);

        $slugs = collect($this->getJson('/api/blog/sitemap')->assertOk()->json())->pluck('slug');

        $this->assertSame([$live->slug], $slugs->all());
    }

    public function test_admin_preview_sanitizes_markdown(): void
    {
        $html = $this->actingAs($this->user('admin'))
            ->postJson('/api/admin/blog/preview', ['body' => "## سلام\n\n<script>x</script>"])
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('<h2>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
