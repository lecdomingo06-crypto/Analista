<?php

namespace App\Support;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Writes only deliberate administration metadata; never accepts a request payload. */
final class AdminAudit
{
    private const STATES = [
        'status' => ['draft', 'pending', 'approved', 'completed', 'needs_changes', 'rejected', 'hidden', 'under_review', 'resolved'],
        'evaluator_status' => ['pending', 'approved', 'needs_info', 'rejected'],
        'verification_status' => ['pending', 'approved', 'needs_info', 'rejected'],
        'project_status' => ['draft', 'pending', 'approved', 'completed', 'needs_changes', 'rejected', 'hidden'],
        'report_action' => ['dismiss', 'request_corrections', 'hide_content', 'suspend_account'],
    ];

    private const FLAGS = ['is_suspended', 'accepting_requests', 'is_published', 'featured', 'active'];

    private const TUTORIAL_FIELDS = ['title', 'category', 'excerpt', 'body', 'youtube_video_id', 'video_creator', 'is_published', 'featured'];

    public static function record(
        User $actor,
        string $action,
        string $targetType,
        int $targetId,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        array $metadata = [],
    ): AdminAction {
        if (! $actor->isAdmin() || ! isset(AdminAction::ACTION_LABELS[$action])
            || ! isset(AdminAction::TARGET_LABELS[$targetType]) || ! str_starts_with($action, $targetType.'.') || $targetId < 1) {
            throw new InvalidArgumentException('A valid administrator action and target are required.');
        }

        return AdminAction::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_values' => self::states($before),
            'after_values' => self::states($after),
            'reason' => self::reason($reason),
            'metadata' => self::metadata($metadata),
        ]);
    }

    private static function states(array $values): array
    {
        $safe = [];
        foreach (self::STATES as $key => $options) {
            if (array_key_exists($key, $values) && ($values[$key] === null || in_array($values[$key], $options, true))) {
                $safe[$key] = $values[$key];
            }
        }
        foreach (self::FLAGS as $key) {
            if (array_key_exists($key, $values) && in_array($values[$key], [true, false, 0, 1], true)) {
                $safe[$key] = (bool) $values[$key];
            }
        }

        return $safe;
    }

    private static function metadata(array $values): array
    {
        $safe = [];
        foreach (['verification_id', 'affected_id'] as $key) {
            if (isset($values[$key]) && filter_var($values[$key], FILTER_VALIDATE_INT) !== false && (int) $values[$key] > 0) {
                $safe[$key] = (int) $values[$key];
            }
        }
        if (isset($values['affected_type']) && is_string($values['affected_type']) && isset(AdminAction::TARGET_LABELS[$values['affected_type']])) {
            $safe['affected_type'] = $values['affected_type'];
        }
        if (isset($values['changed_fields']) && is_array($values['changed_fields'])) {
            $safe['changed_fields'] = array_values(array_intersect(self::TUTORIAL_FIELDS, array_filter($values['changed_fields'], 'is_string')));
        }

        return $safe;
    }

    private static function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $reason = trim(strip_tags($reason));
        $reason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $reason) ?? '';
        $reason = preg_replace('~https?://[^\s<>"\']+~iu', '[link redacted]', $reason) ?? '';
        $reason = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email redacted]', $reason) ?? '';
        $reason = preg_replace('/\bBearer\s+[^\s,;]+/iu', 'Bearer [redacted]', $reason) ?? '';
        $reason = preg_replace('/\b(password|passwd|pwd|secret|token|api[_ -]?key|authorization|cookie|session[_ -]?(?:id|key))\b["\']?\s*(?:[:=]|\bis\b)?\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;]+)/iu', '$1: [redacted]', $reason) ?? '';
        $reason = preg_replace('/\b(?:[a-f0-9]{32,}|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\b/iu', '[token redacted]', $reason) ?? '';

        return $reason === '' ? null : Str::substr($reason, 0, 2000);
    }
}
