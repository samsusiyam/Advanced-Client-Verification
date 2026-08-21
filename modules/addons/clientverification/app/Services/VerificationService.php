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
        $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if (in_array($status, ['approved', 'rejected'], true)) {
            $data['reviewed_at'] = date('Y-m-d H:i:s');
            $data['reviewed_by'] = $adminId ? (string) $adminId : null;
        }
        Capsule::table('mod_cv_verifications')->where('id', $verificationId)->update($data);

        // Keep associated documents in sync with the verification decision.
        if (in_array($status, ['approved', 'rejected'], true)) {
            Capsule::table('mod_cv_documents')
                ->where('verification_id', $verificationId)
                ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
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
                Notifier::rejected($row->client_id);
                OutboundWebhook::dispatch('verification.rejected', $verificationId);
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
        Capsule::table('mod_cv_verifications')
            ->where('id', $verificationId)
            ->update(['status' => 'under_review', 'updated_at' => date('Y-m-d H:i:s')]);
        cv_log_audit($verificationId, 'request_information', $adminId, $note);
        $row = self::find($verificationId);
        if ($row) {
            Notifier::infoRequired($row->client_id);
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

    public static function toEntity(object $row): VerificationEntity
    {
        $personal = Capsule::table('mod_cv_personal_data')->where('verification_id', $row->id)->first();
        $personalData = $personal ? (array) $personal : [];
        return new VerificationEntity($row->id, $row->client_id, $row->verification_method, $row->client_ref ?? '', $personalData);
    }
}
