<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Metadata of an authorized download request, not confirmation of delivery. */
class SensitiveFileAccess extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_LABELS = [
        'thesis_pdf' => 'Thesis PDF',
        'review_pdf' => 'Review PDF',
        'evaluator_evidence' => 'Evaluator evidence',
    ];

    public const ROLE_LABELS = [
        'student' => 'Student',
        'evaluator' => 'Evaluator',
        'admin' => 'Administrator',
    ];

    protected $fillable = [
        'actor_id', 'actor_role', 'file_type', 'file_id', 'project_id', 'verification_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'file_id' => 'integer',
            'project_id' => 'integer',
            'verification_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Sensitive file access history cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Sensitive file access history cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fileLabel(): string
    {
        return (self::TYPE_LABELS[$this->file_type] ?? 'Protected file').' #'.$this->file_id;
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->actor_role] ?? 'Account';
    }
}
