<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function sanitize_conditions(array $conditions): array
{
    return array_values(array_filter(array_map(
        static fn($value) => preg_replace('/[^a-zA-Z_-]/', '', (string)$value),
        $conditions
    )));
}

function run_python_analysis(string $inputPath, int $familySize, string $ageGroup, array $conditions): array
{
    $pythonScript = ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'process_receipt.py';
    $conditionText = implode(',', sanitize_conditions($conditions));

    $command = escapeshellcmd(PYTHON_COMMAND) . ' '
        . escapeshellarg($pythonScript)
        . ' --input ' . escapeshellarg($inputPath)
        . ' --family-size ' . escapeshellarg((string)$familySize)
        . ' --age-group ' . escapeshellarg($ageGroup)
        . ' --conditions ' . escapeshellarg($conditionText);

    $output = shell_exec($command);
    $result = json_decode($output ?? '', true);

    if (!is_array($result)) {
        throw new RuntimeException('Python analysis failed: ' . trim((string)$output));
    }

    return $result;
}

function persist_analysis_result(array &$result, string $sourcePath, ?int $userId = null): void
{
    try {
        $pdo = db();
        $pdo->beginTransaction();

        $receiptStatement = $pdo->prepare(
            'INSERT INTO receipts (user_id, family_profile_id, image_path, extracted_text) VALUES (:user_id, NULL, :image_path, :extracted_text)'
        );
        $receiptStatement->execute([
            ':user_id' => $userId,
            ':image_path' => $sourcePath,
            ':extracted_text' => $result['extracted_text'] ?? '',
        ]);

        $databaseReceiptId = (int)$pdo->lastInsertId();
        $result['database_receipt_id'] = $databaseReceiptId;

        $itemStatement = $pdo->prepare(
            'INSERT INTO receipt_items (receipt_id, raw_name, normalized_name, quantity, food_item_id) VALUES (:receipt_id, :raw_name, :normalized_name, :quantity, NULL)'
        );

        foreach (($result['items'] ?? []) as $item) {
            $itemStatement->execute([
                ':receipt_id' => $databaseReceiptId,
                ':raw_name' => $item['raw_line'] ?? $item['name'] ?? '',
                ':normalized_name' => $item['name'] ?? '',
                ':quantity' => (float)($item['quantity'] ?? 1),
            ]);
        }

        $breakdown = $result['health_score']['breakdown'] ?? [];
        $scoreStatement = $pdo->prepare(
            'INSERT INTO health_scores (receipt_id, family_profile_id, score, score_label, sugar_score, fat_score, sodium_score, fiber_score, diversity_score)
             VALUES (:receipt_id, NULL, :score, :score_label, :sugar_score, :fat_score, :sodium_score, :fiber_score, :diversity_score)'
        );
        $scoreStatement->execute([
            ':receipt_id' => $databaseReceiptId,
            ':score' => (float)($result['health_score']['score'] ?? 0),
            ':score_label' => $result['health_score']['label'] ?? 'Unknown',
            ':sugar_score' => (float)($breakdown['sugar'] ?? 0),
            ':fat_score' => (float)($breakdown['fat'] ?? 0),
            ':sodium_score' => (float)($breakdown['sodium'] ?? 0),
            ':fiber_score' => (float)($breakdown['fiber'] ?? 0),
            ':diversity_score' => (float)($breakdown['diversity'] ?? 0),
        ]);

        $anomalyStatement = $pdo->prepare(
            'INSERT INTO anomalies (receipt_id, item_name, metric_name, value, mean_value, std_deviation, z_score, message)
             VALUES (:receipt_id, :item_name, :metric_name, :value, :mean_value, :std_deviation, :z_score, :message)'
        );

        foreach (($result['anomalies'] ?? []) as $anomaly) {
            $anomalyStatement->execute([
                ':receipt_id' => $databaseReceiptId,
                ':item_name' => $anomaly['item'] ?? '',
                ':metric_name' => 'quantity',
                ':value' => (float)($anomaly['value'] ?? 0),
                ':mean_value' => (float)($anomaly['mean'] ?? 0),
                ':std_deviation' => (float)($anomaly['std_deviation'] ?? 0),
                ':z_score' => (float)($anomaly['z_score'] ?? 0),
                ':message' => $anomaly['message'] ?? '',
            ]);
        }

        $recommendationStatement = $pdo->prepare(
            'INSERT INTO recommendations (receipt_id, recommendation_text, explanation) VALUES (:receipt_id, :recommendation_text, :explanation)'
        );

        foreach (($result['recommendations'] ?? []) as $recommendation) {
            $recommendationStatement->execute([
                ':receipt_id' => $databaseReceiptId,
                ':recommendation_text' => $recommendation,
                ':explanation' => 'Generated from nutrient thresholds, family conditions, item risks, and anomaly output.',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $result['database_warning'] = $exception->getMessage();
    }
}

function save_analysis_result(array $result, string $receiptId): void
{
    ensure_directory(RESULT_DIR);
    $resultPath = RESULT_DIR . DIRECTORY_SEPARATOR . $receiptId . '.json';
    $latestPath = RESULT_DIR . DIRECTORY_SEPARATOR . 'latest.json';

    file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT));
    file_put_contents($latestPath, json_encode($result, JSON_PRETTY_PRINT));
}
