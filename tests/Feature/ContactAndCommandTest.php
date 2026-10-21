<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAndCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $mobile): User
    {
        $u = User::create(['name' => 'ک', 'email' => $mobile.'@e.com', 'mobile' => $mobile, 'password' => 'password-123']);
        $u->forceFill(['role' => $role])->save();

        return $u;
    }

    public function test_contact_form_stores_message_and_validates(): void
    {
        $this->postJson('/api/contact', ['name' => 'علی', 'contact' => 'a@b.ir', 'message' => 'سلام این یک پیام آزمایشی است'])->assertCreated();
        $this->assertSame(1, ContactMessage::count());

        $this->postJson('/api/contact', ['name' => 'علی', 'contact' => 'a@b.ir', 'message' => 'کوتاه'])->assertStatus(422);
    }

    public function test_contact_honeypot_is_silently_dropped(): void
    {
        $this->postJson('/api/contact', ['name' => 'bot', 'contact' => 'x', 'message' => 'spam spam spam spam', 'website' => 'http://spam'])->assertOk();
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_admin_can_list_read_and_delete_messages(): void
    {
        $m = ContactMessage::create(['name' => 'ع', 'contact' => 'c', 'message' => 'یک پیام طولانی برای تست']);

        $this->getJson('/api/admin/contact-messages')->assertUnauthorized();
        $this->actingAs($this->user('user', '09130000001'))->getJson('/api/admin/contact-messages')->assertForbidden();

        $admin = $this->user('admin', '09130000002');
        $this->actingAs($admin)->getJson('/api/admin/contact-messages')->assertOk()->assertJsonPath('unread_count', 1);
        $this->actingAs($admin)->postJson("/api/admin/contact-messages/{$m->id}/read")->assertOk();
        $this->assertNotNull($m->fresh()->read_at);
        $this->actingAs($admin)->deleteJson("/api/admin/contact-messages/{$m->id}")->assertOk();
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_make_super_admin_promotes_existing_user(): void
    {
        $u = $this->user('user', '09130000003');

        $this->artisan('app:make-super-admin', ['mobile' => '09130000003'])->assertSuccessful();
        $this->assertSame('super_admin', $u->fresh()->role);

        $this->artisan('app:make-super-admin', ['mobile' => '123'])->assertFailed();
    }
}
