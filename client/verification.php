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
cv_insert_default_document_types();
$types = Capsule::table('mod_cv_document_types')
    ->whereIn('name', ['national_id', 'passport', 'drivers_license', 'birth_certificate'])
    ->orderByRaw("FIELD(name, 'national_id', 'passport', 'drivers_license', 'birth_certificate')")
    ->get();

if ($types->isEmpty()) {
    $types = collect([
        (object) ['name' => 'national_id', 'label' => 'National ID Card', 'sides_required' => 2],
        (object) ['name' => 'passport', 'label' => 'Passport', 'sides_required' => 1],
        (object) ['name' => 'drivers_license', 'label' => "Driver's License", 'sides_required' => 2],
        (object) ['name' => 'birth_certificate', 'label' => 'Birth Certificate', 'sides_required' => 1],
    ]);
}
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
                <div class="alert alert-success" style="border-radius: 8px; margin: 0;">
                    <i class="fa fa-check-circle"></i> Your identity documents have been approved. No further action is required.
                </div>
            <?php elseif ($v->status === 'rejected'): 
                $rejectionReason = $v->rejection_reason ?? '';
                if (empty($rejectionReason)) {
                    try {
                        $lastRejectLog = Capsule::table('mod_cv_audit_logs')
                            ->where('verification_id', $id)
                            ->whereIn('action', ['status_rejected', 'rejected', 'admin_rejected'])
                            ->orderByDesc('id')
                            ->first();
                        if ($lastRejectLog && !empty($lastRejectLog->details) && $lastRejectLog->details !== 'admin_rejected') {
                            $rejectionReason = $lastRejectLog->details;
                        }
                    } catch (\Throwable $e) {}
                }
            ?>
                <div style="text-align: center; padding: 10px 10px 20px 10px;">
                    <div style="width: 72px; height: 72px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px auto; font-size: 32px;">
                        <i class="fa fa-times"></i>
                    </div>
                    <h3 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 700; color: #991b1b;">Verification Unsuccessful</h3>
                    <p style="color: #64748b; font-size: 14px; margin-bottom: 24px;">Your identity verification could not be approved based on the submitted documents.</p>

                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px 20px; margin-bottom: 28px; text-align: left; max-width: 580px; margin-left: auto; margin-right: auto;">
                        <div style="font-size: 13px; font-weight: 700; color: #991b1b; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <i class="fa fa-exclamation-circle"></i> Reason for Rejection:
                        </div>
                        <div style="font-size: 14px; color: #7f1d1d; line-height: 1.5;">
                            <?php echo htmlspecialchars($rejectionReason ?: 'The submitted documents were unclear, invalid, or did not match your account information. Please submit new, clear documents.'); ?>
                        </div>
                    </div>

                    <a href="index.php?m=clientverification&action=start" class="btn btn-primary btn-lg" style="font-weight: 700; padding: 12px 36px; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">
                        <i class="fa fa-refresh"></i> Start New Verification &raquo;
                    </a>
                </div>
            <?php else: 
                $enableDidit = cv_setting('enable_didit', 'yes') === 'yes' && !empty($config['didit_api_key'] ?? '') && !empty($config['didit_workflow_id'] ?? '');
                $globalMode = $config['verification_mode'] ?? 'hybrid';
                $canDidit = $enableDidit && in_array($globalMode, ['hybrid', 'didit']);
            ?>
                <?php if ($canDidit): ?>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 18px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px; font-size: 13px; color: #166534;">
                            <i class="fa fa-bolt" style="color: #16a34a; font-size: 18px;"></i>
                            <span>Prefer instant AI automated verification? (Instant 1-2 min approval)</span>
                        </div>
                        <a href="index.php?m=clientverification&action=start&method=didit" class="btn btn-success btn-sm" style="font-weight: 600; padding: 6px 16px; background: #16a34a; border-color: #16a34a;">
                            <i class="fa fa-flash"></i> Switch to Didit AI &raquo;
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Manual Upload Header Card -->
                <div style="background: #eff6ff; border: 1px solid #dbeafe; border-radius: 10px; padding: 18px 22px; margin-bottom: 24px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                        <i class="fa fa-cloud-upload" style="font-size: 20px; color: #2563eb;"></i>
                        <h4 style="margin: 0; font-size: 18px; font-weight: 700; color: #2563eb;">Manual Document Upload</h4>
                    </div>
                    <p style="margin: 0; color: #3b82f6; font-size: 13px;">Upload clear photos or scans of your identification documents for review by our compliance team.</p>
                </div>

                <form method="post" enctype="multipart/form-data" action="index.php?m=clientverification&action=document" id="cv_manual_form" onsubmit="return cvValidateUploadForm();">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="verification_id" value="<?php echo (int) $id; ?>">

                    <!-- Document Type Dropdown -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-id-card-o" style="color: #64748b;"></i> Document Type <span style="color: #ef4444;">*</span>
                        </label>
                        <select name="document_type" id="cv_doc_type" class="form-control" style="height: 44px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;" required onchange="cvHandleDocTypeChange(this)">
                            <?php foreach ($types as $t): 
                                $sides = (int) ($t->sides_required ?? 1);
                            ?>
                                <option value="<?php echo htmlspecialchars($t->name); ?>" data-sides="<?php echo $sides; ?>" <?php echo ($t->name === 'national_id' ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($t->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Document Number Field -->
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                            <i class="fa fa-hashtag" style="color: #64748b;"></i> Document Number <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" name="document_number" id="cv_doc_number" class="form-control" placeholder="Enter your document / ID number" required style="height: 44px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <div style="font-size: 12px; color: #64748b; margin-top: 5px;">Enter the number exactly as shown on your document</div>
                    </div>

                    <!-- Document Front Side -->
                    <div class="form-group" style="margin-bottom: 22px;">
                        <div style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-picture-o" style="color: #64748b;"></i> Document Front Side <span style="color: #ef4444;">*</span>
                        </div>
                        <input type="file" name="doc_front" id="file_front" accept="image/jpeg,image/png,image/webp,application/pdf" style="display: none;" onchange="cvHandleFileChange(this, 'front')">
                        <div class="cv-dropzone" id="dropzone_front" onclick="document.getElementById('file_front').click();">
                            <div class="cv-dz-placeholder" id="placeholder_front">
                                <div class="cv-dz-icon"><i class="fa fa-camera"></i></div>
                                <div class="cv-dz-title">Click or drag file to upload front side</div>
                                <div class="cv-dz-subtitle">Max 10MB &bull; JPG, PNG, WEBP, PDF</div>
                            </div>
                            <div class="cv-dz-preview" id="preview_front" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- Document Back Side (Dynamic) -->
                    <div class="form-group" id="group_back" style="margin-bottom: 22px;">
                        <div style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-picture-o" style="color: #64748b;"></i> Document Back Side <span style="color: #ef4444;">*</span>
                        </div>
                        <input type="file" name="doc_back" id="file_back" accept="image/jpeg,image/png,image/webp,application/pdf" style="display: none;" onchange="cvHandleFileChange(this, 'back')">
                        <div class="cv-dropzone" id="dropzone_back" onclick="document.getElementById('file_back').click();">
                            <div class="cv-dz-placeholder" id="placeholder_back">
                                <div class="cv-dz-icon"><i class="fa fa-camera"></i></div>
                                <div class="cv-dz-title">Click or drag file to upload back side</div>
                                <div class="cv-dz-subtitle">Max 10MB &bull; JPG, PNG, WEBP, PDF</div>
                            </div>
                            <div class="cv-dz-preview" id="preview_back" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- Selfie Photo -->
                    <div class="form-group" style="margin-bottom: 28px;">
                        <div style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-user" style="color: #64748b;"></i> Selfie Photo <span style="color: #ef4444;">*</span>
                        </div>
                        <input type="file" name="doc_selfie" id="file_selfie" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="cvHandleFileChange(this, 'selfie')">
                        <div class="cv-dropzone" id="dropzone_selfie" onclick="document.getElementById('file_selfie').click();">
                            <div class="cv-dz-placeholder" id="placeholder_selfie">
                                <div class="cv-dz-icon"><i class="fa fa-user-circle"></i></div>
                                <div class="cv-dz-title">Click or drag file to upload your selfie</div>
                                <div class="cv-dz-subtitle">Clear photo of your face &bull; Max 10MB</div>
                            </div>
                            <div class="cv-dz-preview" id="preview_selfie" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <button type="submit" id="btn_submit_cv" class="btn btn-success btn-lg" style="background: #16a34a; border-color: #16a34a; color: #ffffff; font-weight: 700; font-size: 16px; padding: 12px 40px; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(22, 163, 74, 0.3); cursor: pointer;">
                            <i class="fa fa-check-circle"></i> Submit for Verification
                        </button>
                    </div>

                    <!-- Encrypted Footer -->
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 14px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8;">
                        <div><i class="fa fa-lock"></i> Your data is encrypted and strictly protected</div>
                        <div>Secure Verification Portal</div>
                    </div>
                </form>

                <style>
                    .cv-dropzone {
                        background: #f8fafc;
                        border: 2px dashed #cbd5e1;
                        border-radius: 8px;
                        padding: 20px 14px;
                        text-align: center;
                        cursor: pointer;
                        transition: all 0.2s ease-in-out;
                        position: relative;
                        display: block;
                        width: 100%;
                        max-width: 100%;
                        box-sizing: border-box;
                        overflow: hidden;
                        margin: 0;
                        user-select: none;
                    }
                    .cv-dropzone:hover {
                        border-color: #2563eb;
                        background: #eff6ff;
                    }
                    .cv-dz-icon {
                        font-size: 36px;
                        color: #2563eb;
                        margin-bottom: 6px;
                        pointer-events: none;
                    }
                    .cv-dz-title {
                        font-size: 14px;
                        font-weight: 600;
                        color: #1e293b;
                        margin-bottom: 4px;
                        pointer-events: none;
                    }
                    .cv-dz-subtitle {
                        font-size: 12px;
                        color: #64748b;
                        pointer-events: none;
                    }
                    .cv-preview-img {
                        max-height: 180px;
                        max-width: 100%;
                        border-radius: 6px;
                        border: 1px solid #cbd5e1;
                        margin-bottom: 8px;
                        display: inline-block;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                        object-fit: contain;
                    }
                    .cv-file-badge {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        gap: 6px;
                        background: #e2e8f0;
                        padding: 6px 12px;
                        border-radius: 6px;
                        font-size: 12px;
                        color: #1e293b;
                        font-weight: 600;
                        max-width: 95%;
                        box-sizing: border-box;
                    }
                    .cv-filename-text {
                        max-width: 160px;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        display: inline-block;
                        vertical-align: middle;
                    }
                    .cv-btn-change {
                        margin-top: 8px;
                        font-size: 12px;
                        color: #2563eb;
                        font-weight: 600;
                        text-decoration: underline;
                        cursor: pointer;
                    }
                </style>

                <script>
                function cvEscapeHtml(str) {
                    var div = document.createElement('div');
                    div.appendChild(document.createTextNode(str));
                    return div.innerHTML;
                }

                function cvHandleDocTypeChange(selectElem) {
                    if (!selectElem) return;
                    var opt = selectElem.options[selectElem.selectedIndex];
                    var sides = parseInt(opt.getAttribute('data-sides') || '1');
                    var groupBack = document.getElementById('group_back');
                    
                    if (groupBack) {
                        if (sides >= 2) {
                            groupBack.style.display = 'block';
                        } else {
                            groupBack.style.display = 'none';
                            cvResetDropzone('back');
                        }
                    }

                    // Reset files when changing document type to avoid submitting passport as NID back
                    cvResetDropzone('front');
                    if (sides >= 2) {
                        cvResetDropzone('back');
                    }
                }

                function cvHandleFileChange(input, key) {
                    var placeholder = document.getElementById('placeholder_' + key);
                    var preview = document.getElementById('preview_' + key);
                    var dropzone = document.getElementById('dropzone_' + key);

                    if (!input || !input.files || !input.files[0]) {
                        return;
                    }

                    var file = input.files[0];
                    var sizeMb = (file.size / (1024 * 1024)).toFixed(2);

                    if (placeholder) placeholder.style.display = 'none';
                    if (preview) {
                        preview.style.display = 'block';
                        preview.innerHTML = '<div style="padding: 10px; color: #64748b;"><i class="fa fa-spinner fa-spin"></i> Processing preview...</div>';
                    }
                    if (dropzone) {
                        dropzone.style.borderColor = '#16a34a';
                        dropzone.style.background = '#f0fdf4';
                    }

                    var isImage = (file.type && file.type.indexOf('image') !== -1) || /\.(jpe?g|png|webp|gif|bmp)$/i.test(file.name);

                    if (isImage) {
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            if (preview) {
                                preview.innerHTML = '<img src="' + e.target.result + '" class="cv-preview-img"><br>' +
                                    '<span class="cv-file-badge">' +
                                        '<i class="fa fa-check-circle" style="color: #16a34a; flex-shrink: 0;"></i> ' +
                                        '<span class="cv-filename-text" title="' + cvEscapeHtml(file.name) + '">' + cvEscapeHtml(file.name) + '</span> ' +
                                        '<span style="color: #64748b; flex-shrink: 0; font-weight: normal;">(' + sizeMb + ' MB)</span>' +
                                    '</span>' +
                                    '<div class="cv-btn-change"><i class="fa fa-refresh"></i> Click to change file</div>';
                            }
                        };
                        reader.onerror = function() {
                            if (preview) {
                                preview.innerHTML = '<span class="cv-file-badge">' +
                                        '<i class="fa fa-file-image-o" style="flex-shrink: 0;"></i> ' +
                                        '<span class="cv-filename-text" title="' + cvEscapeHtml(file.name) + '">' + cvEscapeHtml(file.name) + '</span> ' +
                                        '<span style="color: #64748b; flex-shrink: 0; font-weight: normal;">(' + sizeMb + ' MB)</span>' +
                                    '</span>' +
                                    '<div class="cv-btn-change"><i class="fa fa-refresh"></i> Click to change file</div>';
                            }
                        };
                        reader.readAsDataURL(file);
                    } else {
                        if (preview) {
                            preview.innerHTML = '<div style="font-size: 38px; color: #dc2626; margin-bottom: 6px;"><i class="fa fa-file-pdf-o"></i></div>' +
                                '<span class="cv-file-badge">' +
                                    '<i class="fa fa-check-circle" style="color: #16a34a; flex-shrink: 0;"></i> ' +
                                    '<span class="cv-filename-text" title="' + cvEscapeHtml(file.name) + '">' + cvEscapeHtml(file.name) + '</span> ' +
                                    '<span style="color: #64748b; flex-shrink: 0; font-weight: normal;">(' + sizeMb + ' MB)</span>' +
                                '</span>' +
                                '<div class="cv-btn-change"><i class="fa fa-refresh"></i> Click to change file</div>';
                        }
                    }
                }

                function cvResetDropzone(key) {
                    var fileInput = document.getElementById('file_' + key);
                    var placeholder = document.getElementById('placeholder_' + key);
                    var preview = document.getElementById('preview_' + key);
                    var dropzone = document.getElementById('dropzone_' + key);

                    if (fileInput) fileInput.value = '';
                    if (placeholder) placeholder.style.display = 'block';
                    if (preview) {
                        preview.style.display = 'none';
                        preview.innerHTML = '';
                    }
                    if (dropzone) {
                        dropzone.style.borderColor = '#cbd5e1';
                        dropzone.style.background = '#f8fafc';
                    }
                }

                function cvValidateUploadForm() {
                    var docNum = document.getElementById('cv_doc_number');
                    if (!docNum || !docNum.value.trim()) {
                        alert('Please enter your Document / ID Number.');
                        if (docNum) docNum.focus();
                        return false;
                    }

                    var front = document.getElementById('file_front');
                    if (!front || !front.files || !front.files[0]) {
                        alert('Please select the Document Front Side photo/document.');
                        var dzFront = document.getElementById('dropzone_front');
                        if (dzFront) {
                            dzFront.style.borderColor = '#ef4444';
                            dzFront.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        return false;
                    }

                    var docTypeSelect = document.getElementById('cv_doc_type');
                    var sides = 1;
                    if (docTypeSelect) {
                        var opt = docTypeSelect.options[docTypeSelect.selectedIndex];
                        sides = parseInt(opt.getAttribute('data-sides') || '1');
                    }

                    if (sides >= 2) {
                        var back = document.getElementById('file_back');
                        if (!back || !back.files || !back.files[0]) {
                            alert('Please select the Document Back Side photo.');
                            var dzBack = document.getElementById('dropzone_back');
                            if (dzBack) {
                                dzBack.style.borderColor = '#ef4444';
                                dzBack.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                            return false;
                        }
                    }

                    var selfie = document.getElementById('file_selfie');
                    if (!selfie || !selfie.files || !selfie.files[0]) {
                        alert('Please select your Selfie photo.');
                        var dzSelfie = document.getElementById('dropzone_selfie');
                        if (dzSelfie) {
                            dzSelfie.style.borderColor = '#ef4444';
                            dzSelfie.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        return false;
                    }

                    var submitBtn = document.getElementById('btn_submit_cv');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading & Encrypting...';
                    }
                    return true;
                }

                function cvInitDragDrop(key) {
                    var dropzone = document.getElementById('dropzone_' + key);
                    var fileInput = document.getElementById('file_' + key);
                    if (!dropzone || !fileInput) return;

                    ['dragenter', 'dragover'].forEach(function(evt) {
                        dropzone.addEventListener(evt, function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            dropzone.style.borderColor = '#2563eb';
                            dropzone.style.background = '#eff6ff';
                        }, false);
                    });

                    ['dragleave', 'drop'].forEach(function(evt) {
                        dropzone.addEventListener(evt, function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (evt === 'dragleave') {
                                if (fileInput.files && fileInput.files[0]) {
                                    dropzone.style.borderColor = '#16a34a';
                                    dropzone.style.background = '#f0fdf4';
                                } else {
                                    dropzone.style.borderColor = '#cbd5e1';
                                    dropzone.style.background = '#f8fafc';
                                }
                            }
                        }, false);
                    });

                    dropzone.addEventListener('drop', function(e) {
                        var dt = e.dataTransfer;
                        if (dt && dt.files && dt.files[0]) {
                            fileInput.files = dt.files;
                            cvHandleFileChange(fileInput, key);
                        }
                    }, false);
                }

                function cvInitAllDropzones() {
                    cvInitDragDrop('front');
                    cvInitDragDrop('back');
                    cvInitDragDrop('selfie');

                    var select = document.getElementById('cv_doc_type');
                    if (select) {
                        cvHandleDocTypeChange(select);
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', cvInitAllDropzones);
                } else {
                    cvInitAllDropzones();
                }
                </script>
            <?php endif; ?>

            <?php if (!$docs->isEmpty() && in_array($v->status, ['under_review', 'approved'])): ?>
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


