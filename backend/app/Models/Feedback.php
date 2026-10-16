<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    // "feedback" dianggap kata tak terhitung oleh pluralizer, sehingga nama
    // tabel otomatis menjadi 'feedback' padahal migrasinya 'feedbacks'.
    protected $table = 'feedbacks';

    protected $fillable = [
        'placement_id',
        'student_id',
        'company_id',
        'lingkungan_kerja',
        'pembimbing_industri',
        'kesesuaian_bidang',
        'pengalaman_belajar',
        'kenyamanan',
        'kesempatan_belajar',
        'komentar',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'lingkungan_kerja' => 'integer',
            'pembimbing_industri' => 'integer',
            'kesesuaian_bidang' => 'integer',
            'pengalaman_belajar' => 'integer',
            'kenyamanan' => 'integer',
            'kesempatan_belajar' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(PklPlacement::class, 'placement_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
