<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** سجل الملف فقط؛ الملف نفسه على قرص خاص، ويُقدَّم عبر مسار محمي بالصلاحيات (مرحلة المرفقات). */
class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'original_filename',
        'stored_path',
        'disk',
        'mime_type',
        'file_size',
        'checksum',
        'description',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
