<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GoogleCalendarController extends Controller
{
    public function __construct(private GoogleCalendarService $svc) {}

    public function index(Request $request) {
        $acc = IntegrationAccount::where('user_id', $request->user()->id)
            ->where('provider', 'google')->first();

        return Inertia::render('integrations/Index', [
            'google' => $acc ? [
                'connected'  => $acc->isActive(),
                'email'      => $acc->meta['email'] ?? null,
                'name'       => $acc->meta['name'] ?? null,
                'avatar'     => $acc->meta['avatar'] ?? null,
                'expiresAt'  => optional($acc->expires_at)?->toIso8601String(),
            ] : ['connected' => false],
        ]);
    }

    public function listCalendars(Request $request) {
        $acc = IntegrationAccount::where('user_id', $request->user()->id)
            ->where('provider', 'google')
            ->whereNull('revoked_at')
            ->firstOrFail();

        return response()->json($this->svc->listCalendars($acc));
    }

    public function listEvents(Request $request) {
        $request->validate([
            'calendarId' => 'required|string',
            'from'       => 'required|date',
            'to'         => 'required|date|after:from',
            'limit'      => 'nullable|integer|min:1|max:250',
        ]);

        $acc = IntegrationAccount::where('user_id', $request->user()->id)
            ->where('provider', 'google')
            ->whereNull('revoked_at')
            ->firstOrFail();

        $events = $this->svc->listEvents(
            $acc,
            $request->string('calendarId'),
            (new \DateTimeImmutable($request->string('from')))->format(DATE_RFC3339),
            (new \DateTimeImmutable($request->string('to')))->format(DATE_RFC3339),
            (int)($request->input('limit', 100))
        );

        return response()->json($events);
    }
}
