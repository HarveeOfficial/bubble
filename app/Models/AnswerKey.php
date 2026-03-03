<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnswerKey extends Model
{
    protected $fillable = [
        'name',
        'subject',
        'total_items',
        'choices_per_item',
        'answers',
    ];

    protected $casts = [
        'answers' => 'array',
    ];

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }
}
