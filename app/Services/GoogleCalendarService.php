<?php

namespace App\Services;

use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    public function makeClient(IntegrationAccount $acc): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setAccessType('offline');

        // Restore tokens
        $client->setAccessToken([
            'access_token' => $acc->access_token,
            'refresh_token'=> $acc->refresh_token,
            'expires_in'   => max(1, now()->diffInSeconds($acc->expires_at ?? now()->subMinute(), false)),
            'created'      => now()->subSeconds(3600)->getTimestamp(), // just to satisfy lib
        ]);

        // Refresh if near expiry
        if ($client->isAccessTokenExpired() && $acc->refresh_token) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($acc->refresh_token);
            if (!isset($newToken['error'])) {
                $acc->update([
                    'access_token' => $newToken['access_token'],
                    'expires_at'   => now()->addSeconds(($newToken['expires_in'] ?? 3600) - 60),
                ]);
                $client->setAccessToken($newToken);
            } else {
                Log::warning('Google token refresh failed', ['user_id' => $acc->user_id, 'err' => $newToken['error']]);
            }
        }

        return $client;
    }

    public function calendarService(IntegrationAccount $acc): GoogleCalendar
    {
        return new GoogleCalendar($this->makeClient($acc));
    }

    public function listCalendars(IntegrationAccount $acc): array
    {
        $svc = $this->calendarService($acc);
        $resp = $svc->calendarList->listCalendarList();
        $out = [];
        foreach ($resp->getItems() as $itm) {
            $out[] = [
                'id'    => $itm->getId(),
                'title' => $itm->getSummary(),
                'primary' => (bool)$itm->getPrimary(),
                'timeZone'=> $itm->getTimeZone(),
            ];
        }
        return $out;
    }

    public function listEvents(IntegrationAccount $acc, string $calendarId, string $fromIso, string $toIso, int $limit = 100): array
    {
        $svc = $this->calendarService($acc);
        $params = [
            'singleEvents' => true,
            'orderBy'      => 'startTime',
            'timeMin'      => $fromIso,
            'timeMax'      => $toIso,
            'maxResults'   => $limit,
        ];
        $events = $svc->events->listEvents($calendarId, $params);

        $out = [];
        foreach ($events->getItems() as $e) {
            $out[] = [
                'id'          => $e->getId(),
                'title'       => $e->getSummary() ?? '(No title)',
                'start'       => $e->getStart()->getDateTime() ?: $e->getStart()->getDate(), // all-day uses date
                'end'         => $e->getEnd()->getDateTime() ?: $e->getEnd()->getDate(),
                'allDay'      => (bool)!$e->getStart()->getDateTime(),
                'location'    => $e->getLocation(),
                'htmlLink'    => $e->getHtmlLink(),
                'status'      => $e->getStatus(),
                'creatorEmail'=> optional($e->getCreator())->getEmail(),
                'calendarId'  => $calendarId,
            ];
        }
        return $out;
    }
}
