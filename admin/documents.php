<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Storage\DocumentStorage;

// Secure document stream / download handler
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
    echo 'Document file not found';
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

// Query verifications that have associated documents
$verifQuery = Capsule::table('mod_cv_verifications')
    ->leftJoin('tblclients', 'mod_cv_verifications.client_id', '=', 'tblclients.id')
    ->whereExists(function ($q) {
        $q->select(Capsule::raw(1))
          ->from('mod_cv_documents')
          ->whereRaw('mod_cv_documents.verification_id = mod_cv_verifications.id');
    })
    ->select('mod_cv_verifications.*', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email');

if ($statusFilter) {
    $verifQuery->whereExists(function ($q) use ($statusFilter) {
        $q->select(Capsule::raw(1))
          ->from('mod_cv_documents')
          ->whereRaw('mod_cv_documents.verification_id = mod_cv_verifications.id')
          ->where('mod_cv_documents.status', $statusFilter);
    });
}

if ($searchQuery) {
    $verifQuery->where(function ($q) use ($searchQuery) {
        $q->where('tblclients.firstname', 'like', "%{$searchQuery}%")
          ->orWhere('tblclients.lastname', 'like', "%{$searchQuery}%")
          ->orWhere('tblclients.email', 'like', "%{$searchQuery}%")
          ->orWhere('mod_cv_verifications.client_id', $searchQuery)
          ->orWhere('mod_cv_verifications.id', $searchQuery)
          ->orWhereExists(function ($sq) use ($searchQuery) {
              $sq->select(Capsule::raw(1))
                ->from('mod_cv_documents')
                ->whereRaw('mod_cv_documents.verification_id = mod_cv_verifications.id')
                ->where(function($dq) use ($searchQuery) {
                    $dq->where('mod_cv_documents.original_filename', 'like', "%{$searchQuery}%")
                       ->orWhere('mod_cv_documents.document_type', 'like', "%{$searchQuery}%");
                });
          });
    });
}

$total = $verifQuery->count();
$verifications = $verifQuery->orderByDesc('mod_cv_verifications.id')
    ->forPage($page, $perPage)
    ->get();

// Collect verification IDs and load all documents
$vids = $verifications->pluck('id')->toArray();
$docsByVid = [];
if (!empty($vids)) {
    $docsList = Capsule::table('mod_cv_documents')->whereIn('verification_id', $vids)->orderBy('id')->get();
    foreach ($docsList as $d) {
        $docsByVid[$d->verification_id][] = $d;
    }
}

cv_admin_header('documents', 'Documents', 'Manage and audit all uploaded KYC identity documents grouped by verification session.');

$counts = [
    '' => Capsule::table('mod_cv_documents')->count(),
    'pending' => Capsule::table('mod_cv_documents')->where('status', 'pending')->count(),
    'approved' => Capsule::table('mod_cv_documents')->where('status', 'approved')->count(),
    'rejected' => Capsule::table('mod_cv_documents')->where('status', 'rejected')->count(),
];

$baseUrl = 'addonmodules.php?module=clientverification&action=documents'
    . ($statusFilter ? '&status=' . urlencode($statusFilter) : '')
    . ($searchQuery ? '&q=' . urlencode($searchQuery) : '');

?>

<!-- Filter & Search Toolbar -->
<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 16px 20px; margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <?php foreach (['' => 'All Documents', 'pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $s => $label): 
                $isActive = ($statusFilter === $s);
                $btnClass = $isActive ? 'btn-primary' : 'btn-default';
            ?>
                <a class="btn <?php echo $btnClass; ?> btn-sm" href="addonmodules.php?module=clientverification&action=documents<?php echo $s ? '&status=' . urlencode($s) : ''; ?><?php echo $searchQuery ? '&q=' . urlencode($searchQuery) : ''; ?>" style="font-weight: 600;">
                    <?php echo htmlspecialchars($label); ?>
                    <span class="badge" style="background: <?php echo $isActive ? '#ffffff' : '#64748b'; ?>; color: <?php echo $isActive ? '#2563eb' : '#ffffff'; ?>; font-size: 11px; margin-left: 4px;">
                        <?php echo (int)($counts[$s] ?? 0); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="addonmodules.php" style="margin: 0; display: flex; gap: 6px;">
            <input type="hidden" name="module" value="clientverification">
            <input type="hidden" name="action" value="documents">
            <?php if ($statusFilter): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" class="form-control input-sm" placeholder="Search client, filename, ID..." style="width: 240px;">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search"></i></button>
            <?php if ($searchQuery): ?>
                <a href="addonmodules.php?module=clientverification&action=documents<?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>" class="btn btn-default btn-sm" title="Clear Search"><i class="fa fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Grouped Verification Cards List -->
<?php if (empty($verifications) || $verifications->isEmpty()): ?>
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 50px 20px; text-align: center; color: #94a3b8; margin-bottom: 24px;">
        <i class="fa fa-folder-open-o fa-3x" style="margin-bottom: 12px; display: block; color: #cbd5e1;"></i>
        <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 600; color: #475569;">No uploaded documents found</h4>
        <p style="font-size: 13px; margin: 0;">No identity documents match your current filter criteria.</p>
    </div>
<?php else: ?>
    <?php foreach ($verifications as $v): 
        $docsForThis = $docsByVid[$v->id] ?? [];
        $clientName = trim(($v->firstname ?? '') . ' ' . ($v->lastname ?? ''));
        
        $vStatusBadge = match($v->status) {
            'approved' => '<span class="label label-success" style="font-size: 11px; padding: 4px 8px;">Approved</span>',
            'rejected' => '<span class="label label-danger" style="font-size: 11px; padding: 4px 8px;">Rejected</span>',
            'under_review' => '<span class="label label-warning" style="font-size: 11px; padding: 4px 8px;">Under Review</span>',
            default => '<span class="label label-default" style="font-size: 11px; padding: 4px 8px;">' . htmlspecialchars(ucfirst($v->status)) . '</span>',
        };
    ?>
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 20px; overflow: hidden;">
            <!-- Card Header -->
            <div style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <span style="font-size: 15px; font-weight: 700; color: #1e293b;">
                        Verification #<?php echo (int) $v->id; ?>
                    </span>
                    <span style="color: #cbd5e1;">&bull;</span>
                    <span style="font-size: 14px; font-weight: 600; color: #334155;">
                        Client: <a href="clientssummary.php?userid=<?php echo (int) $v->client_id; ?>" target="_blank" style="color: #2563eb;"><?php echo htmlspecialchars($clientName ?: 'Client #' . $v->client_id); ?></a>
                        <?php if (!empty($v->email)): ?>
                            <span style="font-size: 12px; color: #64748b; font-weight: 400;">(<?php echo htmlspecialchars($v->email); ?>)</span>
                        <?php endif; ?>
                    </span>
                    <span style="color: #cbd5e1;">&bull;</span>
                    <span style="font-size: 12px; color: #64748b;">
                        <i class="fa fa-calendar-o"></i> <?php echo htmlspecialchars($v->submitted_at ?: $v->created_at); ?>
                    </span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <?php echo $vStatusBadge; ?>
                    <a href="addonmodules.php?module=clientverification&action=verification&id=<?php echo (int) $v->id; ?>" class="btn btn-default btn-xs" style="font-weight: 600;">
                        <i class="fa fa-external-link"></i> Review Verification #<?php echo (int) $v->id; ?>
                    </a>
                </div>
            </div>

            <!-- Card Body: Uploaded Documents -->
            <div style="padding: 18px 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa fa-file-text-o text-primary"></i> Uploaded Documents (<?php echo count($docsForThis); ?>)
                </div>

                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach ($docsForThis as $doc): 
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
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 14px;">
                                <div style="width: 40px; height: 40px; background: <?php echo $isPdf ? '#fee2e2' : '#eff6ff'; ?>; color: <?php echo $isPdf ? '#dc2626' : '#2563eb'; ?>; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                    <i class="fa <?php echo $isPdf ? 'fa-file-pdf-o' : 'fa-file-image-o'; ?>"></i>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <strong style="color: #1e293b; font-size: 14px;"><?php echo htmlspecialchars($docDisplayTitle); ?></strong>
                                        <?php if ($doc->encrypted): ?>
                                            <span title="Encrypted at rest" style="color: #16a34a; font-size: 12px;"><i class="fa fa-lock"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 3px;">
                                        <code><?php echo htmlspecialchars($doc->original_filename); ?></code>
                                        &bull; <strong><?php echo $sizeKb; ?> KB</strong>
                                        &bull; <i class="fa fa-clock-o"></i> <?php echo htmlspecialchars($doc->created_at ?? $doc->uploaded_at); ?>
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php echo $docStatusBadge; ?>

                                <!-- View Document Popup Button -->
                                <button type="button" class="btn btn-primary btn-sm" style="font-weight: 600;" onclick="cvOpenDocModal(<?php echo (int) $doc->id; ?>, '<?php echo addslashes(htmlspecialchars($docDisplayTitle)); ?>', '<?php echo addslashes(htmlspecialchars($doc->original_filename)); ?>', '<?php echo $sizeKb; ?> KB', '<?php echo addslashes($doc->mime_type ?? ''); ?>');">
                                    <i class="fa fa-eye"></i> View Document
                                </button>

                                <!-- Direct Download Button -->
                                <a href="addonmodules.php?module=clientverification&action=documents&download=<?php echo (int) $doc->id; ?>&mode=download" class="btn btn-default btn-sm" title="Download Document" target="_blank" style="font-weight: 600;">
                                    <i class="fa fa-download"></i> Download
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div style="margin-top: 15px; margin-bottom: 20px;">
        <?php echo cv_render_pagination($total, $perPage, $page, $baseUrl); ?>
    </div>
<?php endif; ?>

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

    var viewUrl = 'addonmodules.php?module=clientverification&action=documents&download=' + docId + '&mode=inline';
    var downloadUrl = 'addonmodules.php?module=clientverification&action=documents&download=' + docId + '&mode=download';

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


