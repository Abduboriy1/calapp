<?php

// app/Http/Controllers/Api/TodoController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use \Illuminate\Contracts\Auth\Authenticatable;

class TodoController extends Controller
{
    private Authenticatable|null $user;

    public function __construct(Request $request)
    {
        $this->user = $request->user();
    }

    public function index(Request $req) {
        $q = Todo::query()
            ->with(['assignee:id,name'])
            ->orderByRaw("CASE status WHEN 'todo' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('urgency')
            ->orderBy('due_at');

        // Optional filters
        if ($req->filled('status')) $q->where('status', $req->string('status'));
        if ($req->filled('assignee_id')) $q->where('assignee_id', $req->integer('assignee_id'));

        return [
            'todos' => $q->get(),
            'users' => User::query()->select('id','name')->orderBy('name')->get()
        ];
    }

    public function store(Request $req) {
        $data = $req->validate([
            'title' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'urgency' => ['required','integer','between:1,5'],
            'status' => ['required', Rule::in(['todo','in_progress','done'])],
            'due_at' => ['nullable','date'],
            'assignee_id' => ['nullable','exists:users,id'],
        ]);

        $todo = Todo::create($data + ['created_by' => $req->user()->id]);
        $todo->load('assignee:id,name');


        return response()->json(['todo' => $todo], 201);
    }

    public function update(Request $req, Todo $todo) {
        $data = $req->validate([
            'title' => ['sometimes','string','max:255'],
            'description' => ['nullable','string'],
            'urgency' => ['sometimes','integer','between:1,5'],
            'status' => ['sometimes', Rule::in(['todo','in_progress','done'])],
            'due_at' => ['nullable','date'],
            'assignee_id' => ['nullable','exists:users,id'],
        ]);

        $todo->fill($data)->save();
        $todo->load('assignee:id,name');


        return ['todo' => $todo];
    }

    public function destroy(Todo $todo) {
        $id = $todo->id;
        $todo->delete();
        return response()->noContent();
    }
}
