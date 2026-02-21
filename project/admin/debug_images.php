<?php
// ── debug_images.php ──────────────────────────────────────────
// DROP THIS FILE IN YOUR ROOT (same folder as products.php)
// Visit: yoursite.com/debug_images.php
// DELETE IT after fixing.
// ─────────────────────────────────────────────────────────────
require_once 'db.php';

echo '<style>body{font-family:monospace;padding:2rem;background:#f5f5f5}
table{border-collapse:collapse;width:100%;margin-top:1rem}
th,td{border:1px solid #ccc;padding:8px 12px;text-align:left;font-size:13px}
th{background:#222;color:#fff}
.ok{background:#d4edda;color:#155724}
.bad{background:#f8d7da;color:#721c24}
.warn{background:#fff3cd;color:#856404}
h2{margin-top:2rem;color:#333}
pre{background:#222;color:#0f0;padding:1rem;border-radius:6px;overflow-x:auto}
</style>';

echo '<h1>🔍 IT Shop Image Debug</h1>';

// ── 1. Folder structure ───────────────────────────────────────
echo '<h2>1. Server Paths</h2>';
echo '<table><tr><th>Key</th><th>Value</th></tr>';
echo '<tr><td>__FILE__ (this script)</td><td>' . __FILE__ . '</td></tr>';
echo '<tr><td>DOCUMENT_ROOT</td><td>' . $_SERVER['DOCUMENT_ROOT'] . '</td></tr>';
echo '<tr><td>script dir</td><td>' . dirname(__FILE__) . '</td></tr>';

$upload_path = dirname(__FILE__) . '/uploads/products/';
echo '<tr><td>uploads/products/ exists?</td><td class="' . (is_dir($upload_path) ? 'ok' : 'bad') . '">'
    . (is_dir($upload_path) ? '✅ YES — ' . $upload_path : '❌ NO — ' . $upload_path) . '</td></tr>';

if (is_dir($upload_path)) {
    $files = array_diff(scandir($upload_path), ['.','..']);
    echo '<tr><td>Files in uploads/products/</td><td>' . (count($files) ? implode('<br>', array_slice($files,0,10)) : '<em>empty</em>') . '</td></tr>';
}
echo '</table>';

// ── 2. DB image values ────────────────────────────────────────
echo '<h2>2. DB Image Column Values (first 15 products)</h2>';
echo '<table><tr><th>ID</th><th>Name</th><th>image (raw DB value)</th><th>File exists on disk?</th><th>Rendered &lt;img&gt;</th></tr>';

try {
    $rows = $pdo->query("SELECT id, name, image FROM products ORDER BY id LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $raw = $r['image'] ?? '';

        // Normalise to root-relative
        if ($raw === '') {
            $norm = '';
        } elseif (preg_match('#^https?://#i', $raw)) {
            $norm = $raw;
        } else {
            $clean = preg_replace('#^(\.\./)+#', '', $raw);
            $norm  = ltrim($clean, '/');
        }

        // Check if file physically exists
        $phys    = dirname(__FILE__) . '/' . $norm;
        $exists  = ($norm && file_exists($phys));
        $ex_cls  = $exists ? 'ok' : 'bad';
        $ex_txt  = $exists ? '✅ YES' : '❌ NO — looked for: ' . htmlspecialchars($phys);

        // Preview
        $preview = $norm ? '<img src="' . htmlspecialchars($norm) . '" style="height:50px;max-width:80px;object-fit:contain;background:#eee;border:1px solid #ccc" onerror="this.outerHTML=\'<span style=color:red>BROKEN</span>\'">' : '<em>no image</em>';

        echo '<tr>';
        echo '<td>' . $r['id'] . '</td>';
        echo '<td>' . htmlspecialchars($r['name']) . '</td>';
        echo '<td><pre style="margin:0;padding:4px;font-size:11px">' . htmlspecialchars($raw ?: '(empty)') . '</pre></td>';
        echo '<td class="' . $ex_cls . '">' . $ex_txt . '</td>';
        echo '<td>' . $preview . '</td>';
        echo '</tr>';
    }
} catch (Exception $e) {
    echo '<tr><td colspan="5" class="bad">DB error: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
}
echo '</table>';

// ── 3. Permission check ───────────────────────────────────────
echo '<h2>3. Upload Directory Permissions</h2>';
echo '<table><tr><th>Path</th><th>Readable</th><th>Writable</th></tr>';
$dirs = [
    dirname(__FILE__) . '/uploads',
    dirname(__FILE__) . '/uploads/products',
];
foreach ($dirs as $d) {
    echo '<tr><td>' . htmlspecialchars($d) . '</td>';
    echo '<td class="' . (is_readable($d) ? 'ok' : 'bad') . '">' . (is_readable($d) ? '✅' : '❌') . '</td>';
    echo '<td class="' . (is_writable($d) ? 'ok' : 'bad') . '">' . (is_writable($d) ? '✅' : '❌') . '</td>';
    echo '</tr>';
}
echo '</table>';

// ── 4. Advice ─────────────────────────────────────────────────
echo '<h2>4. What to fix</h2><ul style="font-family:sans-serif;line-height:2">';
echo '<li>If <b>image (raw DB value)</b> starts with <code>../</code> → images were saved with the wrong path from admin. Run the SQL fix below.</li>';
echo '<li>If <b>File exists on disk?</b> is ❌ → the file was uploaded to the wrong folder. Check admin upload dir.</li>';
echo '<li>If <b>Rendered img</b> shows BROKEN but file exists → path mismatch between web root and file system.</li>';
echo '</ul>';

echo '<h2>5. SQL fix (if DB paths start with ../)</h2>';
echo '<pre>UPDATE products SET image = REGEXP_REPLACE(image, \'^(\\.\\./)+\', \'\') WHERE image LIKE \'../%;\'</pre>';
echo '<p style="font-family:sans-serif">Run this in phpMyAdmin or MySQL CLI to strip leading ../ from all image paths.</p>';
?>