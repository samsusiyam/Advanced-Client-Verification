<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;
use ClientVerification\Services\VerificationService;
use ClientVerification\Storage\DocumentStorage;

$adminId = (int) ($_SESSION['adminid'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);

// Secure document download
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $doc = Capsule::table('mod_cv_documents')->where('id', (int) $_GET['download'])->first();
    if ($doc) {
        $config = cv_get_config();
        $storage = new DocumentStorage(
            $config['storage_path'] ?? '',
            (bool) ($config['storage_encryption'] ?? false),
            $config['encryption_key'] ?? ''
        );
        $content = $storage->read($doc->storage_path, (bool) $doc->encrypted);
        if ($content !== null) {
            header('Content-Type: ' . Sanitizer::headerValue($doc->mime_type));
            header('Content-Disposition: inline; filename="' . Sanitizer::headerValue($doc->original_filename) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        }
    }
    http_response_code(404);
    exit;
}

// Handle POST actions
$feedbackMessage = '';
$feedbackType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && Csrf::check($_POST['cv_token'] ?? null)) {
    $action = $_POST['action'];
    $note = trim($_POST['note'] ?? '');

    switch ($action) {
        case 'approve':
            VerificationService::updateStatus($id, 'approved', $adminId, $note ?: 'admin_approved');
            $feedbackMessage = 'Verification has been approved.';
            break;
        case 'reject':
            VerificationService::updateStatus($id, 'rejected', $adminId, $note ?: 'admin_rejected');
            $feedbackMessage = 'Verification has been rejected.';
            $feedbackType = 'danger';
            break;
        case 'request_info':
            VerificationService::requestInformation($id, $adminId, $note);
            $feedbackMessage = 'Additional information requested from client.';
            $feedbackType = 'warning';
            break;
        case 'suspend':
            VerificationService::suspend($id, $adminId, $note);
            $feedbackMessage = 'Verification suspended.';
            $feedbackType = 'danger';
            break;
        case 'manual_review':
            Capsule::table('mod_cv_verifications')->where('id', $id)->update(['status' => 'under_review', 'manual_review_required' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            cv_log_audit($id, 'manual_review', $adminId, $note);
            $feedbackMessage = 'Verification set to Under Review status.';
            $feedbackType = 'info';
            break;
        case 'delete':
            VerificationService::delete($id, $adminId);
            header('Location: addonmodules.php?module=clientverification&action=verifications&deleted=1');
            echo '<script>window.location.href = "addonmodules.php?module=clientverification&action=verifications&deleted=1";</script>';
            exit;
    }
}

$row = VerificationService::find($id);

cv_admin_header('verifications', 'Verification #' . $id, 'Review documents and client identity data.');

if (!$row) {
    echo '<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> Verification record not found.</div>';
    return;
}

$personal = Capsule::table('mod_cv_personal_data')->where('verification_id', $id)->first();
$documents = Capsule::table('mod_cv_documents')->where('verification_id', $id)->get();
$client = Capsule::table('tblclients')->where('id', $row->client_id)->first();
$audit = json_decode($row->audit_log ?? '[]', true);

?>

<?php if ($feedbackMessage): ?>
    <div class="alert alert-<?php echo $feedbackType; ?>" style="border-radius: 6px; margin-bottom: 20px;">
        <i class="fa fa-info-circle"></i> <?php echo htmlspecialchars($feedbackMessage); ?>
    </div>
<?php endif; ?>

<div class="row">
    <!-- LEFT COLUMN: Client Info & Verification Meta -->
    <div class="col-md-5">
        <!-- Client Profile Card -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;"><i class="fa fa-user text-primary"></i> Client Profile</h4>
                <?php if ($client): ?>
                    <a href="clientssummary.php?userid=<?php echo (int) $client->id; ?>" target="_blank" class="btn btn-default btn-xs">View WHMCS Profile &raquo;</a>
                <?php endif; ?>
            </div>

            <?php if ($client): ?>
                <table class="table table-condensed" style="margin: 0;">
                    <tr>
                        <td style="color: #64748b; width: 110px; border-top: none;">Full Name:</td>
                        <td style="font-weight: 600; border-top: none;"><?php echo htmlspecialchars($client->firstname . ' ' . $client->lastname); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Email:</td>
                        <td><a href="mailto:<?php echo htmlspecialchars($client->email); ?>"><?php echo htmlspecialchars($client->email); ?></a></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Phone:</td>
                        <td><?php echo htmlspecialchars($client->phonenumber ?: 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Company:</td>
                        <td><?php echo htmlspecialchars($client->companyname ?: 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Country:</td>
                        <td><?php echo htmlspecialchars($client->country ?: 'N/A'); ?></td>
                    </tr>
                </table>
            <?php else: ?>
                <p style="color: #94a3b8;">Client record not found.</p>
            <?php endif; ?>
        </div>

        <!-- Verification Details Card -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-id-card text-primary"></i> Verification Status
            </h4>

            <?php
            $statusBadge = match($row->status) {
                'approved' => '<span class="label label-success" style="font-size: 13px; padding: 5px 12px;">Approved</span>',
                'rejected' => '<span class="label label-danger" style="font-size: 13px; padding: 5px 12px;">Rejected</span>',
                'under_review' => '<span class="label label-warning" style="font-size: 13px; padding: 5px 12px;">Under Review</span>',
                'expired' => '<span class="label label-default" style="font-size: 13px; padding: 5px 12px;">Expired</span>',
                default => '<span class="label label-info" style="font-size: 13px; padding: 5px 12px;">Pending</span>',
            };
            ?>

            <table class="table table-condensed" style="margin: 0;">
                <tr>
                    <td style="color: #64748b; width: 130px; border-top: none;">Current Status:</td>
                    <td style="border-top: none;"><?php echo $statusBadge; ?></td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Method:</td>
                    <td><strong style="text-transform: capitalize;"><?php echo htmlspecialchars($row->verification_method); ?></strong></td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Reference:</td>
                    <td><code><?php echo htmlspecialchars($row->client_ref ?: 'CV-' . $row->id); ?></code></td>
                </tr>
                <tr>
                    <td style="color: #64748b; vertical-align: top;">Risk Assessment:</td>
                    <td>
                        <div style="margin-bottom: 6px;">
                            <?php
                            $riskColor = match($row->risk_level) {
                                'high' => '#ef4444',
                                'medium' => '#f59e0b',
                                default => '#10b981',
                            };
                            ?>
                            <span style="font-weight: 700; color: <?php echo $riskColor; ?>;">
                                <i class="fa fa-circle"></i> <?php echo ucfirst(htmlspecialchars($row->risk_level)); ?> Risk (Score: <?php echo htmlspecialchars($row->risk_score); ?>/100)
                            </span>
                        </div>

                        <?php
                        $reasons = [];
                        if (!empty($row->risk_reasons)) {
                            $reasons = json_decode($row->risk_reasons, true) ?: [];
                        }
                        if (empty($reasons) && !empty($row->risk_flags)) {
                            $flags = json_decode($row->risk_flags, true) ?: [];
                            foreach ($flags as $f) {
                                $reasons[] = ucwords(str_replace('_', ' ', $f));
                            }
                        }
                        ?>

                        <?php if (!empty($reasons)): ?>
                            <div style="background: <?php echo $row->risk_level === 'high' ? '#fef2f2' : '#fffbeb'; ?>; border: 1px solid <?php echo $row->risk_level === 'high' ? '#fecaca' : '#fde68a'; ?>; border-radius: 6px; padding: 8px 12px; margin-top: 4px;">
                                <div style="font-size: 11px; font-weight: 700; color: <?php echo $row->risk_level === 'high' ? '#991b1b' : '#92400e'; ?>; margin-bottom: 3px;">
                                    <i class="fa fa-exclamation-triangle"></i> Identified Risk Factors:
                                </div>
                                <ul style="margin: 0; padding-left: 16px; font-size: 11px; color: <?php echo $row->risk_level === 'high' ? '#7f1d1d' : '#78350f'; ?>; line-height: 1.4;">
                                    <?php foreach ($reasons as $reason): ?>
                                        <li><?php echo htmlspecialchars($reason); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; padding: 4px 8px; font-size: 11px; color: #166534; margin-top: 4px; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="fa fa-check-circle"></i> Clean submission (no flags)
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($row->didit_session_id): ?>
                    <tr>
                        <td style="color: #64748b;">Didit Session:</td>
                        <td><code><?php echo htmlspecialchars(substr($row->didit_session_id, 0, 16) . '...'); ?></code></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Didit Decision:</td>
                        <td><span class="label label-default"><?php echo htmlspecialchars($row->didit_decision ?: 'None'); ?></span></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td style="color: #64748b;">Submitted At:</td>
                    <td><?php echo htmlspecialchars($row->submitted_at ?? $row->created_at); ?></td>
                </tr>
                <?php if ($row->reviewed_at): ?>
                    <tr>
                        <td style="color: #64748b;">Reviewed At:</td>
                        <td><?php echo htmlspecialchars($row->reviewed_at); ?></td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Personal Submitted Data -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-info-circle text-primary"></i> Submitted Personal Info
            </h4>

            <?php if ($personal): ?>
                <table class="table table-condensed" style="margin: 0;">
                    <?php foreach (['document_number' => 'Document / ID Number', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'date_of_birth' => 'Date of Birth', 'phone' => 'Phone', 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'postal_code' => 'Postal Code', 'country' => 'Country'] as $field => $lbl): ?>
                        <?php if (!empty($personal->$field)): ?>
                            <tr>
                                <td style="color: #64748b; width: 140px;"><?php echo $lbl; ?>:</td>
                                <td style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($personal->$field); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p style="color: #94a3b8; margin: 0;">No separate personal data record submitted.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT COLUMN: Documents, Actions & Audit Trail -->
    <div class="col-md-7">
        <!-- Admin Decision Box -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">
                    <i class="fa fa-gavel text-primary"></i> Admin Decision &amp; Actions
                </h4>
                <button type="button" class="btn btn-danger btn-xs" onclick="if(confirm('Are you sure you want to PERMANENTLY DELETE this verification record (#<?php echo (int)$id; ?>) and all uploaded files? This action cannot be undone.')) { document.getElementById('cv_delete_form').submit(); }">
                    <i class="fa fa-trash"></i> Delete Verification
                </button>
            </div>

            <form method="post" action="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>">
                <?php echo Csrf::field(); ?>
                
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Decision Note / Reason:</label>
                    <textarea name="note" class="form-control" rows="2" placeholder="Optional note or client rejection reason..."></textarea>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('Approve this client verification?');">
                        <i class="fa fa-check"></i> Approve
                    </button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger" onclick="return confirm('Reject this client verification?');">
                        <i class="fa fa-times"></i> Reject
                    </button>
                    <button type="submit" name="action" value="request_info" class="btn btn-warning" onclick="return confirm('Request additional information from client?');">
                        <i class="fa fa-question-circle"></i> Request Info
                    </button>
                    <button type="submit" name="action" value="manual_review" class="btn btn-info">
                        <i class="fa fa-eye"></i> Under Review
                    </button>
                    <button type="submit" name="action" value="suspend" class="btn btn-default" onclick="return confirm('Suspend verification?');">
                        <i class="fa fa-ban"></i> Suspend
                    </button>
                </div>
            </form>

            <form method="post" id="cv_delete_form" action="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>" style="display: none;">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="action" value="delete">
            </form>
        </div>

        <!-- Uploaded Documents Card -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-file-text-o text-primary"></i> Uploaded Documents (<?php echo count($documents); ?>)
            </h4>

            <?php if ($documents->isEmpty()): ?>
                <div style="text-align: center; padding: 24px; color: #94a3b8;">
                    <i class="fa fa-folder-open-o fa-2x" style="margin-bottom: 8px; display: block;"></i>
                    No documents uploaded for this verification.
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach ($documents as $doc): 
                        $docStatusBadge = match($doc->status) {
                            'approved' => '<span class="label label-success">Approved</span>',
                            'rejected' => '<span class="label label-danger">Rejected</span>',
                            default => '<span class="label label-warning">Pending</span>',
                        };
                    ?>
                        <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                            <div>
                                <strong style="text-transform: capitalize; color: #1e293b; font-size: 14px;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $doc->document_type)); ?>
                                </strong>
                                <?php if (!empty($doc->side)): ?>
                                    <span class="label label-default" style="font-size: 10px; text-transform: uppercase;"><?php echo htmlspecialchars($doc->side); ?></span>
                                <?php endif; ?>
                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                    File: <code><?php echo htmlspecialchars($doc->original_filename); ?></code> &bull; Size: <?php echo round($doc->file_size / 1024, 1); ?> KB
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php echo $docStatusBadge; ?>
                                <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>&download=<?php echo (int) $doc->id; ?>" target="_blank" class="btn btn-primary btn-xs">
                                    <i class="fa fa-external-link"></i> View Document
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Audit Timeline Card -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 20px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 16px 0; font-size: 15px; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                <i class="fa fa-history text-primary"></i> Verification Audit Log
            </h4>

            <?php if (empty($audit)): ?>
                <p style="color: #94a3b8; margin: 0;">No audit entries recorded.</p>
            <?php else: ?>
                <div style="border-left: 2px solid #e2e8f0; padding-left: 16px; margin-left: 8px;">
                    <?php foreach (array_reverse($audit) as $entry): ?>
                        <div style="margin-bottom: 14px; position: relative;">
                            <div style="position: absolute; left: -22px; top: 3px; width: 10px; height: 10px; border-radius: 50%; background: #3b82f6;"></div>
                            <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($entry['ts'] ?? ''); ?></div>
                            <div style="font-weight: 600; color: #1e293b; font-size: 13px;">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $entry['action'] ?? ''))); ?>
                                <?php if (!empty($entry['admin_id'])): ?>
                                    <span style="font-weight: 400; color: #64748b; font-size: 11px;">(Admin #<?php echo (int) $entry['admin_id']; ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($entry['note'])): ?>
                                <div style="font-size: 12px; color: #475569; background: #f8fafc; padding: 4px 8px; border-radius: 4px; margin-top: 4px; display: inline-block;">
                                    <?php echo htmlspecialchars($entry['note']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

