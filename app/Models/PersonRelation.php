<?php

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\HasSourceReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** علاقة شيخ وتلميذ بين شخصين، موثقة بمصدرها عبر source_references. */
class PersonRelation extends Model
{
    use HasAuditUsers;
    use HasSourceReferences;

    protected $fillable = ['teacher_person_id', 'student_person_id', 'relation_type', 'verification_status', 'notes'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'teacher_person_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'student_person_id');
    }
}
