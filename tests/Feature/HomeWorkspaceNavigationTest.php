<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomeWorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('workspaceRoles')]
    public function test_login_session_keeps_workspace_navigation_on_home_guidelines_and_home_again(string $role): void
    {
        $user = match ($role) {
            'evaluator' => User::factory()->pendingEvaluator()->create(['name' => 'Kelvin evaluator']),
            'admin' => User::factory()->admin()->create(['name' => 'Kelvin administrator']),
            default => User::factory()->create(['name' => 'Kelvin student']),
        };
        $this->assertSame('array', config('session.driver'));
        $this->assertGuestNavigation($this->browserGet(route('home')));
        $this->assertSessionIdentity(null);
        $this->browserGet(route('login'))->assertOk();
        $login = $this->browserPost(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route($role === 'admin' ? 'admin.index' : 'account.dashboard'));
        $login->assertSessionHas($this->app['auth']->guard()->getName(), $user->id);
        $this->assertSessionIdentity($user);

        foreach ([route('home'), route('guidelines'), route('home')] as $url) {
            $response = $this->browserGet($url)->assertOk();
            $this->assertAuthenticatedAs($user);
            $this->assertWorkspaceNavigation($response, $user);
        }

        $this->browserPost(route('logout'))->assertRedirect(route('home'));
        $this->assertSessionIdentity(null);
        foreach ([route('home'), route('guidelines'), route('home')] as $url) {
            $response = $this->browserGet($url)->assertDontSee($user->name);
            $this->assertGuestNavigation($response);
            $this->assertGuest();
        }
    }

    public static function workspaceRoles(): array
    {
        return [['student'], ['evaluator'], ['admin']];
    }

    public function test_guests_see_the_same_public_navigation_without_workspace_links_on_home_and_guidelines(): void
    {
        foreach ([route('home'), route('guidelines'), route('home')] as $url) {
            $this->assertGuestNavigation($this->browserGet($url));
            $this->assertGuest();
        }
    }

    public function test_public_html_navigation_responses_cannot_be_reused_from_a_guest_or_authenticated_cache(): void
    {
        $user = User::factory()->create();
        foreach ([route('home'), route('guidelines')] as $url) {
            $guestPage = $this->browserGet($url)->assertOk();
            $this->assertHtmlCacheDirectives($guestPage);
            $this->assertGuestNavigation($guestPage);
        }

        $this->browserPost(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        foreach ([route('home'), route('guidelines')] as $url) {
            $signedInPage = $this->browserGet($url)->assertOk();
            $this->assertHtmlCacheDirectives($signedInPage);
            $this->assertWorkspaceNavigation($signedInPage, $user);
        }

        $this->browserPost(route('logout'))->assertRedirect(route('home'));
        foreach ([route('home'), route('guidelines')] as $url) {
            $signedOutPage = $this->browserGet($url)->assertOk();
            $this->assertHtmlCacheDirectives($signedOutPage);
            $this->assertGuestNavigation($signedOutPage);
        }
    }

    private function assertHtmlCacheDirectives(TestResponse $response): void
    {
        foreach (['private', 'no-store', 'no-cache', 'must-revalidate'] as $directive) {
            $this->assertTrue($response->headers->hasCacheControlDirective($directive), $directive);
        }
        $this->assertSame('0', (string) $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $response->assertHeader('Pragma', 'no-cache')->assertHeader('Expires', '0');
    }

    private function assertSessionIdentity(?User $user): void
    {
        $response = $this->browserGet(route('session.status'))->assertOk()
            ->assertExactJson(['authenticated' => $user !== null, 'user_id' => $user?->id]);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function browserGet(string $url): TestResponse
    {
        $this->beginBrowserRequest();

        return $this->rememberSessionCookie($this->get($url));
    }

    private function browserPost(string $url, array $data = []): TestResponse
    {
        $this->beginBrowserRequest();

        return $this->rememberSessionCookie($this->post($url, $data));
    }

    private function beginBrowserRequest(): void
    {
        // Keep only the browser cookie; reload authentication from saved session data.
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
    }

    private function rememberSessionCookie(TestResponse $response): TestResponse
    {
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie($cookie->getName(), $cookie->getValue());

        return $response;
    }

    private function assertWorkspaceNavigation(TestResponse $response, User $user): void
    {
        $response->assertSee('YOUR WORKSPACE');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame((string) $user->id, $xpath->query('//body')->item(0)->getAttribute('data-auth-user-id'));
        $this->assertSame(route('session.status'), $xpath->query('//body')->item(0)->getAttribute('data-session-status-url'));
        foreach ($this->workspaceUrls() as $url) {
            $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.$url.'"]')->length);
        }
        $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('account.dashboard').'"]')->length);
        $this->assertSame($user->role === 'evaluator' ? 1 : 0, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('account.reviews').'"]')->length);
        $this->assertSame($user->role === 'evaluator' ? 1 : 0, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('account.evaluator-verification.show').'"]')->length);
        $this->assertSame($user->isAdmin() ? 1 : 0, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('admin.index').'"]')->length);
        $this->assertSame($user->name, trim($xpath->query('//aside[@id="sidebar"]//a[contains(@class,"account-chip")]//strong')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]//form[@action="'.route('logout').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('login').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('register').'"]')->length);
    }

    private function assertGuestNavigation(TestResponse $response): void
    {
        $response->assertOk()->assertDontSee('YOUR WORKSPACE');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame('', $xpath->query('//body')->item(0)->getAttribute('data-auth-user-id'));
        $this->assertSame(route('session.status'), $xpath->query('//body')->item(0)->getAttribute('data-session-status-url'));
        foreach ($this->workspaceUrls() as $url) {
            $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.$url.'"]')->length);
        }
        foreach ([route('home'), route('guidelines')] as $url) {
            $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.$url.'"]')->length, $url);
        }
        foreach ([route('login'), route('register')] as $url) {
            $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]//a[@href="'.$url.'"]')->length, $url);
        }
        $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]//form[@action="'.route('logout').'"]')->length);
    }

    private function workspaceUrls(): array
    {
        return [route('account.projects'), route('messages.index'),
            route('account.dashboard', ['tab' => 'notifications']), route('account.profile')];
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }
}
