<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

class GoogleAuthController extends Controller
{
    // Send user to Google
    public function redirect(Request $request) {
        /** @var GoogleProvider $google */
        $google = Socialite::driver('google');
        return $google->setScopes(['https://www.googleapis.com/auth/calendar.readonly'])
              ->with(['access_type' => 'offline', 'prompt' => 'consent'])
              ->redirect();
    }

    // Handle callback
    public function callback(Request $request) {
        /** @var GoogleProvider $google */
        $google = Socialite::driver('google');

        // Use stateless() ONLY if this route is NOT using the 'web' middleware (i.e., no session)
        // If your callback route is under routes/web.php with 'web' middleware, you can omit stateless().
        $google = $google->stateless();
        $googleUser = $google->user();
        $tokens = $googleUser->token; // access token
        $refresh = $googleUser->refreshToken ?? null;
        $expiresIn = $googleUser->expiresIn ?? 3600;

        $account = IntegrationAccount::updateOrCreate(
            ['user_id' => Auth::id(), 'provider' => 'google'],
            [
                'provider_user_id' => $googleUser->id,
                'access_token'     => $tokens,
                'refresh_token'    => $refresh,
                'expires_at'       => now()->addSeconds($expiresIn - 60),
                'scope'            => implode(' ', $googleUser->approvedScopes ?? []),
                'meta'             => [
                    'email' => $googleUser->email,
                    'name'  => $googleUser->name,
                    'avatar'=> $googleUser->avatar,
                ],
                'revoked_at'       => null,
            ]
        );

        return redirect()->route('integrations.index')->with('success', 'Google Calendar connected.');
    }

    // Revoke + delete locally
    public function revoke(Request $request) {
        $request->validate(['hard_delete' => ['nullable','boolean']]);

        $acc = IntegrationAccount::where('user_id', $request->user()->id)
            ->where('provider', 'google')->firstOrFail();

        // Call Google token revoke endpoint
        try {
            $token = decrypt($acc->getRawOriginal('access_token')); // raw encrypted value
        } catch (\Throwable $e) {
            $token = $acc->access_token; // encrypted cast handles it
        }

        // Best effort revoke
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 8]);
            $client->post('https://oauth2.googleapis.com/revoke', [
                'form_params' => ['token' => $token],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);
        } catch (\Throwable $e) { /* swallow, not fatal */ }

        if ($request->boolean('hard_delete')) {
            $acc->delete();
        } else {
            $acc->update(['revoked_at' => now()]);
        }

        return back()->with('success', 'Google Calendar disconnected.');
    }
}
