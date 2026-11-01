<?php

namespace App\Support;

use App\Models\SensitiveFileAccess;
use App\Models\User;
use InvalidArgumentException;

/** Records only fixed identifiers after an authorized download response is prepared. */
final class SensitiveFileAudit
{
    public static function record(
        User $actor,
        string $type,
        int $id,
        ?int $projectId = null,
        ?int $verificationId = null,
        ?int $version = null,
    ): SensitiveFileAccess {
        if (! $actor->exists || (int) $actor->getKey() < 1 || $actor->is_suspended
            || ! isset(SensitiveFileAccess::ROLE_LABELS[$actor->role])
            || ! isset(SensitiveFileAccess::TYPE_LABELS[$type]) || $id < 1
            || ($projectId !== null && $projectId < 1)
            || ($verificationId !== null && $verificationId < 1)
            || ($version !== null && $version < 1)) {
            throw new InvalidArgumentException('An active account and valid protected file identifiers are required.');
        }

        return SensitiveFileAccess::create([
            'actor_id' => $actor->getKey(),
            'actor_role' => $actor->role,
            'file_type' => $type,
            'file_id' => $id,
            'project_id' => $projectId,
            'verification_id' => $verificationId,
            'version' => $version,
        ]);
    }
}
