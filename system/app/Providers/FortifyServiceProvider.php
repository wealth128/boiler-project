<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

/**
 * Fortify handles login, logout and password confirmation only. Sign-up,
 * reset by email, email verification, two-factor and passkeys are off
 * (config/fortify.php).
 */
class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // After login, go to the role's landing page (Role::landingRoute()).
        // A page asked for before login is not reopened: on a shared PC it
        // may belong to the previous user's role.
        $this->app->instance(LoginResponse::class, new class implements LoginResponse
        {
            public function toResponse($request)
            {
                /** @var User $user */
                $user = $request->user();

                return $request->wantsJson()
                    ? new JsonResponse(['two_factor' => false])
                    : redirect()->route($user->role->landingRoute());
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureViews();
        $this->configureRateLimiting();

        // Username + password, 5-wrong-password lock, audit entries.
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateUser::class)($request));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     *
     * 10 tries per minute per username + IP. This is set above the 5-wrong-
     * password account lock, so a user sees the clear "account locked"
     * message instead of a generic "too many attempts" one.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(10)->by($throttleKey);
        });
    }
}
