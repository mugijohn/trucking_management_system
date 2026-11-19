<?php
require 'config.php';
require_login();

// Serve a load document through PHP so the link does not depend on a
// directly accessible uploads URL or Apache directory configuration.
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId || $documentId < 1) {
    http_response_code(400);
    exit('Invalid document request.');
}

$stmt = db()->prepare('SELECT file_path, original_name FROM load_documents WHERE id = ? LIMIT 1');
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit('Document record was not found. It may have been deleted.');
}

$uploadDirectory = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'load_documents');
if ($uploadDirectory === false) {
    http_response_code(404);
    exit('The document storage folder does not exist. Check that uploads/load_documents is present in your project.');
}

// Only allow a filename from the document storage folder; never trust a path
// stored in the database as a filesystem path.
$fileName = basename(str_replace('\\', '/', (string)$document['file_path']));
$filePath = realpath($uploadDirectory . DIRECTORY_SEPARATOR . $fileName);

if ($filePath === false || !is_file($filePath) || strpos($filePath, $uploadDirectory . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('The uploaded file cannot be found on the server. The database record exists, but the file is missing from uploads/load_documents.');
}

$mimeType = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $filePath);
        if ($detected) $mimeType = $detected;
        finfo_close($finfo);
    }
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$disposition = in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';
$downloadName = (string)($document['original_name'] ?: basename($filePath));
$downloadName = str_replace(["\r", "\n", '"'], '', $downloadName);

if (ob_get_level()) {
    while (ob_get_level()) ob_end_clean();
}
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($downloadName, '\\') . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
