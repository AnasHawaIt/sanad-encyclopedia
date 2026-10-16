<?php

namespace App\Models\Concerns;

use App\Enums\PublicationStatus;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * حالتا التوثيق والنشر منفصلتان: قد يكون السجل موثقاً وغير منشور.
 */
trait HasWorkflowStatus
{
    public function initializeHasWorkflowStatus(): void
    {
        $this->mergeFillable(['verification_status', 'publication_status']);
        $this->mergeCasts([
            'verification_status' => VerificationStatus::class,
            'publication_status' => PublicationStatus::class,
        ]);
        $this->attributes += [
            'verification_status' => VerificationStatus::Draft->value,
            'publication_status' => PublicationStatus::Unpublished->value,
        ];
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', VerificationStatus::Verified->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', PublicationStatus::Published->value);
    }
}
