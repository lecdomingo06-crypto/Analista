<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminAction extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_LABELS = [
        'evaluator.approved' => 'Evaluator approved',
        'evaluator.needs_info' => 'Evaluator information requested',
        'evaluator.rejected' => 'Evaluator rejected',
        'project.approved' => 'Project approved or restored',
        'project.needs_changes' => 'Project changes requested',
        'project.rejected' => 'Project rejected',
        'project.hidden' => 'Project hidden',
        'report.updated' => 'Report status updated',
        'report.resolved' => 'Report resolved',
        'user.suspended' => 'Account suspended',
        'user.reinstated' => 'Account reinstated',
        'invitation.created' => 'Invitation created',
        'invitation.invalidated' => 'Previous invitation invalidated',
        'tutorial.created' => 'Tutorial created',
        'tutorial.updated' => 'Tutorial updated',
        'tutorial.deleted' => 'Tutorial deleted',
    ];

    public const TARGET_LABELS = [
        'evaluator' => 'Evaluator',
        'project' => 'Project',
        'report' => 'Report',
        'user' => 'Account',
        'invitation' => 'Invitation',
        'tutorial' => 'Tutorial',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'target_id' => 'integer',
            'before_values' => 'array',
            'after_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Administration history cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Administration history cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? 'Administration action';
    }

    public function targetLabel(): string
    {
        return (self::TARGET_LABELS[$this->target_type] ?? 'Record').' #'.$this->target_id;
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    public function changes(): array
    {
        $before = $this->before_values ?? [];
        $after = $this->after_values ?? [];
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }

        return $changes;
    }
}
