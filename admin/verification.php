<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;
use ClientVerification\Services\VerificationService;
use ClientVerification\Storage\DocumentStorage;

$adminId = (int) ($_SESSION['adminid'] ?? 0);
$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));

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
            $isDownload = ($_GET['mode'] ?? '') === 'download';
            $disposition = $isDownload ? 'attachment' : 'inline';
            header('Content-Type: ' . Sanitizer::headerValue($doc->mime_type ?: 'application/octet-stream'));
            header('Content-Disposition: ' . $disposition . '; filename="' . Sanitizer::headerValue($doc->original_filename) . '"');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Csrf::check($_POST['cv_token'] ?? null)) {
        $feedbackMessage = 'Security token invalid or expired. Please refresh the page and try again.';
        $feedbackType = 'danger';
    } else {
        $action = $_POST['action'];
        $note = trim($_POST['note'] ?? '');

    switch ($action) {
        case 'approve':
            VerificationService::updateStatus($id, 'approved', $adminId, $note);
            $feedbackMessage = 'Verification approved successfully.';
            break;
        case 'reject':
            VerificationService::updateStatus($id, 'rejected', $adminId, $note);
            $feedbackMessage = 'Verification rejected.';
            $feedbackType = 'warning';
            break;
        case 'request_info':
            VerificationService::requestInformation($id, $adminId, $note);
            $feedbackMessage = 'Additional information requested from client.';
            $feedbackType = 'info';
            break;
        case 'manual_review':
            VerificationService::updateStatus($id, 'under_review', $adminId, $note);
            $feedbackMessage = 'Verification marked as under review.';
            $feedbackType = 'info';
            break;
        case 'delete_doc':
            $docId = (int) ($_POST['doc_id'] ?? 0);
            if ($docId > 0 && VerificationService::deleteDocument($docId, $adminId)) {
                $feedbackMessage = "Document #{$docId} has been permanently deleted.";
            } else {
                $feedbackMessage = "Could not delete document #{$docId}.";
                $feedbackType = 'danger';
            }
            break;
        case 'delete':
            VerificationService::delete($id, $adminId);
            header('Location: addonmodules.php?module=clientverification&action=verifications&deleted=1');
            echo '<script>window.location.href = "addonmodules.php?module=clientverification&action=verifications&deleted=1";</script>';
            exit;
    }
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
                'info_requested' => '<span class="label label-info" style="font-size: 13px; padding: 5px 12px; background: #0284c7;">Info Requested</span>',
                'expired' => '<span class="label label-default" style="font-size: 13px; padding: 5px 12px;">Expired</span>',
                default => '<span class="label label-info" style="font-size: 13px; padding: 5px 12px;">Pending</span>',
            };
            ?>

            <table class="table table-condensed" style="margin: 0;">
                <tr>
                    <td style="color: #64748b; width: 130px; border-top: none;">Current Status:</td>
                    <td style="border-top: none;"><?php echo $statusBadge; ?></td>
                </tr>
                <?php if (!empty($row->rejection_reason)): ?>
                    <tr>
                        <td style="color: #64748b;">Decision Note:</td>
                        <td><div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 6px 12px; font-size: 12px; color: #991b1b;"><strong>Reason:</strong> <?php echo htmlspecialchars($row->rejection_reason); ?></div></td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($row->info_request_note)): ?>
                    <tr>
                        <td style="color: #64748b;">Requested Info:</td>
                        <td><div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 6px 12px; font-size: 12px; color: #0369a1;"><strong>Note to client:</strong> <?php echo htmlspecialchars($row->info_request_note); ?></div></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td style="color: #64748b;">Method:</td>
                    <td><strong style="text-transform: capitalize;"><?php echo htmlspecialchars($row->verification_method); ?></strong></td>
                </tr>
                <?php 
                $docNumber = $row->document_number ?? ($personal->document_number ?? '');
                if (empty($docNumber)) {
                    try {
                        $docLog = Capsule::table('mod_cv_audit_logs')
                            ->where('verification_id', $id)
                            ->where('action', 'document_number_saved')
                            ->orderByDesc('id')
                            ->first();
                        if ($docLog && !empty($docLog->details)) {
                            if (preg_match('/Document No:\s*(.+)/i', $docLog->details, $m)) {
                                $docNumber = trim($m[1]);
                            } elseif (preg_match('/number=([^\s\*]+)/i', $docLog->details, $m)) {
                                $docNumber = trim($m[1]);
                            }
                        }
                    } catch (\Throwable $e) {}
                }
                ?>
                <?php if (!empty($docNumber)): ?>
                    <tr>
                        <td style="color: #64748b;">Document / ID No:</td>
                        <td><strong style="color: #0369a1; font-size: 14px; letter-spacing: 0.5px; background: #e0f2fe; padding: 2px 8px; border-radius: 4px; display: inline-block;"><?php echo htmlspecialchars($docNumber); ?></strong></td>
                    </tr>
                <?php endif; ?>
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
                            'approved' => '<span class="label label-success" style="font-size: 11px;">Approved</span>',
                            'rejected' => '<span class="label label-danger" style="font-size: 11px;">Rejected</span>',
                            default => '<span class="label label-warning" style="font-size: 11px;">Pending</span>',
                        };

                        $formattedType = ucwords(str_replace('_', ' ', $doc->document_type));
                        $formattedSide = !empty($doc->side) ? strtoupper($doc->side) : 'FRONT';
                        $docDisplayTitle = $formattedType . ' (' . $formattedSide . ')';
                        $sizeKb = round(($doc->file_size ?? 0) / 1024, 1);
                        $isPdf = stripos($doc->mime_type ?? '', 'pdf') !== false || preg_match('/\.pdf$/i', $doc->original_filename);
                    ?>
                        <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 38px; height: 38px; background: <?php echo $isPdf ? '#fee2e2' : '#eff6ff'; ?>; color: <?php echo $isPdf ? '#dc2626' : '#2563eb'; ?>; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                    <i class="fa <?php echo $isPdf ? 'fa-file-pdf-o' : 'fa-file-image-o'; ?>"></i>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <strong style="color: #1e293b; font-size: 14px;"><?php echo htmlspecialchars($docDisplayTitle); ?></strong>
                                        <?php if ($doc->encrypted): ?>
                                            <span title="Encrypted at rest" style="color: #16a34a; font-size: 12px;"><i class="fa fa-lock"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                        File: <code><?php echo htmlspecialchars($doc->original_filename); ?></code> &bull; Size: <strong><?php echo $sizeKb; ?> KB</strong>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php echo $docStatusBadge; ?>

                                <!-- View Document Popup Button -->
                                <button type="button" class="btn btn-primary btn-sm" style="font-weight: 600;" onclick="cvOpenDocModal(<?php echo (int) $doc->id; ?>, '<?php echo addslashes(htmlspecialchars($docDisplayTitle)); ?>', '<?php echo addslashes(htmlspecialchars($doc->original_filename)); ?>', '<?php echo $sizeKb; ?> KB', '<?php echo addslashes($doc->mime_type ?? ''); ?>');">
                                    <i class="fa fa-eye"></i> View Document
                                </button>

                                <!-- Direct Download Button -->
                                <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>&download=<?php echo (int) $doc->id; ?>&mode=download" target="_blank" class="btn btn-default btn-sm" style="font-weight: 600;">
                                    <i class="fa fa-download"></i> Download
                                </a>

                                <!-- Delete Document Button -->
                                <button type="button" class="btn btn-danger btn-sm" title="Delete document" onclick="if(confirm('Are you sure you want to permanently delete this document (#<?php echo (int)$doc->id; ?>)?')) { document.getElementById('cv_del_doc_<?php echo (int)$doc->id; ?>').submit(); }">
                                    <i class="fa fa-trash"></i>
                                </button>
                                <form method="post" id="cv_del_doc_<?php echo (int)$doc->id; ?>" action="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>" style="display: none;">
                                    <?php echo Csrf::field(); ?>
                                    <input type="hidden" name="action" value="delete_doc">
                                    <input type="hidden" name="doc_id" value="<?php echo (int) $doc->id; ?>">
                                </form>
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

<!-- Interactive Lightbox / Modal Popup for Document Preview -->
<div id="cv_doc_preview_modal" style="display: none; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(4px); overflow-y: auto;">
    <div style="background: #ffffff; width: 800px; max-width: 94%; margin: 35px auto; border-radius: 10px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4); overflow: hidden; position: relative;">
        <!-- Modal Header -->
        <div style="background: #f8fafc; padding: 16px 22px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h4 style="margin: 0 0 3px 0; font-size: 16px; font-weight: 700; color: #1e293b;" id="cv_modal_title">Document Preview</h4>
                <div style="font-size: 12px; color: #64748b;" id="cv_modal_meta">Loading metadata...</div>
            </div>
            <button type="button" onclick="cvCloseDocModal();" style="background: none; border: none; font-size: 26px; color: #64748b; cursor: pointer; line-height: 1; padding: 0 4px;" title="Close">&times;</button>
        </div>

        <!-- Modal Body (Preview Canvas) -->
        <div style="padding: 20px; text-align: center; background: #0f172a; min-height: 380px; display: flex; align-items: center; justify-content: center; position: relative;" id="cv_modal_body">
            <img id="cv_modal_img" src="" alt="Document Preview" style="max-width: 100%; max-height: 560px; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.5); object-fit: contain; display: none;">
            <iframe id="cv_modal_iframe" src="" style="display: none; width: 100%; height: 540px; border: none; border-radius: 6px; background: #ffffff;"></iframe>
            <div id="cv_modal_loader" style="color: #ffffff; font-size: 14px;">
                <i class="fa fa-spinner fa-spin fa-2x"></i><br>
                <span style="display: inline-block; margin-top: 8px;">Loading document preview...</span>
            </div>
        </div>

        <!-- Modal Footer -->
        <div style="background: #f8fafc; padding: 14px 22px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <span style="font-size: 12px; color: #64748b;">
                <i class="fa fa-shield text-success"></i> Encrypted KYC Verification Document
            </span>
            <div style="display: flex; gap: 8px;">
                <a id="cv_modal_download_btn" href="#" target="_blank" class="btn btn-primary" style="font-weight: 600;">
                    <i class="fa fa-download"></i> Download Document
                </a>
                <button type="button" class="btn btn-default" onclick="cvCloseDocModal();" style="font-weight: 600;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function cvOpenDocModal(docId, docTitle, filename, fileSize, mimeType) {
    var modal = document.getElementById('cv_doc_preview_modal');
    var titleElem = document.getElementById('cv_modal_title');
    var metaElem = document.getElementById('cv_modal_meta');
    var imgElem = document.getElementById('cv_modal_img');
    var iframeElem = document.getElementById('cv_modal_iframe');
    var loaderElem = document.getElementById('cv_modal_loader');
    var downloadBtn = document.getElementById('cv_modal_download_btn');

    var viewUrl = 'addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>&download=' + docId + '&mode=inline';
    var downloadUrl = 'addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $id; ?>&download=' + docId + '&mode=download';

    titleElem.textContent = docTitle;
    metaElem.textContent = 'File: ' + filename + ' • Size: ' + fileSize;
    downloadBtn.href = downloadUrl;

    if (loaderElem) loaderElem.style.display = 'block';
    if (imgElem) imgElem.style.display = 'none';
    if (iframeElem) iframeElem.style.display = 'none';

    if (mimeType && mimeType.indexOf('pdf') !== -1) {
        iframeElem.src = viewUrl;
        iframeElem.onload = function() {
            if (loaderElem) loaderElem.style.display = 'none';
            iframeElem.style.display = 'block';
        };
    } else {
        imgElem.src = viewUrl;
        imgElem.onload = function() {
            if (loaderElem) loaderElem.style.display = 'none';
            imgElem.style.display = 'inline-block';
        };
        imgElem.onerror = function() {
            if (loaderElem) loaderElem.innerHTML = '<span style="color: #f87171;"><i class="fa fa-exclamation-triangle"></i> Failed to preview document image. Please use the download button below.</span>';
        };
    }

    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function cvCloseDocModal() {
    var modal = document.getElementById('cv_doc_preview_modal');
    var iframeElem = document.getElementById('cv_modal_iframe');
    var imgElem = document.getElementById('cv_modal_img');
    if (iframeElem) iframeElem.src = '';
    if (imgElem) imgElem.src = '';
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal when pressing Escape key or clicking on dark overlay backdrop
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        cvCloseDocModal();
    }
});

document.getElementById('cv_doc_preview_modal').addEventListener('click', function(e) {
    if (e.target === this) {
        cvCloseDocModal();
    }
});
</script>

