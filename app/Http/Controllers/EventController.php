<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventController extends Controller
{
    public function index(Request $request)
    {
        // Optional: accept range to limit results (FullCalendar sends start/end)
        $query = Event::query()->where(function($q){
            // If you want per-user: uncomment next line
            // $q->where('user_id', Auth::id());
        });

        if ($request->filled('start')) $query->where('end', '>=', $request->start);
        if ($request->filled('end'))   $query->where('start', '<=', $request->end);

        return $query->orderBy('start', 'asc')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'all_day' => ['required','boolean'],
            'start' => ['required','date'],
            'end' => ['nullable','date','after_or_equal:start'],
            'color' => ['nullable','string','max:20'],
            'location' => ['nullable','string','max:255'],
            'meta' => ['nullable','array'],
        ]);

        $data['user_id'] = Auth::id();
        $event = Event::create($data);

        return response()->json($data, 201);
    }

    public function update(Request $request, Event $event)
    {
        // If per-user, ensure ownership:
        // abort_if($event->user_id !== Auth::id(), 403);

        $data = $request->validate([
            'title' => ['sometimes','string','max:255'],
            'description' => ['nullable','string'],
            'all_day' => ['sometimes','boolean'],
            'start' => ['sometimes','date'],
            'end' => ['nullable','date','after_or_equal:start'],
            'color' => ['nullable','string','max:20'],
            'location' => ['nullable','string','max:255'],
            'meta' => ['nullable','array'],
        ]);

        $event->update($data);
        return $event;
    }

    public function destroy(Event $event)
    {
        // If per-user, ensure ownership:
        // abort_if($event->user_id !== Auth::id(), 403);

        $event->delete();
        return response()->noContent();
    }
}
