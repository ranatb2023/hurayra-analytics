<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    protected $fillable = [
        'original_filename',
        'stored_path',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'status',
        'error_log',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'imported_rows' => 'integer',
        'skipped_rows' => 'integer',
        'error_log' => 'array',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
