<?php

namespace ClientVerification\Services;

use ClientVerification\Mail\Notifier;
use ClientVerification\Providers\VerificationEntity;
use ClientVerification\Webhooks\OutboundWebhook;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Core verification operations: create, retrieve, admin decisions, expiry.
 * Does not contain provider-specific logic (that lives in providers + HybridVerificationService).
 */
class VerificationService
{
    public static function generateReference(int $verificationId, int $clientId): string
    {
        return 'CV-' . $verificationId . '-' . $clientId;
    }

    public static function getActiveForClient(int $clientId)
    {
        // 1. Check for suspended (highest compliance enforcement priority)
        $suspended = Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->where('status', 'suspended')
            ->orderByDesc('id')
            ->first();
        if ($suspended) {
            return $suspended;
        }

        // 2. Check for active approved
        $approved = Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->first();
        if ($approved) {
            return $approved;
        }

        // 3. Check for active under_review / info_requested / pending
        $active = Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->whereIn('status', ['under_review', 'info_requested', 'pending', 'in_progress'])
            ->orderByDesc('id')
            ->first();
        if ($active) {
            return $active;
        }

        // 4. Default to latest record (e.g. rejected, expired)
        return Capsule::table('mod_cv_verifications')
            ->where('client_id', $clientId)
            ->orderByDesc('id')
            ->first();
    }

    public static function find(int $verificationId)
    {
        return Capsule::table('mod_cv_verifications')->where('id', $verificationId)->first();
    }

    public static function updateStatus(int $verificationId, string $status, int $adminId = 0, string $note = ''): void
    {
        $prevRow = self::find($verificationId);
        if (!$prevRow) {
            return;
        }

        // Idempotency check: if already in this status, do not re-send emails or duplicate updates
        if ($prevRow->status === $status) {
            return;
        }

        $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if (in_array($status, ['approved', 'rejected', 'suspended'], true)) {
            $data['reviewed_at'] = date('Y-m-d H:i:s');
            $data['reviewed_by'] = $adminId ? (string) $adminId : null;
        }
        if (in_array($status, ['rejected', 'suspended'], true) && !empty($note)) {
            try {
                if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'rejection_reason')) {
                    Capsule::schema()->table('mod_cv_verifications', function ($table) {
                        $table->text('rejection_reason')->nullable();
                    });
                }
                $data['rejection_reason'] = $note;
            } catch (\Throwable $e) {}
        }
        if ($status === 'approved') {
            $expiryDays = (int) cv_setting('verification_expiry_days', 365);
            if ($expiryDays > 0) {
                $data['expires_at'] = date('Y-m-d H:i:s', time() + ($expiryDays * 86400));
            }
        }
        Capsule::table('mod_cv_verifications')->where('id', $verificationId)->update($data);

        // Keep associated documents in sync with the verification decision.
        if (in_array($status, ['approved', 'rejected', 'suspended'], true)) {
            $docStatus = ($status === 'suspended') ? 'rejected' : $status;
            Capsule::table('mod_cv_documents')
                ->where('verification_id', $verificationId)
                ->update(['status' => $docStatus, 'updated_at' => date('Y-m-d H:i:s')]);
        }

        // Sync/close other dangling open sessions for the same client if decided
        if (in_array($status, ['approved', 'rejected', 'suspended'], true) && $prevRow->client_id > 0) {
            try {
                Capsule::table('mod_cv_verifications')
                    ->where('client_id', $prevRow->client_id)
                    ->where('id', '!=', $verificationId)
                    ->whereIn('status', ['pending', 'in_progress', 'under_review', 'info_requested'])
                    ->update([
                        'status' => $status,
                        'rejection_reason' => $note ?: null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } catch (\Throwable $e) {}
        }

        if (function_exists('cv_log_audit')) {
            cv_log_audit($verificationId, 'status_' . $status, $adminId, $note);
        }

        $row = self::find($verificationId);
        if ($row) {
            if ($status === 'approved') {
                Notifier::approved($row->client_id);
                OutboundWebhook::dispatch('verification.approved', $verificationId);
            } elseif ($status === 'rejected') {
                Notifier::rejected($row->client_id, ['reason' => $note, 'note' => $note]);
                OutboundWebhook::dispatch('verification.rejected', $verificationId);
            } elseif ($status === 'suspended') {
                Notifier::suspended($row->client_id, ['reason' => $note ?: 'Account verification suspended by compliance administration.', 'note' => $note]);
                OutboundWebhook::dispatch('verification.suspended', $verificationId);
            } elseif ($status === 'under_review') {
                Notifier::reviewRequired($row->client_id);
                OutboundWebhook::dispatch('verification.review_required', $verificationId);
            } elseif ($status === 'expired') {
                Notifier::expired($row->client_id);
                OutboundWebhook::dispatch('verification.expired', $verificationId);
            }
        }
    }

    public static function requestInformation(int $verificationId, int $adminId, string $note = ''): void
    {
        try {
            if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'info_request_note')) {
                Capsule::schema()->table('mod_cv_verifications', function ($table) {
                    $table->text('info_request_note')->nullable();
                });
            }
        } catch (\Throwable $e) {}

        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update([
                'status' => 'info_requested',
                'info_request_note' => $note,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if (function_exists('cv_log_audit')) {
            cv_log_audit($verificationId, 'request_information', $adminId, $note ?: 'Additional information requested');
        }

        $row = self::find($verificationId);
        if ($row) {
            Notifier::infoRequired($row->client_id, ['note' => $note, 'reason' => $note]);
            OutboundWebhook::dispatch('verification.info_requested', $verificationId);
        }
    }

    public static function suspend(int $verificationId, int $adminId, string $note = ''): void
    {
        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update(['status' => 'rejected', 'updated_at' => date('Y-m-d H:i:s')]);
        Capsule::table('mod_cv_documents')
            ->where('verification_id', $verificationId)
            ->update(['status' => 'rejected', 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit($verificationId, 'suspended', $adminId, $note);
    }

    /**
     * Expire verifications past their expiry date (cron).
     */
    public static function expireOverdue(): int
    {
        $now = date('Y-m-d H:i:s');
        $affected = Capsule::table('mod_cv_verifications')
            ->where('status', 'approved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update(['status' => 'expired', 'updated_at' => $now]);
        return $affected;
    }

    public static function deleteDocument(int $documentId, int $adminId = 0): bool
    {
        $doc = Capsule::table('mod_cv_documents')->where('id', $documentId)->first();
        if (!$doc) {
            return false;
        }

        $config = cv_get_config();
        $storage = new \ClientVerification\Storage\DocumentStorage(
            $config['storage_path'] ?? '',
            (bool) ($config['storage_encryption'] ?? false),
            $config['encryption_key'] ?? ''
        );

        if (!empty($doc->storage_path)) {
            $storage->delete($doc->storage_path);
        }

        Capsule::table('mod_cv_documents')->where('id', $documentId)->delete();

        if (function_exists('cv_log_audit')) {
            cv_log_audit((int) $doc->verification_id, 'document_deleted', $adminId, 'Document #' . $documentId . ' (' . $doc->original_filename . ') deleted by admin #' . $adminId);
        }

        return true;
    }

    public static function delete(int $verificationId, int $adminId = 0): bool
    {
        $v = Capsule::table('mod_cv_verifications')->where('id', $verificationId)->first();
        if (!$v) {
            return false;
        }

        $config = cv_get_config();
        $storage = new \ClientVerification\Storage\DocumentStorage(
            $config['storage_path'] ?? '',
            (bool) ($config['storage_encryption'] ?? false),
            $config['encryption_key'] ?? ''
        );

        // 1. Delete all physical files from disk securely
        $docs = Capsule::table('mod_cv_documents')->where('verification_id', $verificationId)->get();
        foreach ($docs as $doc) {
            if (!empty($doc->storage_path)) {
                $storage->delete($doc->storage_path);
            }
        }

        // Clean up empty directory if applicable
        $storageBase = $config['storage_path'] ?? '';
        if (empty($storageBase)) {
            $storageBase = __DIR__ . '/../../storage';
        }
        $docDir = rtrim(str_replace('\\', '/', $storageBase), '/') . '/documents/' . $verificationId;
        if (is_dir($docDir)) {
            @rmdir($docDir);
        }

        // 2. Delete database records
        Capsule::table('mod_cv_documents')->where('verification_id', $verificationId)->delete();
        Capsule::table('mod_cv_personal_data')->where('verification_id', $verificationId)->delete();

        // 3. Log audit
        if (function_exists('cv_log_audit')) {
            cv_log_audit($verificationId, 'verification_deleted', $adminId, 'Verification #' . $verificationId . ' deleted by admin #' . $adminId);
        }

        // 4. Delete verification row
        Capsule::table('mod_cv_verifications')->where('id', $verificationId)->delete();

        return true;
    }

    public static function toEntity(object $row): VerificationEntity
    {
        $personal = Capsule::table('mod_cv_personal_data')->where('verification_id', $row->id)->first();
        $personalData = $personal ? (array) $personal : [];
        return new VerificationEntity($row->id, $row->client_id, $row->verification_method, $row->client_ref ?? '', $personalData);
    }
}
