<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluatorEvidence extends Model
{
    protected $table = 'evaluator_evidence';

    protected $guarded = [];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(EvaluatorVerification::class, 'evaluator_verification_id');
    }
}
