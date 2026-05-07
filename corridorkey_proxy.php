<?php
// corridorkey_proxy.php
//
// Receives a raw spritesheet PNG (multipart upload) plus layout metadata,
// splits it into individual frame PNGs, hands the folder to the queue-locked
// CorridorKey server via batch_corridorkey_remove.py, then recomposites the
// resulting transparent frames back into one PNG and streams it to the caller.

set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '2048M');

function fail($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST required.');

// Detect the most common cause of "missing sheet": PHP silently dropped the
// upload because Content-Length exceeded post_max_size, leaving $_POST and
// $_FILES both empty even though the browser sent a valid multipart body.
$contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax  = ini_get('post_max_size');
$uploadMax = ini_get('upload_max_filesize');
if (empty($_POST) && empty($_FILES) && $contentLen > 0) {
    fail(413,
        "Upload was dropped by PHP before this script ran.\n" .
        "Content-Length: {$contentLen} bytes\n" .
        "post_max_size: {$postMax}\n" .
        "upload_max_filesize: {$uploadMax}\n" .
        "memory_limit: " . ini_get('memory_limit') . "\n\n" .
        "Edit C:\\wamp64\\bin\\php\\php<ver>\\php.ini AND C:\\wamp64\\bin\\apache\\apache<ver>\\bin\\php.ini\n" .
        "Set:\n  post_max_size = 1024M\n  upload_max_filesize = 1024M\n  memory_limit = 2048M\n  max_execution_time = 0\n" .
        "Then restart WAMP (left-click tray icon -> Restart All Services)."
    );
}

if (!isset($_FILES['sheet'])) fail(400, "Missing 'sheet' field. Got POST keys: " . json_encode(array_keys($_POST)) . " FILES keys: " . json_encode(array_keys($_FILES)));
if ($_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
    $codes = [
        UPLOAD_ERR_INI_SIZE => 'UPLOAD_ERR_INI_SIZE (exceeds upload_max_filesize=' . $uploadMax . ')',
        UPLOAD_ERR_FORM_SIZE => 'UPLOAD_ERR_FORM_SIZE',
        UPLOAD_ERR_PARTIAL => 'UPLOAD_ERR_PARTIAL',
        UPLOAD_ERR_NO_FILE => 'UPLOAD_ERR_NO_FILE',
        UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
        UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
        UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
    ];
    $err = $_FILES['sheet']['error'];
    fail(400, 'Sheet upload error: ' . ($codes[$err] ?? "code $err"));
}

$cols   = (int)($_POST['cols']   ?? 0);
$rows   = (int)($_POST['rows']   ?? 0);
$frameW = (int)($_POST['frameW'] ?? 0);
$frameH = (int)($_POST['frameH'] ?? 0);
$count  = (int)($_POST['count']  ?? 0);
$baseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $_POST['baseName'] ?? 'maxymax');

if ($cols < 1 || $rows < 1 || $frameW < 1 || $frameH < 1 || $count < 1) {
    fail(400, "Invalid metadata: cols=$cols rows=$rows frameW=$frameW frameH=$frameH count=$count");
}

// CorridorKey config
$CK_SCRIPT  = 'C:\\wamp64\\www\\Game Asset Creation\\batch_corridorkey_remove.py';
$CK_SERVER  = 'http://127.0.0.1:8766';
// Apache's PATH doesn't usually have python; point at an installed interpreter.
// With --server-url the script only uses stdlib (urllib/json), so any Python works.
// Tries candidates in order; first one that exists wins.
$PY_CANDIDATES = [
    'C:\\Python314\\python.exe',
    'C:\\Users\\maxme\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
    'C:\\Users\\maxme\\AppData\\Local\\Python\\bin\\python.exe',
    'C:\\Windows\\py.exe',
];
$PY = null;
foreach ($PY_CANDIDATES as $cand) { if (file_exists($cand)) { $PY = $cand; break; } }
if ($PY === null) fail(500, "No Python interpreter found. Tried:\n" . implode("\n", $PY_CANDIDATES));

// Working dirs
$tag = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
$work   = __DIR__ . DIRECTORY_SEPARATOR . '_ck_work' . DIRECTORY_SEPARATOR . $tag;
$inDir  = $work . DIRECTORY_SEPARATOR . 'in';
$outDir = $work . DIRECTORY_SEPARATOR . 'out';
if (!@mkdir($inDir,  0777, true) && !is_dir($inDir))  fail(500, "mkdir failed: $inDir");
if (!@mkdir($outDir, 0777, true) && !is_dir($outDir)) fail(500, "mkdir failed: $outDir");

// Split sheet -> frames
$src = @imagecreatefrompng($_FILES['sheet']['tmp_name']);
if (!$src) fail(500, 'Failed to decode uploaded sheet PNG.');
imagealphablending($src, false);
imagesavealpha($src, true);

for ($i = 0; $i < $count; $i++) {
    $sx = ($i % $cols) * $frameW;
    $sy = intdiv($i, $cols) * $frameH;
    $cell = imagecreatetruecolor($frameW, $frameH);
    imagealphablending($cell, false);
    imagesavealpha($cell, true);
    imagecopy($cell, $src, 0, 0, $sx, $sy, $frameW, $frameH);
    $name = sprintf('frame_%05d.png', $i);
    if (!imagepng($cell, $inDir . DIRECTORY_SEPARATOR . $name)) {
        imagedestroy($src); imagedestroy($cell);
        fail(500, "Failed to write $name");
    }
    imagedestroy($cell);
}
imagedestroy($src);

// Build CK command (matches the 7t.md "queue-locked" pattern verbatim)
$cmd = sprintf(
    '%s %s %s --output-dir %s --device cuda --server-url %s --alpha-feather 0.35 --image-size 1024 --key "0,255,0" --overwrite --fail-fast 2>&1',
    escapeshellarg($PY),
    escapeshellarg($CK_SCRIPT),
    escapeshellarg($inDir),
    escapeshellarg($outDir),
    escapeshellarg($CK_SERVER)
);

$output = [];
$rc = 0;
exec($cmd, $output, $rc);

if ($rc !== 0) {
    fail(500, "CorridorKey failed (rc=$rc):\n" . implode("\n", $output) . "\n\nCMD:\n$cmd");
}

// Recomposite output frames into a transparent sheet
$sheet = imagecreatetruecolor($cols * $frameW, $rows * $frameH);
imagealphablending($sheet, false);
imagesavealpha($sheet, true);
$transparent = imagecolorallocatealpha($sheet, 0, 0, 0, 127);
imagefilledrectangle($sheet, 0, 0, $cols * $frameW, $rows * $frameH, $transparent);
imagealphablending($sheet, true);

$missing = 0;
for ($i = 0; $i < $count; $i++) {
    $name = sprintf('frame_%05d.png', $i);
    $path = $outDir . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($path)) { $missing++; continue; }
    $cell = @imagecreatefrompng($path);
    if (!$cell) { $missing++; continue; }
    imagealphablending($cell, false);
    imagesavealpha($cell, true);
    $sx = ($i % $cols) * $frameW;
    $sy = intdiv($i, $cols) * $frameH;
    imagecopy($sheet, $cell, $sx, $sy, 0, 0, $frameW, $frameH);
    imagedestroy($cell);
}

if ($missing === $count) {
    fail(500, "CK produced 0/$count output frames. Check server logs.\nCMD:\n$cmd\n\nLog:\n" . implode("\n", $output));
}

header('Content-Type: image/png');
header('X-CK-Missing-Frames: ' . $missing);
header('X-CK-Total-Frames: ' . $count);
imagepng($sheet);
imagedestroy($sheet);

// Best-effort cleanup of working dir (keep on failure for forensics)
function rrmdir($d) {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $d . DIRECTORY_SEPARATOR . $e;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}
rrmdir($work);
