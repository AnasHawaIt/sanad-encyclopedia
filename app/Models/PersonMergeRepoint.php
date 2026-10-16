<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** صف أُعيد توجيهه أثناء الدمج، يُحفظ ليتراجع عنه بدقة. */
class PersonMergeRepoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['merge_request_id', 'table_name', 'row_id', 'column_name', 'old_person_id'];

    public function mergeRequest(): BelongsTo
    {
        return $this->belongsTo(PersonMergeRequest::class, 'merge_request_id');
    }
}
