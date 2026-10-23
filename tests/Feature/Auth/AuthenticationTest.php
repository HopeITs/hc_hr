<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_remember_me_queues_the_recaller_cookie(): void
    {
        $user = $this->makeLoginableUser();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('remember', true)
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();

        // Laravel only queues the recaller cookie AND persists a remember_token
        // when the remember flag reaches Auth::attempt() as true.
        $this->assertTrue(
            \Illuminate\Support\Facades\Cookie::hasQueued(auth()->guard()->getRecallerName()),
            'The remember-me recaller cookie should be queued when remember is checked.',
        );
        $this->assertNotNull(
            $user->fresh()->remember_token,
            'A remember_token should be persisted so the user is recognised on return.',
        );
    }

    public function test_login_without_remember_does_not_queue_the_recaller_cookie(): void
    {
        $user = $this->makeLoginableUser();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('remember', false)
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        $this->assertFalse(
            \Illuminate\Support\Facades\Cookie::hasQueued(auth()->guard()->getRecallerName()),
            'The recaller cookie must not be queued when remember is unchecked.',
        );
    }

    public function test_login_form_binds_the_remember_checkbox_to_livewire(): void
    {
        Livewire::test(Login::class)
            ->assertSeeHtml('wire:model="remember"');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login');

        $response->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        // A4: the sign-in page that follows keeps the user's language, so the
        // redirect goes to the localized home rather than the bare root.
        $response->assertRedirect(LaravelLocalization::localizeUrl('/', 'en'));

        $this->assertGuest();
    }

    /**
     * Build a minimal, loginable user directly against the real schema.
     *
     * The shared UserFactory/EmployeeFactory chain is drifted from the current
     * migrations (inserts non-existent columns like `name_en`), so we bypass it
     * here to keep the remember-me assertions independent of that breakage.
     */
    private function makeLoginableUser(): User
    {
        $employeeId = \Illuminate\Support\Facades\DB::table('employees')->insertGetId([
            'first_name_en' => 'Test',
            'first_name_ar' => 'اختبار',
            'last_name_en' => 'User',
            'last_name_ar' => 'مستخدم',
            'username' => 'test_user_'.uniqid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User;
        $user->email = 'remember_test_'.uniqid().'@example.com';
        $user->password = \Illuminate\Support\Facades\Hash::make('password');
        $user->employee_id = $employeeId;
        $user->remember_token = null;
        $user->save();

        return $user;
    }
}
