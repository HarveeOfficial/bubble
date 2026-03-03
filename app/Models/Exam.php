<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $fillable = [
        'answer_key_id',
        'student_name',
        'student_id',
        'image_path',
        'detected_answers',
        'score',
        'total_items',
        'percentage',
        'status',
    ];

    protected $casts = [
        'detected_answers' => 'array',
    ];

    public function answerKey()
    {
        return $this->belongsTo(AnswerKey::class);
    }

    public function results()
    {
        return $this->hasMany(ExamResult::class);
    }
}
