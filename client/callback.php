<?php

use ClientVerification\Providers\DiditProvider;
use ClientVerification\Services\HybridVerificationService;
use ClientVerification\Services\VerificationService;
use Illuminate\Database\Capsule\Manager as Capsule;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$sessionId = trim((string)($_GET['verificationSessionId'] ?? ($_GET['session_id'] ?? ($_GET['verification_session_id'] ?? ($_GET['sessionId'] ?? ($_GET['id'] ?? ''))))));
$statusParam = trim((string)($_GET['status'] ?? ($_GET['decision'] ?? ($_GET['verification_status'] ?? ($_GET['didit_status'] ?? ($_GET['result'] ?? ''))))));

$isCallbackApproved = in_array(strtolower($statusParam), ['approved', 'verified', 'clear', 'passed', 'success', 'complete', 'completed', 'finished'], true);
$isCallbackDeclined = in_array(strtolower($statusParam), ['declined', 'rejected', 'failed', 'denied'], true);

$verificationId = null;

if ($sessionId) {
    $vRow = Capsule::table('mod_cv_verifications')
        ->where('didit_session_id', $sessionId)
        ->first();

    if ($vRow) {
        $verificationId = $vRow->id;
    }
}

if (!$verificationId && $clientId > 0) {
    $vRow = Capsule::table('mod_cv_verifications')
        ->where('client_id', $clientId)
        ->whereIn('status', ['pending', 'in_progress', 'under_review'])
        ->orderByDesc('id')
        ->first();

    if ($vRow) {
        $verificationId = $vRow->id;
        if ($sessionId && empty($vRow->didit_session_id)) {
            Capsule::table('mod_cv_verifications')
                ->where('id', $vRow->id)
                ->update(['didit_session_id' => $sessionId]);
        }
    }
}

if ($verificationId) {
    $config = cv_get_config();
    $apiKey = $config['didit_api_key'] ?? ($config['api_key'] ?? '');
    $workflowId = $config['didit_workflow_id'] ?? ($config['workflow_id'] ?? '');

    $result = null;

    if ($apiKey && $workflowId && $sessionId) {
        try {
            $provider = new DiditProvider(
                $apiKey,
                $workflowId,
                $config['didit_webhook_secret'] ?? '',
                cv_callback_url()
            );

            $result = $provider->getStatus($sessionId);
        } catch (\Throwable $e) {}
    }

    if ($result && $result->decision === \ClientVerification\Providers\KycResult::DECISION_APPROVED) {
        HybridVerificationService::applyResult($verificationId, $result);
    } elseif ($isCallbackApproved) {
        $cleanResult = new \ClientVerification\Providers\KycResult(
            $sessionId ?: 'didit_cb_' . $verificationId,
            'approved',
            \ClientVerification\Providers\KycResult::DECISION_APPROVED,
            0,
            'low',
            ['callback_status' => $statusParam ?: 'Approved', 'source' => 'browser_callback']
        );
        HybridVerificationService::applyResult($verificationId, $cleanResult);
    } elseif ($result && $result->decision === \ClientVerification\Providers\KycResult::DECISION_DECLINED) {
        HybridVerificationService::applyResult($verificationId, $result);
    } elseif ($isCallbackDeclined) {
        $cleanResult = new \ClientVerification\Providers\KycResult(
            $sessionId ?: 'didit_cb_' . $verificationId,
            'rejected',
            \ClientVerification\Providers\KycResult::DECISION_DECLINED,
            80,
            'high',
            ['callback_status' => $statusParam ?: 'Declined', 'source' => 'browser_callback']
        );
        HybridVerificationService::applyResult($verificationId, $cleanResult);
    } elseif ($result && $result->decision !== \ClientVerification\Providers\KycResult::DECISION_ERROR) {
        HybridVerificationService::applyResult($verificationId, $result);
    }
}

$dest = $verificationId
    ? 'index.php?m=clientverification&action=verification&id=' . (int) $verificationId
    : 'index.php?m=clientverification';

if (!headers_sent()) {
    header('Location: ' . $dest);
}
echo '<script>window.location.href = ' . json_encode($dest) . ';</script>';
echo '<div style="text-align: center; padding: 40px;"><p>Redirecting to your verification dashboard...</p><a href="' . htmlspecialchars($dest) . '" class="btn btn-primary">Continue &raquo;</a></div>';
exit;
