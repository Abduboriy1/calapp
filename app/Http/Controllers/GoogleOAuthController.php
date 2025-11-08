<?php

namespace App\Http\Controllers;

use App\Models\IntegrationAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

class GoogleOAuthController extends Controller
{
    // 1) Start OAuth (behind 'auth')
    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();
        $state = (string) Str::uuid();

        // Map state -> user_id for a short time
        Cache::put("oauth:google:state:{$state}", $user->id, now()->addMinutes(10));

        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('google');

        // Ask for offline access (refresh token) + any optional params
        return $provider
            ->redirectUrl(env('GOOGLE_REDIRECT_URI'))
            ->scopes(['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.readonly'])
            ->with([
                'state' => $state,                 // carry our nonce here
                'access_type' => 'offline',
                'prompt' => 'consent',             // useful in dev to ensure refresh_token
                'include_granted_scopes' => 'true',
            ])
            ->stateless()                          // no session/cookie dependency
            ->redirect();
    }

    // 2) Google callback (public; no cookie / session expected)
    public function callback(Request $request): RedirectResponse
    {
        // Recover user identity from the state
        $state = (string) $request->query('state', '');
        $userId = Cache::pull("oauth:google:state:{$state}"); // one-time use
        if (!$userId) {
            return redirect()->to(config('app.url') . '/calendar?google=error_state');
        }

        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('google');


        $googleUser = $provider->stateless()->user();

        $account = IntegrationAccount::updateOrCreate(
            [
                'user_id'          => $userId,
                'provider'         => 'google',
                'provider_user_id' => $googleUser->getId(),
            ],
            [
                'access_token'  => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken, // may be null on subsequent consents
                'expires_at'    => now()->addSeconds($googleUser->expiresIn ?? 3300),
                'scope'         => 'https://www.googleapis.com/auth/calendar.readonly',
                'meta'          => [
                    'email' => $googleUser->getEmail(),
                    'name'  => $googleUser->getName(),
                ],
                'revoked_at'    => null,
            ]
        );

        // Preserve old refresh token if Google didn't send a new one
        if (!$googleUser->refreshToken && $account->getOriginal('refresh_token')) {
            $account->refresh_token = $account->getOriginal('refresh_token');
            $account->save();
        }

        return redirect()->to(config('app.url') . '/calendar?google=connected');
    }

    // 3) Token fetch remains the same; it reads by user_id from Auth::id() in API context
    public function accessToken(Request $request)
    {
        $account = IntegrationAccount::where([
            'user_id'  => Auth::id(),
            'provider' => 'google'
        ])->whereNull('revoked_at')->first();

        if (!$account) return response()->json(['connected' => false], 200);

        if (!$account->expires_at || $account->expires_at->lt(now()->addSeconds(60))) {
            if (!$account->refresh_token) return response()->json(['connected' => false], 200);

            $refreshed = $this->refreshWithGoogle($account->refresh_token);
            if (!$refreshed) return response()->json(['connected' => false], 200);

            $account->access_token = $refreshed['access_token'];
            if (!empty($refreshed['expires_in'])) {
                $account->expires_at = now()->addSeconds((int) $refreshed['expires_in']);
            }
            $account->save();
        }

        return response()->json([
            'connected'    => true,
            'access_token' => $account->access_token,
            'expires_at'   => $account->expires_at?->toIso8601String(),
            'email'        => $account->meta['email'] ?? null,
        ]);
    }

    public function revoke()
    {
        $account = IntegrationAccount::where([
            'user_id'  => Auth::id(),
            'provider' => 'google',
        ])->whereNull('revoked_at')->first();

        if ($account) {
            if ($account->access_token) {
                Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                    'token' => $account->access_token,
                ]);
            }
            $account->revoked_at = now();
            $account->save();
        }

        return response()->json(['ok' => true]);
    }

    protected function refreshWithGoogle(string $refreshToken): ?array
    {
        $resp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$resp->ok()) return null;
        $data = $resp->json();
        return $data['access_token'] ?? null ? $data : null;
    }
}
