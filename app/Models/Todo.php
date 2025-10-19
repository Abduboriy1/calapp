<?php

// app/Models/Todo.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    protected $fillable = [
        'title','description','urgency','status','due_at','assignee_id','created_by'
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'urgency' => 'integer',
    ];

    public function assignee() { return $this->belongsTo(User::class, 'assignee_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
