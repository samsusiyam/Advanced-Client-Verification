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
            <?php elseif ($v->status === 'under_review'): ?>
                <div class="alert alert-info" style="border-radius: 8px; margin-bottom: 20px;">
                    <i class="fa fa-clock-o"></i> Your documents have been submitted and are currently in the compliance review queue.
                </div>
            <?php else: ?>
                <!-- Manual Upload Header Card -->
                <div style="background: #eff6ff; border: 1px solid #dbeafe; border-radius: 10px; padding: 18px 22px; margin-bottom: 24px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                        <i class="fa fa-cloud-upload" style="font-size: 20px; color: #2563eb;"></i>
                        <h4 style="margin: 0; font-size: 18px; font-weight: 700; color: #2563eb;">Manual Document Upload</h4>
                    </div>
                    <p style="margin: 0; color: #3b82f6; font-size: 13px;">Upload your identification documents for manual review by our team.</p>
                </div>

                <form method="post" enctype="multipart/form-data" action="index.php?m=clientverification&action=document" id="cv_manual_form">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="verification_id" value="<?php echo (int) $id; ?>">

                    <!-- Document Type Dropdown -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-id-card-o" style="color: #64748b;"></i> Document Type <span style="color: #ef4444;">*</span>
                        </label>
                        <select name="document_type" id="cv_doc_type" class="form-control" style="height: 44px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;" required>
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
                        <input type="text" name="document_number" id="cv_doc_number" class="form-control" placeholder="Enter your document number" required style="height: 44px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <div style="font-size: 12px; color: #64748b; margin-top: 5px;">Enter the number exactly as shown on your document</div>
                    </div>

                    <!-- Document Front Side -->
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-picture-o" style="color: #64748b;"></i> Document Front Side <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="file" name="doc_front" id="file_front" accept="image/*,.pdf" required style="position: absolute; opacity: 0; width: 1px; height: 1px;">
                        <div class="cv-dropzone" id="dropzone_front">
                            <div class="cv-dz-placeholder" id="placeholder_front">
                                <div class="cv-dz-icon"><i class="fa fa-camera"></i></div>
                                <div class="cv-dz-title">Click to upload front side</div>
                                <div class="cv-dz-subtitle">Max 5MB | JPG, PNG, WEBP, PDF</div>
                            </div>
                            <div class="cv-dz-preview" id="preview_front" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- Document Back Side (Dynamic) -->
                    <div class="form-group" id="group_back" style="margin-bottom: 22px;">
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-picture-o" style="color: #64748b;"></i> Document Back Side <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="file" name="doc_back" id="file_back" accept="image/*,.pdf" style="position: absolute; opacity: 0; width: 1px; height: 1px;">
                        <div class="cv-dropzone" id="dropzone_back">
                            <div class="cv-dz-placeholder" id="placeholder_back">
                                <div class="cv-dz-icon"><i class="fa fa-camera"></i></div>
                                <div class="cv-dz-title">Click to upload back side</div>
                                <div class="cv-dz-subtitle">Max 5MB | JPG, PNG, WEBP, PDF</div>
                            </div>
                            <div class="cv-dz-preview" id="preview_back" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- Selfie Photo -->
                    <div class="form-group" style="margin-bottom: 28px;">
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                            <i class="fa fa-user" style="color: #64748b;"></i> Selfie Photo <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="file" name="doc_selfie" id="file_selfie" accept="image/*,.pdf" required style="position: absolute; opacity: 0; width: 1px; height: 1px;">
                        <div class="cv-dropzone" id="dropzone_selfie">
                            <div class="cv-dz-placeholder" id="placeholder_selfie">
                                <div class="cv-dz-icon"><i class="fa fa-user-circle"></i></div>
                                <div class="cv-dz-title">Click to upload your selfie</div>
                                <div class="cv-dz-subtitle">Clear photo of your face | Max 5MB</div>
                            </div>
                            <div class="cv-dz-preview" id="preview_selfie" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <button type="submit" id="btn_submit_cv" class="btn" style="background: #16a34a; color: #ffffff; font-weight: 700; font-size: 15px; padding: 12px 36px; border-radius: 6px; border: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(22, 163, 74, 0.25); cursor: pointer;">
                            <i class="fa fa-check-circle"></i> Submit for Verification
                        </button>
                    </div>

                    <!-- Encrypted Footer -->
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 14px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8;">
                        <div><i class="fa fa-lock"></i> Your data is encrypted and secure</div>
                        <div>Current Time: <?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                </form>

                <style>
                    .cv-dropzone {
                        background: #f8fafc;
                        border: 2px dashed #cbd5e1;
                        border-radius: 8px;
                        padding: 24px 16px;
                        text-align: center;
                        cursor: pointer;
                        transition: all 0.2s ease-in-out;
                        position: relative;
                        user-select: none;
                    }
                    .cv-dropzone:hover {
                        border-color: #3b82f6;
                        background: #f1f5f9;
                    }
                    .cv-dz-icon {
                        font-size: 36px;
                        color: #2563eb;
                        margin-bottom: 8px;
                    }
                    .cv-dz-title {
                        font-size: 14px;
                        font-weight: 600;
                        color: #1e293b;
                        margin-bottom: 4px;
                    }
                    .cv-dz-subtitle {
                        font-size: 12px;
                        color: #64748b;
                    }
                    .cv-preview-img {
                        max-height: 160px;
                        max-width: 100%;
                        border-radius: 6px;
                        border: 1px solid #e2e8f0;
                        margin-bottom: 8px;
                        display: inline-block;
                    }
                    .cv-file-badge {
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        background: #e2e8f0;
                        padding: 4px 12px;
                        border-radius: 4px;
                        font-size: 12px;
                        color: #334155;
                        font-weight: 600;
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
                document.addEventListener('DOMContentLoaded', function() {
                    var docTypeSelect = document.getElementById('cv_doc_type');
                    var groupBack = document.getElementById('group_back');
                    var fileBack = document.getElementById('file_back');

                    function handleTypeChange() {
                        var opt = docTypeSelect.options[docTypeSelect.selectedIndex];
                        var sides = parseInt(opt.getAttribute('data-sides') || '1');
                        
                        if (sides >= 2) {
                            groupBack.style.display = 'block';
                            fileBack.required = true;
                        } else {
                            groupBack.style.display = 'none';
                            fileBack.required = false;
                        }

                        // Reset file selections and previews on document type switch
                        resetDropzone('front');
                        resetDropzone('back');
                    }

                    docTypeSelect.addEventListener('change', handleTypeChange);
                    handleTypeChange();

                    function setupDropzone(key) {
                        var fileInput = document.getElementById('file_' + key);
                        var dropzone = document.getElementById('dropzone_' + key);
                        var placeholder = document.getElementById('placeholder_' + key);
                        var preview = document.getElementById('preview_' + key);

                        if (!fileInput || !dropzone) return;

                        // Clicking anywhere on dropzone opens file dialog
                        dropzone.addEventListener('click', function(e) {
                            fileInput.click();
                        });

                        fileInput.addEventListener('change', function(e) {
                            if (fileInput.files && fileInput.files[0]) {
                                showPreview(fileInput.files[0], placeholder, preview, key);
                            }
                        });

                        // Drag and drop handlers
                        ['dragenter', 'dragover'].forEach(function(eventName) {
                            dropzone.addEventListener(eventName, function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                dropzone.style.borderColor = '#2563eb';
                                dropzone.style.background = '#eff6ff';
                            }, false);
                        });

                        ['dragleave', 'drop'].forEach(function(eventName) {
                            dropzone.addEventListener(eventName, function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                dropzone.style.borderColor = '#cbd5e1';
                                dropzone.style.background = '#f8fafc';
                            }, false);
                        });

                        dropzone.addEventListener('drop', function(e) {
                            var dt = e.dataTransfer;
                            if (dt && dt.files && dt.files[0]) {
                                fileInput.files = dt.files;
                                showPreview(dt.files[0], placeholder, preview, key);
                            }
                        }, false);
                    }

                    function showPreview(file, placeholder, preview, key) {
                        if (!file) return;
                        placeholder.style.display = 'none';
                        preview.style.display = 'block';
                        preview.innerHTML = '<div style="padding: 10px; color: #64748b;"><i class="fa fa-spinner fa-spin"></i> Loading preview...</div>';

                        var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                        var isImage = (file.type && file.type.indexOf('image') !== -1) || /\.(jpe?g|png|webp|gif|bmp)$/i.test(file.name);

                        if (isImage) {
                            var reader = new FileReader();
                            reader.onload = function(e) {
                                preview.innerHTML = '<img src="' + e.target.result + '" class="cv-preview-img"><br>' +
                                    '<span class="cv-file-badge"><i class="fa fa-check-circle" style="color: #16a34a;"></i> ' + escapeHtml(file.name) + ' (' + sizeMb + ' MB)</span>' +
                                    '<div class="cv-btn-change">Click box to change file</div>';
                            };
                            reader.onerror = function() {
                                preview.innerHTML = '<span class="cv-file-badge"><i class="fa fa-file-image-o"></i> ' + escapeHtml(file.name) + ' (' + sizeMb + ' MB)</span>' +
                                    '<div class="cv-btn-change">Click box to change file</div>';
                            };
                            reader.readAsDataURL(file);
                        } else {
                            preview.innerHTML = '<div style="font-size: 38px; color: #dc2626; margin-bottom: 6px;"><i class="fa fa-file-pdf-o"></i></div>' +
                                '<span class="cv-file-badge"><i class="fa fa-check-circle" style="color: #16a34a;"></i> ' + escapeHtml(file.name) + ' (' + sizeMb + ' MB)</span>' +
                                '<div class="cv-btn-change">Click box to change file</div>';
                        }
                    }

                    window.resetDropzone = function(key) {
                        var fileInput = document.getElementById('file_' + key);
                        var placeholder = document.getElementById('placeholder_' + key);
                        var preview = document.getElementById('preview_' + key);
                        if (fileInput) fileInput.value = '';
                        if (placeholder) placeholder.style.display = 'block';
                        if (preview) {
                            preview.style.display = 'none';
                            preview.innerHTML = '';
                        }
                    };

                    function escapeHtml(str) {
                        var div = document.createElement('div');
                        div.appendChild(document.createTextNode(str));
                        return div.innerHTML;
                    }

                    setupDropzone('front');
                    setupDropzone('back');
                    setupDropzone('selfie');
                });
                </script>
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


