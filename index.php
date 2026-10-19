<?php
require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Dompdf\Dompdf;
use Dompdf\Options;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function readCsv($filePath) {
    if (!file_exists($filePath)) return [];
    $rows = array_map('str_getcsv', file($filePath));
    if (empty($rows)) return [];
    
    $header = array_shift($rows);
    $data = [];
    foreach ($rows as $row) {
        if (count($header) === count($row)) {
            $data[] = array_combine($header, $row);
        }
    }
    return $data;
}

$employees = readCsv(__DIR__ . '/data/employees.csv');
$payrolls = readCsv(__DIR__ . '/data/payroll.csv');

// Root Route: GET /
if ($requestMethod === 'GET' && ($requestUri === '/' || $requestUri === '')) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'PHP Payroll API is running'
    ]);
    exit;
}

// Route 1: GET /api/employees
if ($requestMethod === 'GET' && $requestUri === '/api/employees') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $employees
    ]);
    exit;
}

// Route 2: GET /api/reports/payroll
if ($requestMethod === 'GET' && $requestUri === '/api/reports/payroll') {
    $totalGross = 0;
    $totalNet = 0;
    foreach ($payrolls as $row) {
        $totalGross += (float)($row['gross_pay'] ?? 0);
        $totalNet += (float)($row['net_pay'] ?? 0);
    }
    $totalDeductions = $totalGross - $totalNet;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'total_payroll_records' => count($payrolls),
            'total_gross_pay' => $totalGross,
            'total_deductions' => $totalDeductions,
            'total_net_pay' => $totalNet
        ]
    ]);
    exit;
}

// Route 3: GET /api/payslips/{payroll_id}
if ($requestMethod === 'GET' && preg_match('#^/api/payslips/([0-9]+)$#', $requestUri, $matches)) {
    $payrollId = $matches[1];
    $foundPayroll = null;

    foreach ($payrolls as $p) {
        if (($p['payroll_id'] ?? null) == $payrollId) {
            $foundPayroll = $p;
            break;
        }
    }

    if (!$foundPayroll) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payslip record not found']);
        exit;
    }

    $empName = "Employee #" . ($foundPayroll['employee_id'] ?? '');
    foreach ($employees as $e) {
        if (($e['employee_id'] ?? null) == ($foundPayroll['employee_id'] ?? null)) {
            $empName = $e['full_name'] ?? $empName;
            break;
        }
    }

    $gross = (float)($foundPayroll['gross_pay'] ?? 0);
    $net = (float)($foundPayroll['net_pay'] ?? 0);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'payroll_id' => (int)$payrollId,
            'employee_name' => $empName,
            'gross_pay' => $gross,
            'deductions' => $gross - $net,
            'net_pay' => $net
        ]
    ]);
    exit;
}

// Route 4: POST /api/payslips/{payroll_id}/generate
if ($requestMethod === 'POST' && preg_match('#^/api/payslips/([0-9]+)/generate$#', $requestUri, $matches)) {
    $payrollId = $matches[1];

    $options = new Options();
    $options->set('tempDir', '/tmp');
    $options->set('fontDir', '/tmp');
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $html = "<h2>Employee Payslip</h2><hr><p><strong>Payroll ID:</strong> {$payrollId}</p><p><strong>Status:</strong> Generated via AWS Lambda</p>";
    $dompdf->loadHtml($html);
    $dompdf->render();
    $pdfOutput = $dompdf->output();

    $bucketName = 'payroll-payslips-sam10-toledano';
    $s3Key = "payslips/{$payrollId}.pdf";

    $s3 = new S3Client([
        'region'  => 'us-east-1',
        'version' => 'latest'
    ]);

    // Upload PDF to S3
    $s3->putObject([
        'Bucket'      => $bucketName,
        'Key'         => $s3Key,
        'Body'        => $pdfOutput,
        'ContentType' => 'application/pdf'
    ]);

    // Generate 15-minute temporary download link
    $cmd = $s3->getCommand('GetObject', [
        'Bucket' => $bucketName,
        'Key'    => $s3Key
    ]);
    $presignedRequest = $s3->createPresignedRequest($cmd, '+15 minutes');
    $presignedUrl = (string)$presignedRequest->getUri();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Payslip generated and uploaded.',
        'data' => [
            'payroll_id' => (int)$payrollId,
            'payslip_file' => $presignedUrl
        ]
    ]);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Endpoint not found']);