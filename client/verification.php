<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

$clientId = (int) (($_SESSION['clientsdetails']['userid'] ?? 0) ?: ($_SESSION['uid'] ?? 0));
$id = (int) ($_GET['id'] ?? 0);
$v = Capsule::table('mod_cv_verifications')->where('id', $id)->where('client_id', $clientId)->first();

if (!$v) {
    echo '<div class="alert alert-danger" style="margin: 20px auto; max-width: 650px; border-radius: 8px;"><i class="fa fa-exclamation-triangle"></i> Verification session not found or access denied.</div>';
    return;
}

$statusBadge = match($v->status) {
    'approved' => '<span class="label label-success" style="font-size: 12px; padding: 4px 10px;">Approved</span>',
    'rejected' => '<span class="label label-danger" style="font-size: 12px; padding: 4px 10px;">Rejected</span>',
    'under_review' => '<span class="label label-warning" style="font-size: 12px; padding: 4px 10px;">Under Review</span>',
    'expired' => '<span class="label label-default" style="font-size: 12px; padding: 4px 10px;">Expired</span>',
    default => '<span class="label label-info" style="font-size: 12px; padding: 4px 10px;">Pending Documents</span>',
};

$docs = Capsule::table('mod_cv_documents')->where('verification_id', $id)->get();
$types = Capsule::table('mod_cv_document_types')->where('is_required', 1)->get();
?>

<div style="max-width: 720px; margin: 30px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); overflow: hidden; margin-bottom: 24px;">
        <div style="padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #1e293b;">
                    Identity Verification <span style="color: #64748b; font-weight: 400; font-size: 14px;">(#<?php echo (int) $v->id; ?>)</span>
                </h3>
            </div>
            <div>
                <?php echo $statusBadge; ?>
            </div>
        </div>

        <div style="padding: 24px;">
            <?php if ($v->status === 'approved'): ?>
                <div class="alert alert-success" style="border-radius: 8px;">
                    <i class="fa fa-check-circle"></i> Your identity documents have been approved. No further action is required.
                </div>
            <?php elseif ($v->status === 'under_review'): ?>
                <div class="alert alert-info" style="border-radius: 8px;">
                    <i class="fa fa-clock-o"></i> Your documents have been submitted and are currently in the compliance review queue.
                </div>
            <?php else: ?>
                <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;">
                    Please upload high-quality, uncropped photos or scans of the required identification documents below. Accepted formats: JPG, PNG, PDF.
                </p>

                <form method="post" enctype="multipart/form-data" action="index.php?m=clientverification&action=document">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="verification_id" value="<?php echo (int) $id; ?>">

                    <?php foreach ($types as $t): 
                        $sides = (int) ($t->sides_required ?? 1);
                    ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 18px;">
                            <h4 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 700; color: #1e293b;">
                                <i class="fa fa-id-card-o text-primary"></i> <?php echo htmlspecialchars($t->label); ?>
                            </h4>
                            
                            <?php if ($sides >= 2): ?>
                                <div class="row">
                                    <div class="col-sm-6" style="margin-bottom: 10px;">
                                        <label style="font-size: 12px; font-weight: 600; color: #475569;">Front Side:</label>
                                        <input type="file" name="doc_<?php echo htmlspecialchars($t->name); ?>__front" class="form-control" required accept=".pdf,.png,.jpg,.jpeg,.webp">
                                    </div>
                                    <div class="col-sm-6" style="margin-bottom: 10px;">
                                        <label style="font-size: 12px; font-weight: 600; color: #475569;">Back Side:</label>
                                        <input type="file" name="doc_<?php echo htmlspecialchars($t->name); ?>__back" class="form-control" required accept=".pdf,.png,.jpg,.jpeg,.webp">
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="form-group" style="margin: 0;">
                                    <label style="font-size: 12px; font-weight: 600; color: #475569;">Document File:</label>
                                    <input type="file" name="doc_<?php echo htmlspecialchars($t->name); ?>" class="form-control" required accept=".pdf,.png,.jpg,.jpeg,.webp">
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary btn-lg" style="font-weight: 600; padding: 12px 28px; width: 100%;">
                        <i class="fa fa-upload"></i> Submit Documents for Verification
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!$docs->isEmpty()): ?>
                <div style="margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <h4 style="margin: 0 0 14px 0; font-size: 15px; font-weight: 700; color: #1e293b;">Uploaded Documents</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($docs as $d): 
                            $docBadge = match($d->status) {
                                'approved' => '<span class="label label-success">Approved</span>',
                                'rejected' => '<span class="label label-danger">Rejected</span>',
                                default => '<span class="label label-warning">Pending Review</span>',
                            };
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                <div>
                                    <strong style="text-transform: capitalize; color: #1e293b;"><?php echo htmlspecialchars(str_replace('_', ' ', $d->document_type)); ?></strong>
                                    <?php if ($d->side): ?>
                                        <span class="label label-default" style="font-size: 10px;"><?php echo htmlspecialchars(strtoupper($d->side)); ?></span>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;"><?php echo htmlspecialchars($d->original_filename); ?></div>
                                </div>
                                <div>
                                    <?php echo $docBadge; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

