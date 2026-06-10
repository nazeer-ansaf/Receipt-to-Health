<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/results.php';

$format = strtolower((string)($_GET['format'] ?? 'json'));
$result = load_result((string)($_GET['id'] ?? 'latest'));

if (!$result) {
    json_response(['error' => 'Report not found.'], 404);
}

$fileName = 'receipt-to-health-' . ($result['_id'] ?? 'report');

function pdf_escape_text(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? $text;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_wrapped_lines(string $text, int $maxLength = 86): array
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        if (strlen($line . ' ' . $word) > $maxLength) {
            $lines[] = trim($line);
            $line = $word;
        } else {
            $line .= ' ' . $word;
        }
    }

    if (trim($line) !== '') {
        $lines[] = trim($line);
    }

    return $lines ?: [''];
}

function build_report_pdf(array $result): string
{
    $lines = [];
    $lines[] = APP_NAME . ' Final Report';
    $lines[] = 'Report ID: ' . ($result['_id'] ?? 'latest');
    $lines[] = 'Score: ' . ($result['health_score']['score'] ?? '-') . ' (' . ($result['health_score']['label'] ?? 'Unknown') . ')';
    $lines[] = 'Family: ' . ($result['family']['family_size'] ?? '-') . ' member(s), ' . ($result['family']['age_group'] ?? '-');
    $lines[] = 'Conditions: ' . condition_text($result['family'] ?? []);
    $lines[] = '';

    $explanation = score_explanation($result);
    $lines[] = 'Score explanation:';
    foreach (pdf_wrapped_lines((string)($explanation['summary'] ?? '')) as $line) {
        $lines[] = '  ' . $line;
    }
    foreach (($explanation['reasons'] ?? []) as $reason) {
        foreach (pdf_wrapped_lines('- ' . (string)$reason) as $line) {
            $lines[] = '  ' . $line;
        }
    }
    $lines[] = '';

    $lines[] = 'Per-person nutrition:';
    foreach (($result['per_person_nutrition'] ?? []) as $key => $value) {
        $lines[] = '  ' . ucwords(str_replace('_', ' ', (string)$key)) . ': ' . $value;
    }
    $lines[] = '';

    $lines[] = 'Priority alerts:';
    foreach (($result['priority_alerts'] ?? []) as $alert) {
        foreach (pdf_wrapped_lines(($alert['priority'] ?? 'Watch') . ' - ' . ($alert['title'] ?? '') . ': ' . ($alert['proof'] ?? '')) as $line) {
            $lines[] = '  ' . $line;
        }
    }
    if (empty($result['priority_alerts'])) {
        $lines[] = '  No priority alerts stored in this report.';
    }
    $lines[] = '';

    $lines[] = 'Recommendations:';
    foreach (array_slice(recommendation_cards($result), 0, 6) as $card) {
        foreach (pdf_wrapped_lines('- ' . (string)($card['advice'] ?? '')) as $line) {
            $lines[] = '  ' . $line;
        }
    }

    $commands = ['BT', '/F1 11 Tf', '50 790 Td', '14 TL'];
    foreach (array_slice($lines, 0, 54) as $line) {
        $commands[] = '(' . pdf_escape_text($line) . ') Tj';
        $commands[] = 'T*';
    }
    $commands[] = 'ET';
    $stream = implode("\n", $commands);
    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
        "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($index = 1; $index <= count($objects); $index++) {
        $pdf .= str_pad((string)$offsets[$index], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['section', 'name', 'value', 'detail']);
    fputcsv($output, ['score', 'health_score', $result['health_score']['score'] ?? '', $result['health_score']['label'] ?? '']);

    foreach (($result['per_person_nutrition'] ?? []) as $key => $value) {
        fputcsv($output, ['nutrition', $key, $value, 'per person']);
    }

    foreach (($result['items'] ?? []) as $item) {
        fputcsv($output, ['item', $item['name'] ?? '', $item['quantity'] ?? '', $item['risk'] ?? '']);
    }

    foreach (recommendation_cards($result) as $recommendation) {
        fputcsv($output, ['recommendation', 'advice', $recommendation['trigger'] ?? '', $recommendation['advice'] ?? '']);

        foreach (($recommendation['proof_points'] ?? []) as $proof) {
            fputcsv($output, ['recommendation', 'proof', '', $proof]);
        }

        foreach (($recommendation['evidence_items'] ?? []) as $evidence) {
            fputcsv($output, ['recommendation', 'receipt_evidence', '', $evidence]);
        }
    }

    fclose($output);
    exit;
}

if ($format === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '.pdf"');
    echo build_report_pdf($result);
    exit;
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $fileName . '.json"');
echo json_encode($result, JSON_PRETTY_PRINT);
