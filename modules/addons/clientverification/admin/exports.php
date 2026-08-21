<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $rows = Capsule::table('mod_cv_verifications')->orderByDesc('id')->get();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cv_verifications_' . date('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Client ID', 'Method', 'Status', 'Risk Score', 'Risk Level', 'Submitted', 'Reviewed']);

    foreach ($rows as $r) {
        fputcsv($out, [
            Sanitizer::csvCell($r->id),
            Sanitizer::csvCell($r->client_id),
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

echo '<h2>' . Sanitizer::escape($_LANG['cv_exports']) . '</h2>';
echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">
<p>Export all verifications to CSV (CSV-injection safe).</p>
<a class="btn btn-primary" href="addonmodules.php?module=clientverification&action=exports&download=csv">Download CSV</a>
</div>';
