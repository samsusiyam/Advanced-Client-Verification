<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $rows = Capsule::table('mod_cv_verifications')
        ->leftJoin('tblclients', 'mod_cv_verifications.client_id', '=', 'tblclients.id')
        ->select('mod_cv_verifications.*', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email')
        ->orderByDesc('mod_cv_verifications.id')
        ->get();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cv_verifications_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Client ID', 'Client Name', 'Client Email', 'Method', 'Status', 'Risk Score', 'Risk Level', 'Submitted At', 'Reviewed At']);

    foreach ($rows as $r) {
        $name = trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? ''));
        fputcsv($out, [
            Sanitizer::csvCell($r->id),
            Sanitizer::csvCell($r->client_id),
            Sanitizer::csvCell($name),
            Sanitizer::csvCell($r->email ?? ''),
            Sanitizer::csvCell($r->verification_method),
            Sanitizer::csvCell($r->status),
            Sanitizer::csvCell($r->risk_score),
            Sanitizer::csvCell($r->risk_level),
            Sanitizer::csvCell($r->submitted_at),
            Sanitizer::csvCell($r->reviewed_at),
        ]);
    }
    fclose($out);
    exit;
}

cv_admin_header('exports', 'Data Exports', 'Export verification logs and metrics for auditing and compliance reporting.');

$totalCount = Capsule::table('mod_cv_verifications')->count();
?>

<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); padding: 24px; max-width: 650px;">
    <h4 style="margin: 0 0 10px 0; font-size: 16px; font-weight: 700; color: #1e293b;">
        <i class="fa fa-file-excel-o text-success"></i> Export Verifications to CSV
    </h4>
    <p style="color: #64748b; font-size: 13px; margin-bottom: 20px;">
        Download all <strong><?php echo number_format($totalCount); ?></strong> verification records in CSV spreadsheet format. Field data is automatically sanitized against formula injection (CSV injection defense).
    </p>

    <a class="btn btn-success" href="addonmodules.php?module=clientverification&action=exports&download=csv" style="font-weight: 600;">
        <i class="fa fa-download"></i> Download CSV Export (<?php echo number_format($totalCount); ?> records)
    </a>
</div>

