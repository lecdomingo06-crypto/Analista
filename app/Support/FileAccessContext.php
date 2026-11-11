<?php

namespace App\Support;

use App\Models\DocumentVersion;
use App\Models\EvaluatorEvidence;
use App\Models\ReviewRequest;
use Illuminate\Support\Collection;

/** Resolve current, admin-only file labels without changing immutable access records. */
final class FileAccessContext
{
    public static function forEntries(Collection $entries): array
    {
        $ids = fn (string $type) => $entries->where('file_type', $type)->pluck('file_id')->unique();
        $documentIds = $ids('thesis_pdf');
        $reviewIds = $ids('review_pdf');
        $evidenceIds = $ids('evaluator_evidence');
        $documents = $documentIds->isEmpty() ? collect() : DocumentVersion::whereIn('id', $documentIds)
            ->with('uploader:id,name')->get(['id', 'project_id', 'user_id', 'original_name'])->keyBy('id');
        $reviews = $reviewIds->isEmpty() ? collect() : ReviewRequest::whereIn('id', $reviewIds)
            ->with('evaluator:id,name')->get(['id', 'project_id', 'evaluator_id', 'output_original_name'])->keyBy('id');
        $evidence = $evidenceIds->isEmpty() ? collect() : EvaluatorEvidence::whereIn('id', $evidenceIds)
            ->with(['verification:id,user_id', 'verification.user:id,name'])
            ->get(['id', 'evaluator_verification_id', 'original_name'])->keyBy('id');

        return $entries->mapWithKeys(function ($entry) use ($documents, $reviews, $evidence): array {
            $file = match ($entry->file_type) {
                'thesis_pdf' => $documents->get($entry->file_id),
                'review_pdf' => $reviews->get($entry->file_id),
                'evaluator_evidence' => $evidence->get($entry->file_id),
                default => null,
            };
            // Different kinds of files may share the same ID. Also verify the
            // recorded parent before associating a filename or another person's name.
            $matches = $file && match ($entry->file_type) {
                'thesis_pdf', 'review_pdf' => (int) $file->project_id === (int) $entry->project_id,
                'evaluator_evidence' => (int) $file->evaluator_verification_id === (int) $entry->verification_id,
                default => false,
            };
            $submitter = $matches ? match ($entry->file_type) {
                'thesis_pdf' => $file->uploader?->name,
                'review_pdf' => $file->evaluator?->name,
                'evaluator_evidence' => $file->verification?->user?->name,
                default => null,
            } : null;
            $name = $matches ? ($entry->file_type === 'review_pdf' ? $file->output_original_name : $file->original_name) : null;
            $name = is_string($name) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?? '') : '';

            return [$entry->id => [
                'filename' => $name !== '' ? $name : 'File no longer available',
                'submitter' => $matches ? ($submitter ?? 'Former account') : 'Unavailable',
                'project_title' => in_array($entry->file_type, ['thesis_pdf', 'review_pdf'], true) ? $entry->project?->title : null,
                'category' => match ($entry->file_type) {
                    'thesis_pdf' => 'Thesis PDF',
                    'review_pdf' => 'Review PDF',
                    'evaluator_evidence' => 'Evaluator verification',
                    default => 'Protected file',
                },
                'action' => match ($entry->file_type) {
                    'thesis_pdf' => 'requested a thesis PDF download',
                    'review_pdf' => 'requested a review PDF download',
                    'evaluator_evidence' => 'requested a verification file download',
                    default => 'requested a protected file download',
                },
                'icon' => $entry->file_type === 'evaluator_evidence' ? 'shield-check' : 'file-text',
            ]];
        })->all();
    }
}
