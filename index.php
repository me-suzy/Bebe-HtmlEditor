<?php
// Simple Dreamweaver-like HTML/PHP editor with code + design view
// NOTE: Set your project root folder here:
$ROOT = 'e:/Carte/BB/17 - Site Leadership/'; // schimbă daca vrei alt folder de lucru
mb_internal_encoding('UTF-8');

function norm_path($p)
{
    $p = str_replace(["\\", ".."], ["/", ""], $p);
    return ltrim($p, "/");
}

function resolve_path($p, $ROOT)
{
    $p = str_replace("\\", "/", $p);
    if (preg_match('#^[a-zA-Z]:/#', $p) || strpos($p, '/') === 0) {
        return $p;
    }
    return rtrim($ROOT, '/') . '/' . ltrim($p, '/');
}

// --- Asset proxy: serve any file from disk (CSS, JS, images, etc.) ---
$pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
if (strpos($pathInfo, '/asset/') === 0) {
    $assetPath = substr($pathInfo, 7);
    $assetPath = str_replace("\\", "/", $assetPath);
    $assetPath = str_replace("../", "", $assetPath);
    if (file_exists($assetPath) && is_file($assetPath)) {
        $ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        $mimes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html' => 'text/html',
            'htm' => 'text/html',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'json' => 'application/json',
            'xml' => 'text/xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
        ];
        $ct = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';
        header('Content-Type: ' . $ct);
        header('Cache-Control: public, max-age=3600');
        readfile($assetPath);
    } else {
        http_response_code(404);
        echo "Not found";
    }
    exit;
}

// --- API: preview (HTML) ---
if (isset($_GET['action']) && $_GET['action'] === 'preview') {
    $file = isset($_GET['file']) ? $_GET['file'] : '';
    $full = resolve_path($file, $ROOT);
    if (!file_exists($full)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Fisierul nu exista.";
        exit;
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        chdir(dirname($full));
        include $full;
    } else {
        $dir = str_replace("\\", "/", dirname($full));
        $baseUrl = '/htmleditor/index.php/asset/' . $dir . '/';
        $html = file_get_contents($full);
        $baseTag = '<base href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '">';

        // ── STEP 1: Strip ALL JavaScript from the ENTIRE file first ──
        // This neutralises scripts in both <head> and <body>, preventing
        // JS-based redirects, hydration race-conditions, and runtime errors
        // that can blank the design preview.
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $html = preg_replace('/<script\b[^>]*\/>/i', '', $html);
        // Strip <noscript> blocks (usually "enable JS" fallback messages)
        $html = preg_replace('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', '', $html);

        // ── STEP 2: Collect stylesheets from the full (now script-free) HTML ──
        $allStyles = '';
        // <link rel="stylesheet"> – strip on* handlers, fix media="print" → "all",
        // strip title attr (titled stylesheets are "preferred" and may not activate in blob context)
        preg_match_all('/<link\b[^>]*\brel=["\']?stylesheet["\']?[^>]*\/?>/i', $html, $linkM);
        if (!empty($linkM[0])) {
            $cleanLinks = array_map(function($tag) {
                $tag = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $tag);
                $tag = preg_replace('/\bmedia\s*=\s*["\']?\s*print\s*["\']?/i', 'media="all"', $tag);
                $tag = preg_replace('/\s+title\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $tag);
                return $tag;
            }, $linkM[0]);
            $allStyles .= implode("\n", $cleanLinks) . "\n";
        }

        // <style> blocks from the entire page, re-packaged cleanly in <head>
        preg_match_all('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $html, $styleM);
        foreach ($styleM[1] as $css) {
            if (trim($css)) $allStyles .= "<style type=\"text/css\">\n" . $css . "\n</style>\n";
        }

        // <meta charset> and <meta viewport>
        $metaTags = '';
        preg_match_all('/<meta\b(?=[^>]*(?:charset|viewport))[^>]*>/i', $html, $metaM);
        if (!empty($metaM[0])) $metaTags = implode("\n", $metaM[0]) . "\n";

        // <title>
        $titleTag = '';
        if (preg_match('/<title\b[^>]*>[\s\S]*?<\/title>/i', $html, $titleM)) {
            $titleTag = $titleM[0] . "\n";
        }

        // ── STEP 3: Extract and sanitise body content ──
        if (preg_match('/<body\b[^>]*>/i', $html, $bodyOpenM, PREG_OFFSET_CAPTURE)) {
            $bodyTagEnd   = $bodyOpenM[0][1] + strlen($bodyOpenM[0][0]);
            $bodyClosePos = strripos($html, '</body>');
            if ($bodyClosePos !== false && $bodyClosePos >= $bodyTagEnd) {
                $bodyInner = substr($html, $bodyTagEnd, $bodyClosePos - $bodyTagEnd);

                // Remove <style> from body (already collected into $allStyles above)
                $bodyInner = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $bodyInner);

                // Strip ALL on* event handler attributes from every element in body
                $bodyInner = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $bodyInner);

                // Neutralise javascript: URLs in href attributes
                $bodyInner = preg_replace('/\bhref\s*=\s*["\']?\s*javascript\s*:[^"\'>\s]*/i', 'href="#"', $bodyInner);

                // DOMContentLoaded notification script (our only injected script)
                $domReadyScript = '<script>document.addEventListener("DOMContentLoaded",function(){'
                    . 'try{if(window.parent&&window.parent.__previewDOMReady)window.parent.__previewDOMReady();}catch(e){}'
                    . '});</script>';

                // Override CSS — force ALL elements to be visible.
                // Many modern pages hide content with CSS expecting JS hydration;
                // since we strip all scripts we must override every level, not just body>*.
                $editorOverrideCSS = "<style type=\"text/css\" id=\"editor-override\">\n"
                    . "html, body { visibility: visible !important; opacity: 1 !important; }\n"
                    . "body { display: block !important; }\n"
                    . "body * { visibility: visible !important; opacity: 1 !important;\n"
                    . "  animation: none !important; transition: none !important; }\n"
                    . "#preloader, .preloader, #loader, .loader, #loader-fade,\n"
                    . ".loading-overlay, .page-loader, .loading-screen,\n"
                    . "#loading-overlay, #page-loading, .site-loader,\n"
                    . ".loader-container, .spinner, #spinner,\n"
                    . "[class*=\"preload\"], [id*=\"preload\"],\n"
                    . "[class*=\"page-load\"], [id*=\"page-load\"] {\n"
                    . "  display: none !important; }\n"
                    . "</style>\n";

                // Strip on* from the <body> opening tag itself
                $bodyOpenTag = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $bodyOpenM[0][0]);

                $html = "<!DOCTYPE html>\n<html>\n<head>\n"
                    . $metaTags . $titleTag . $baseTag . "\n" . $domReadyScript . "\n" . $allStyles . $editorOverrideCSS
                    . "</head>\n" . $bodyOpenTag . $bodyInner . "</body>\n</html>";
            } else {
                $html = "<!DOCTYPE html>\n<html>\n<head>\n" . $metaTags . $baseTag . "\n" . $allStyles . "</head>\n<body></body>\n</html>";
            }
        } else {
            $html = "<!DOCTYPE html>\n<html>\n<head>\n" . $metaTags . $baseTag . "\n" . $allStyles . "</head>\n<body></body>\n</html>";
        }

        $ct = ($ext === 'css') ? 'text/css' : 'text/html';
        header("Content-Type: {$ct}; charset=utf-8");
        echo $html;
    }
    exit;
}

// --- API JSON (list/load/save) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'list') {
        $root = rtrim($ROOT, "/");
        $subdir = isset($_GET['dir']) ? $_GET['dir'] : '';
        $subdir = str_replace(["\\", ".."], ["/", ""], $subdir);
        $subdir = trim($subdir, '/');
        $scanPath = $subdir ? ($root . '/' . $subdir) : $root;
        $out = [];
        if (is_dir($scanPath)) {
            $items = scandir($scanPath);
            foreach ($items as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                $full = $scanPath . '/' . $f;
                $relPath = $subdir ? ($subdir . '/' . $f) : $f;
                if (is_dir($full)) {
                    $out[] = ['type' => 'dir', 'path' => $relPath, 'name' => $f];
                } else {
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (in_array($ext, ['html', 'htm', 'css', 'js', 'php'])) {
                        $out[] = ['type' => 'file', 'path' => $relPath, 'name' => $f, 'ext' => $ext];
                    }
                }
            }
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Search file by name recursively in ROOT
    if ($action === 'search') {
        $name = isset($_GET['name']) ? $_GET['name'] : '';
        $name = basename(str_replace("\\", "/", $name));
        if (!$name) {
            echo json_encode(['ok' => false, 'error' => 'Nume fisier lipsa']);
            exit;
        }
        $results = [];
        // Fast method: Windows dir /s /b
        $searchRoot = str_replace('/', '\\', rtrim($ROOT, '/'));
        $cmd = 'dir /s /b "' . $searchRoot . '\\' . $name . '" 2>nul';
        $output = [];
        @exec($cmd, $output);
        foreach ($output as $line) {
            $line = trim($line);
            if ($line) $results[] = str_replace("\\", "/", $line);
            if (count($results) >= 5) break;
        }
        // Fallback: PHP scandir recursiv (daca exec nu a mers)
        if (empty($results)) {
            $root = rtrim($ROOT, '/');
            $stack = [$root];
            $depth = 0;
            while (!empty($stack) && count($results) < 5) {
                $nextStack = [];
                foreach ($stack as $dir) {
                    $items = @scandir($dir);
                    if (!$items) continue;
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $path = $dir . '/' . $item;
                        if (is_file($path) && strcasecmp($item, $name) === 0) {
                            $results[] = str_replace("\\", "/", $path);
                            if (count($results) >= 5) break 3;
                        } elseif (is_dir($path)) {
                            $nextStack[] = $path;
                        }
                    }
                }
                $stack = $nextStack;
                $depth++;
                if ($depth > 8) break;
            }
        }
        // Also check in extra directories (from recent files, outside $ROOT).
        // MUST run independently of $ROOT results — for common filenames like
        // "index.html", $ROOT may fill all slots and the real file is outside it.
        $extraDirs = isset($_GET['dirs']) ? json_decode($_GET['dirs'], true) : [];
        if (is_array($extraDirs)) {
            $existing = array_flip($results);
            // Step A: exact match in each recent directory
            foreach ($extraDirs as $dir) {
                $dir = str_replace("\\", "/", $dir);
                $dir = str_replace("..", "", $dir);
                $dir = rtrim($dir, '/');
                $candidate = $dir . '/' . $name;
                if (file_exists($candidate) && is_file($candidate)) {
                    $norm = str_replace("\\", "/", $candidate);
                    if (!isset($existing[$norm])) {
                        $results[] = $norm;
                        $existing[$norm] = true;
                    }
                }
            }
            // Step B: broaden search — go up 2 levels from each extra dir
            // and use dir /s /b to recursively search those ancestors.
            $searchRoots = [];
            $rootNorm = rtrim(str_replace("\\", "/", $ROOT), '/');
            foreach ($extraDirs as $dir) {
                $dir = str_replace("\\", "/", $dir);
                $dir = str_replace("..", "", $dir);
                $dir = rtrim($dir, '/');
                // Go up 2 levels
                $ancestor = $dir;
                for ($i = 0; $i < 2; $i++) {
                    $parent = dirname($ancestor);
                    if ($parent === $ancestor || $parent === '.' || strlen($parent) <= 3) break;
                    $ancestor = $parent;
                }
                $ancestor = str_replace("\\", "/", $ancestor);
                // Skip if it's under $ROOT (already searched) or is a drive root
                if (stripos($ancestor, $rootNorm) === 0) continue;
                if (strlen($ancestor) <= 3) continue; // e.g. "D:/"
                $searchRoots[$ancestor] = true;
            }
            foreach (array_keys($searchRoots) as $sr) {
                $srWin = str_replace('/', '\\', $sr);
                $cmd = 'dir /s /b "' . $srWin . '\\' . $name . '" 2>nul';
                $lines = [];
                @exec($cmd, $lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!$line) continue;
                    $norm = str_replace("\\", "/", $line);
                    if (!isset($existing[$norm])) {
                        $results[] = $norm;
                        $existing[$norm] = true;
                    }
                    if (count($results) >= 20) break;
                }
                if (count($results) >= 20) break;
            }
        }
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    // Load file - supports both relative (to ROOT) and absolute paths
    if ($action === 'load') {
        $file = isset($_GET['file']) ? $_GET['file'] : '';
        $full = resolve_path($file, $ROOT);
        if (!file_exists($full)) {
            echo json_encode(['ok' => false, 'error' => 'Fisierul nu exista: ' . $full]);
            exit;
        }
        $txt = file_get_contents($full);
        echo json_encode(['ok' => true, 'file' => $full, 'content' => $txt]);
        exit;
    }

    // Save file - supports both relative and absolute paths
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = isset($_POST['file']) ? $_POST['file'] : '';
        $full = resolve_path($file, $ROOT);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            echo json_encode(['ok' => false, 'error' => 'Directorul nu exista: ' . $dir]);
            exit;
        }
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        file_put_contents($full, $content);
        echo json_encode(['ok' => true, 'file' => $full]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Actiune necunoscuta']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">

<head>
    <meta charset="UTF-8">
    <title>Mini Dreamweaver - Editor HTML/PHP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: Segoe UI, system-ui, sans-serif;
            background: #1e1f26;
            color: #eaeaea;
            height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: 17px
        }

        .topbar {
            background: #252633;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #333;
            font-size: 16px
        }

        .brand {
            font-weight: 700;
            color: #fff;
            font-size: 18px
        }

        #tabFile {
            font-size: 14px;
            color: #9ca3af;
            max-width: 420px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap
        }

        .btn {
            padding: 7px 14px;
            border: none;
            border-radius: 4px;
            font-size: 15px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px
        }

        .btn-primary {
            background: #3b82f6;
            color: #fff
        }

        .btn-ghost {
            background: #2b2c3a;
            color: #ccc
        }

        .btn-primary:hover {
            background: #2563eb
        }

        .btn-ghost:hover {
            background: #3b3d4d
        }

        .main {
            flex: 1;
            display: flex;
            min-height: 0
        }

        .sidebar {
            width: 280px;
            min-width: 280px;
            background: #181924;
            border-right: 1px solid #333;
            overflow: auto;
            padding: 10px;
            transition: min-width 0.2s, width 0.2s, padding 0.2s, border 0.2s;
        }

        .sidebar.collapsed {
            width: 0 !important;
            min-width: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            border-right: none !important;
        }

        #btnToggleSidebar {
            font-size: 18px;
            padding: 5px 10px;
            line-height: 1;
        }

        .sidebar h3 {
            font-size: 17px;
            margin-bottom: 8px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .6px
        }

        .file {
            padding: 6px 10px;
            font-size: 17px;
            cursor: pointer;
            border-radius: 3px;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .file:hover {
            background: #2a2b3a
        }

        .file.active {
            background: #3b82f6;
            color: #fff
        }

        .file .path {
            opacity: .6;
            font-size: 14px
        }

        .dir {
            padding: 4px 6px;
            font-size: 16px;
            color: #9ca3af;
            margin-top: 4px
        }

        .dir span {
            opacity: .7
        }

        .center {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0
        }

        .tabs {
            background: #181924;
            border-bottom: 1px solid #333;
            padding: 8px 12px;
            font-size: 16px;
            color: #9ca3af;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .split {
            flex: 1;
            display: flex;
            min-height: 0;
            position: relative
        }

        .editor-pane {
            flex: 1;
            border-right: 1px solid #333;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden
        }

        .editor-header {
            padding: 8px 12px;
            font-size: 16px;
            background: #20212c;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .preview-pane {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden
        }

        .preview-header {
            padding: 8px 12px;
            font-size: 16px;
            background: #20212c;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        iframe {
            flex: 1;
            border: none;
            background: #fff
        }

        .viewmode-tabs {
            display: inline-flex;
            border: 1px solid #4b5563;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
            font-size: 14px
        }

        .viewmode-tabs button {
            background: #111827;
            border: none;
            color: #e5e7eb;
            padding: 4px 10px;
            cursor: pointer
        }

        .viewmode-tabs button.active {
            background: #e5e7eb;
            color: #111827;
            font-weight: 600
        }

        .viewmode-tabs button+button {
            border-left: 1px solid #4b5563
        }

        .split.mode-code .preview-pane {
            display: none
        }

        .split.mode-code .editor-pane {
            flex: 1;
            border-right: none
        }

        .split.mode-design .editor-pane {
            display: none
        }

        .split.mode-design .preview-pane {
            flex: 1
        }

        .split.mode-split .editor-pane {
            display: flex
        }

        .split.mode-split .preview-pane {
            display: flex
        }

        .split .divider {
            width: 6px;
            cursor: col-resize;
            background: #111827;
            border-left: 1px solid #333;
            border-right: 1px solid #333;
            z-index: 10;
            position: relative
        }

        .split-overlay {
            position: absolute;
            inset: 0;
            cursor: col-resize;
            z-index: 20;
            display: none
        }

        .status {
            font-size: 15px;
            color: #9ca3af
        }

        .cm-editor {
            height: 100%
        }

        .cm-s-material-darker {
            background: #111827;
            color: #e5e7eb;
            font-size: 17px
        }

        .CodeMirror-selected {
            background: rgba(250, 204, 21, 0.25) !important
        }

        .CodeMirror-focused .CodeMirror-selected {
            background: rgba(250, 204, 21, 0.35) !important
        }

        .cm-sync-highlight {
            background: rgba(250, 204, 21, 0.25) !important;
            transition: background 0.9s ease-out
        }

        /* Yellow highlight for text selected in design panel, shown in code editor */
        .cm-selection-highlight {
            background: rgba(250, 204, 21, 0.45) !important;
        }

        .properties-panel {
            background: #20212c;
            border-top: 1px solid #333;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 15px;
            min-height: 46px
        }

        .properties-panel label {
            color: #9ca3af;
            margin-right: 2px
        }

        .properties-panel select,
        .properties-panel input[type="number"] {
            background: #15161d;
            border: 1px solid #444;
            border-radius: 4px;
            padding: 5px 10px;
            color: #eee;
            font-size: 14px
        }

        .properties-panel input[type="color"] {
            width: 30px;
            height: 26px;
            padding: 2px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #444
        }

        .properties-panel .prop-btn {
            padding: 5px 12px;
            min-width: 34px;
            font-weight: 700;
            height: 30px
        }

        /* Overlay start dialog */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .92);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000
        }

        .overlay-card {
            width: 960px;
            max-width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            background: #020617;
            border-radius: 16px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, .6);
            border: 1px solid #1f2937;
            padding: 34px 34px 26px;
            color: #e5e7eb
        }

        .overlay-row {
            display: flex;
            gap: 20px;
            align-items: flex-start
        }

        .overlay-row .drop-zone {
            flex: 1;
            min-width: 0
        }

        .overlay-row .recent-panel {
            flex: 1;
            min-width: 0;
            max-height: 280px;
            overflow-y: auto
        }

        .overlay-title {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 12px
        }

        .overlay-sub {
            font-size: 18px;
            color: #9ca3af;
            margin-bottom: 22px
        }

        .drop-zone {
            border: 2px dashed #4b5563;
            border-radius: 14px;
            padding: 48px 28px;
            text-align: center;
            background: rgba(15, 23, 42, .8);
            cursor: pointer;
            transition: .15s all;
            font-size: 18px
        }

        .drop-zone.over {
            border-color: #3b82f6;
            background: rgba(37, 99, 235, .12)
        }

        .drop-zone i {
            font-size: 50px;
            color: #3b82f6;
            margin-bottom: 14px
        }

        .drop-zone .drop-label {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px
        }

        .drop-zone .drop-hint {
            font-size: 16px;
            color: #9ca3af
        }

        .drop-main {
            margin-top: 22px;
            font-size: 17px;
            color: #9ca3af
        }

        .overlay-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
            font-size: 17px
        }

        .recent-item {
            padding: 7px 12px;
            cursor: pointer;
            border-radius: 5px;
            font-size: 15px;
            color: #d1d5db;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background .15s;
            text-align: left;
        }

        .recent-item:hover {
            background: #2a2b3a;
            color: #fff
        }

        .recent-item .recent-icon {
            color: #60a5fa;
            font-size: 13px;
            flex-shrink: 0
        }

        .recent-item .recent-name {
            font-weight: 500;
            color: #eee;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>
    <div class="overlay" id="startOverlay">
        <div class="overlay-card">
            <div class="overlay-title">Deschide un fisier</div>
            <div class="overlay-sub">Scrie calea completa, trage un fisier, sau click pe zona de mai jos.</div>
            <div style="display:flex;gap:8px;margin-bottom:18px">
                <input type="text" id="pathInput" placeholder="Ex: e:/Carte/BB/fisier.html"
                    style="flex:1;background:#15161d;border:1px solid #444;border-radius:6px;padding:10px 12px;color:#eee;font-size:16px">
                <button class="btn btn-primary" onclick="openFromPath()" style="white-space:nowrap">Deschide</button>
            </div>
            <div class="overlay-row">
                <div class="drop-zone" id="dropZone">
                    <i class="fas fa-file-code"></i>
                    <div class="drop-label">Drag &amp; Drop fisier aici</div>
                    <div class="drop-hint">sau click pentru a alege un fisier</div>
                    <div id="dropStatus" style="margin-top:12px;font-size:15px;color:#facc15;display:none"></div>
                </div>
                <div class="recent-panel" id="recentFilesSection" style="display:none">
                    <div style="color:#9ca3af;font-size:14px;margin-bottom:8px;font-weight:600">Fisiere recente:</div>
                    <div id="recentFilesList"></div>
                </div>
            </div>
            <input type="file" id="filePicker" accept=".html,.htm,.css,.js,.php" style="display:none">
            <div class="overlay-actions">
                <button class="btn btn-ghost" onclick="hideOverlay()">Continua la editor</button>
            </div>
        </div>
    </div>
    <div class="topbar">
        <button class="btn btn-ghost" id="btnToggleSidebar" onclick="toggleSidebar()" title="Arata/Ascunde lista de fisiere (fisiere HTML)">☰</button>
        <div class="brand">Mini Dreamweaver</div>
        <div id="tabFile">Niciun fisier deschis</div>
        <div style="flex:1"></div>
        <button class="btn btn-ghost" onclick="closeEditor()">Inchide</button>
        <button class="btn btn-primary" onclick="saveFile()">Salveaza (Ctrl+S)</button>
    </div>
    <div class="main">
        <div class="sidebar">
            <h3>Fisiere (HTML / CSS / JS / PHP)</h3>
            <div id="fileList"></div>
            <div id="sidebarRecent" style="margin-top:16px;display:none">
                <h3>Fisiere recente</h3>
                <div id="sidebarRecentList"></div>
            </div>
        </div>
        <div class="center">
            <div style="display:flex;align-items:center;justify-content:space-between">
                <div class="viewmode-tabs">
                    <button type="button" id="btnViewCode" onclick="setViewMode('code')">Code</button>
                    <button type="button" id="btnViewSplit" onclick="setViewMode('split')">Split</button>
                    <button type="button" id="btnViewDesign" onclick="setViewMode('design')">Design</button>
                </div>
                <div class="viewmode-tabs" style="margin-left:8px">
                    <button type="button" id="btnUndo" onclick="doUndoFromDesign()" title="Undo (Ctrl+Z)">&#8630;
                        Undo</button>
                    <button type="button" id="btnRedo" onclick="doRedoFromDesign()" title="Redo (Ctrl+Y)">&#8631;
                        Redo</button>
                </div>
                <div class="tabs">
                    <div class="status" id="status">Pregatit</div>
                </div>
            </div>
            <div class="split mode-split" id="splitContainer">
                <div class="editor-pane">
                    <div class="editor-header">
                        <div>Cod (UTF-8)</div>
                        <div style="font-size:11px;color:#9ca3af">Editare HTML / CSS / JS / PHP</div>
                    </div>
                    <textarea id="code" name="code"></textarea>
                </div>
                <div class="divider" id="splitDivider"></div>
                <div class="preview-pane">
                    <div class="preview-header">
                        <div>Design / Preview</div>
                        <button class="btn btn-ghost" onclick="updatePreview()">Reincarca preview</button>
                    </div>
                    <iframe id="preview"
                        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-pointer-lock allow-modals"></iframe>
                </div>
            </div>
            <div id="propertiesPanel" class="properties-panel">
                <span style="color:#9ca3af;margin-right:8px">Proprietati font:</span>
                <label>Font:</label><select id="propFont">
                    <option value="">(mostenit)</option>
                    <option value="Arial">Arial</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Times New Roman">Times New Roman</option>
                    <option value="Verdana">Verdana</option>
                    <option value="Source Sans Pro, sans-serif">Source Sans Pro</option>
                </select>
                <label>Clasa:</label><select id="propClass">
                    <option value="">(fara)</option>
                </select>
                <label>Marime:</label><select id="propSize">
                    <option value="">(mostenit)</option>
                    <option value="12">12px</option>
                    <option value="14">14px</option>
                    <option value="16">16px</option>
                    <option value="18">18px</option>
                    <option value="20">20px</option>
                    <option value="24">24px</option>
                </select>
                <button type="button" class="btn btn-ghost prop-btn" id="propBold" title="Bold">B</button>
                <button type="button" class="btn btn-ghost prop-btn" id="propItalic" title="Italic">I</button>
                <label>Text:</label><input type="color" id="propColor" value="#000000" title="Culoare text">
                <label>Fundal:</label><input type="color" id="propBg" value="#ffffff" title="Culoare fundal">
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
    <script>
        let editor;
        let currentFile = null;
        let currentDir = '';
        let isSyncFromCode = false;
        let isSyncFromDesign = false;
        let isApplyingUndoRedo = false;
        let previewDebounceTimer = null;
        let designInputDebounceTimer = null;
        let cssClasses = [];
        let isSelectionFromDesign = false;
        let isSelectionFromCode = false;
        let syncSelectionMark = null;   // markText handle for yellow selection highlight in code
        let isDirty = false;
        let viewMode = 'split';
        let skipPreviewUpdateUntil = 0;
        let sidebarVisible = true;
        let lastPreviewHadBody = false;

        // --- Custom undo/redo stack for the design panel ---
        // The browser's native contentEditable undo (execCommand('undo')) does NOT
        // track JavaScript-based DOM changes (applyFontProperty, toggleInlineFormat).
        // So we maintain our own stack of body.innerHTML snapshots.
        let designUndoStack = [];
        let designRedoStack = [];
        let lastDesignSnapshot = null;
        let designSnapshotTimer = null;

        function getDesignBodyHtml() {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            return (doc && doc.body) ? doc.body.innerHTML : null;
        }

        // Push current body state into the undo stack.
        // Call this BEFORE making a change, or periodically (every 500ms) to
        // capture incremental typing so undo reverts ~500ms of keystrokes at a time.
        function designSaveSnapshot() {
            const html = getDesignBodyHtml();
            if (html === null) return;
            if (lastDesignSnapshot === null) {
                lastDesignSnapshot = html;
                return;
            }
            if (html === lastDesignSnapshot) return; // nothing changed
            designUndoStack.push(lastDesignSnapshot);
            if (designUndoStack.length > 100) designUndoStack.shift();
            lastDesignSnapshot = html;
            designRedoStack = []; // new change clears the redo future
        }

        // Explicitly push the CURRENT body state to the undo stack.
        // Call this BEFORE making a property/format change so the "before" state is saved.
        // Uses time-based grouping: rapid calls within 500ms (e.g. dragging a color picker)
        // are collapsed into one undo entry — the state when the gesture STARTED.
        let _lastDesignPushTime = 0;
        function designPushCurrentState() {
            const now = Date.now();
            // Group rapid changes into one undo step (color picker drag etc.)
            if (now - _lastDesignPushTime < 500) return;
            _lastDesignPushTime = now;
            const html = getDesignBodyHtml();
            if (html === null) return;
            // Don't push if identical to the top of the stack
            if (designUndoStack.length > 0 && designUndoStack[designUndoStack.length - 1] === html) return;
            designUndoStack.push(html);
            if (designUndoStack.length > 100) designUndoStack.shift();
            designRedoStack = [];
        }

        // Reset stacks (called when the preview iframe reloads / new file is opened)
        function designResetUndoStack() {
            designUndoStack = [];
            designRedoStack = [];
            lastDesignSnapshot = getDesignBodyHtml();
            if (designSnapshotTimer) clearInterval(designSnapshotTimer);
            // Periodically save snapshots while the user types in design, so each
            // undo step covers ~500ms of keystroke activity.
            designSnapshotTimer = setInterval(() => {
                if (!isApplyingUndoRedo && !isSyncFromCode) {
                    designSaveSnapshot();
                }
            }, 500);
        }

        function toggleSidebar() {
            sidebarVisible = !sidebarVisible;
            const sb = document.querySelector('.sidebar');
            if (sb) sb.classList.toggle('collapsed', !sidebarVisible);
            try { localStorage.setItem('htmlEditorSidebarVisible', sidebarVisible ? '1' : '0'); } catch (e) {}
        }

        function toast(msg) {
            const st = document.getElementById('status');
            st.textContent = msg;
            setTimeout(() => { st.textContent = 'Pregatit'; }, 4000);
        }

        function shortName(path) {
            if (!path) return '';
            return path.replace(/\\/g, '/').split('/').pop();
        }

        function getRecentDirs() {
            try {
                const recent = JSON.parse(localStorage.getItem('htmlEditorRecentFiles')) || [];
                const dirs = new Set();
                recent.forEach(p => {
                    const dir = p.replace(/\\/g, '/').replace(/\/[^/]*$/, '');
                    if (dir) dirs.add(dir);
                });
                return Array.from(dirs);
            } catch (e) { return []; }
        }

        function addToRecentFiles(path) {
            if (!path) return;
            try {
                let recent = JSON.parse(localStorage.getItem('htmlEditorRecentFiles')) || [];
                // Remove if already exists
                recent = recent.filter(p => p !== path);
                // Add to top
                recent.unshift(path);
                // Keep only last 10
                if (recent.length > 10) recent = recent.slice(0, 10);
                localStorage.setItem('htmlEditorRecentFiles', JSON.stringify(recent));
                renderRecentFiles();
                renderSidebarRecent();
            } catch (e) {
                console.error('Error saving recent files:', e);
            }
        }

        function renderRecentFiles() {
            const listEl = document.getElementById('recentFilesList');
            const sectionEl = document.getElementById('recentFilesSection');
            if (!listEl || !sectionEl) return;
            
            try {
                const recent = JSON.parse(localStorage.getItem('htmlEditorRecentFiles')) || [];
                if (recent.length === 0) {
                    sectionEl.style.display = 'none';
                    return;
                }
                
                sectionEl.style.display = 'block';
                listEl.innerHTML = '';
                
                recent.forEach(path => {
                    const el = document.createElement('div');
                    el.className = 'recent-item';
                    
                    // Create visual parts
                    const name = shortName(path);
                    const isPhp = name.toLowerCase().endsWith('.php');
                    const iconClass = isPhp ? 'fab fa-php' : 'fas fa-code';
                    
                    el.title = path;
                    el.innerHTML = `
                        <i class="recent-icon ${iconClass}"></i>
                        <span class="recent-name">${name}</span>
                    `;
                    
                    el.onclick = () => {
                        const pathInp = document.getElementById('pathInput');
                        if (pathInp) pathInp.value = path;
                        openFromPath();
                    };
                    
                    listEl.appendChild(el);
                });
            } catch (e) {
                console.error('Error rendering recent files:', e);
                sectionEl.style.display = 'none';
            }
        }

        function renderSidebarRecent() {
            const listEl = document.getElementById('sidebarRecentList');
            const sectionEl = document.getElementById('sidebarRecent');
            if (!listEl || !sectionEl) return;
            try {
                const recent = JSON.parse(localStorage.getItem('htmlEditorRecentFiles')) || [];
                if (recent.length === 0) { sectionEl.style.display = 'none'; return; }
                sectionEl.style.display = 'block';
                listEl.innerHTML = '';
                recent.forEach(path => {
                    const el = document.createElement('div');
                    el.className = 'file';
                    const name = shortName(path);
                    el.innerHTML = '<span title="' + path + '">&#128196; ' + name + '</span>';
                    el.onclick = () => openFile(path, el);
                    listEl.appendChild(el);
                });
            } catch(e) { sectionEl.style.display = 'none'; }
        }

        function initEditor() {
            editor = CodeMirror.fromTextArea(document.getElementById('code'), {
                mode: 'application/x-httpd-php',
                lineNumbers: true,
                theme: 'material-darker',
                lineWrapping: true,
                indentUnit: 4,
                indentWithTabs: false,
                tabSize: 4
            });
            window.editor = editor;
            editor.setSize('100%', '100%');
            editor.on('change', () => {
                refreshClassListFromCode();
                isDirty = true;
                if (currentFile) {
                    document.getElementById('tabFile').textContent = shortName(currentFile) + ' *';
                }
                if (isSyncFromDesign) return;
                if (isApplyingUndoRedo) return;
                if (Date.now() < skipPreviewUpdateUntil) return;
                // If the page previously had a <body> structure but undo/change
                // removed it (or vice-versa), force a full blob reload so CSS
                // and override styles are rebuilt correctly.
                const nowHasBody = /<body\b/i.test(editor.getValue());
                if (nowHasBody !== lastPreviewHadBody) {
                    clearTimeout(previewDebounceTimer);
                    previewDebounceTimer = null;
                    isSyncFromCode = true;
                    updatePreview();
                    setTimeout(() => { isSyncFromCode = false; }, 500);
                    return;
                }
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = setTimeout(() => {
                    isSyncFromCode = true;
                    updatePreview();
                    setTimeout(() => { isSyncFromCode = false; }, 500);
                }, 500);
            });

            editor.on('cursorActivity', () => {
                if (isSelectionFromDesign) return;
                if (isApplyingUndoRedo) return;
                // Clear the yellow selection mark when the user moves the cursor in code
                if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
                syncSelectionToDesignFromCode();
            });

            window.addEventListener('keydown', e => {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveFile();
                }
                // Undo/Redo is handled natively by the browser in the iframe
                // and by CodeMirror in the code editor - no interception needed
            }, true);
            setViewMode('split');
            initSplitter();
        }

        function setViewMode(mode) {
            viewMode = mode;
            const split = document.getElementById('splitContainer');
            if (!split) return;
            // resetam latimile custom cand schimbam modul
            const leftPane = split.querySelector('.editor-pane');
            const rightPane = split.querySelector('.preview-pane');
            if (leftPane && rightPane) {
                leftPane.style.flex = '';
                rightPane.style.flex = '';
            }
            split.classList.remove('mode-code', 'mode-split', 'mode-design');
            if (mode === 'code') split.classList.add('mode-code');
            else if (mode === 'design') split.classList.add('mode-design');
            else split.classList.add('mode-split');
            const btnCode = document.getElementById('btnViewCode');
            const btnSplit = document.getElementById('btnViewSplit');
            const btnDesign = document.getElementById('btnViewDesign');
            if (btnCode && btnSplit && btnDesign) {
                btnCode.classList.toggle('active', mode === 'code');
                btnSplit.classList.toggle('active', mode === 'split');
                btnDesign.classList.toggle('active', mode === 'design');
            }
        }

        function initSplitter() {
            const divider = document.getElementById('splitDivider');
            const split = document.getElementById('splitContainer');
            if (!divider || !split) return;
            let dragging = false;
            let startX = 0;
            let startLeftWidth = 0;
            const leftPane = split.querySelector('.editor-pane');
            const rightPane = split.querySelector('.preview-pane');
            let overlay = split.querySelector('.split-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'split-overlay';
                split.appendChild(overlay);
            }
            divider.addEventListener('mousedown', e => {
                if (viewMode !== 'split') return;
                dragging = true;
                startX = e.clientX;
                startLeftWidth = leftPane.getBoundingClientRect().width;
                document.body.style.cursor = 'col-resize';
                overlay.style.display = 'block';
                e.preventDefault();
            });
            overlay.addEventListener('mousemove', e => {
                if (!dragging) return;
                const dx = e.clientX - startX;
                const totalWidth = split.getBoundingClientRect().width;
                let newLeft = Math.max(150, Math.min(totalWidth - 150, startLeftWidth + dx));
                const percent = (newLeft / totalWidth) * 100;
                leftPane.style.flex = '0 0 ' + percent + '%';
                rightPane.style.flex = '1 0 auto';
            });
            const stopDrag = () => {
                if (!dragging) return;
                dragging = false;
                document.body.style.cursor = '';
                overlay.style.display = 'none';
            };
            overlay.addEventListener('mouseup', stopDrag);
            window.addEventListener('mouseup', stopDrag);
        }

        function refreshClassListFromCode() {
            if (!editor) return;
            const code = editor.getValue();
            const re = /class\s*=\s*["']([^"']+)["']/gi;
            const set = new Set();
            let m;
            while ((m = re.exec(code)) !== null) {
                const parts = m[1].split(/\s+/);
                parts.forEach(c => {
                    if (c) set.add(c);
                });
            }
            cssClasses = Array.from(set).sort((a, b) => a.localeCompare(b, 'ro'));
            const sel = document.getElementById('propClass');
            if (!sel) return;
            sel.innerHTML = '<option value=\"\">(fara)</option>';
            cssClasses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                sel.appendChild(opt);
            });
        }

        async function refreshList(dir) {
            if (dir === undefined) dir = currentDir;
            currentDir = dir;
            const cont = document.getElementById('fileList');
            cont.textContent = 'Se incarca...';
            try {
                const url = '?action=list' + (dir ? '&dir=' + encodeURIComponent(dir) : '');
                const res = await fetch(url);
                const data = await res.json();
                renderList(data, dir);
            } catch (e) {
                cont.textContent = 'Eroare la listare';
            }
        }

        function renderList(items, dir) {
            const cont = document.getElementById('fileList');
            cont.innerHTML = '';
            if (dir) {
                const back = document.createElement('div');
                back.className = 'dir';
                back.style.cursor = 'pointer';
                back.style.color = '#60a5fa';
                back.innerHTML = '<span>&#8592; Inapoi</span>';
                const parent = dir.includes('/') ? dir.split('/').slice(0, -1).join('/') : '';
                back.onclick = () => refreshList(parent);
                cont.appendChild(back);
            }
            const dirLabel = document.createElement('div');
            dirLabel.className = 'dir';
            dirLabel.innerHTML = '<span>' + (dir || '(root)') + '</span>';
            cont.appendChild(dirLabel);

            const sorted = [...items].sort((a, b) => {
                if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
                return a.name.localeCompare(b.name);
            });
            sorted.forEach(it => {
                if (it.type === 'dir') {
                    const el = document.createElement('div');
                    el.className = 'file';
                    el.style.color = '#60a5fa';
                    el.innerHTML = '<span>&#128193; ' + it.name + '</span>';
                    el.onclick = () => refreshList(it.path);
                    cont.appendChild(el);
                } else {
                    const el = document.createElement('div');
                    el.className = 'file';
                    el.dataset.path = it.path;
                    el.innerHTML = '<span>' + it.name + '</span><span class="path">' + it.ext + '</span>';
                    el.onclick = () => openFile(it.path, el);
                    cont.appendChild(el);
                }
            });
        }

        async function openFile(path, el) {
            try {
                toast('Se deschide...');
                const res = await fetch('?action=load&file=' + encodeURIComponent(path));
                const data = await res.json();
                if (!data.ok) { toast('Eroare: ' + data.error); return; }
                currentFile = data.file;
                addToRecentFiles(currentFile);
                editor.setValue(data.content);
                editor.getDoc().clearHistory();
                // Cancel any preview-debounce that editor.setValue()'s change event just scheduled.
                // Without this, a second updatePreview() fires 500ms later, causing a race
                // condition where the DOMContentLoaded callback gets consumed by the wrong closure
                // and makeDesignEditable is never called on the final loaded page.
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = null;
                document.getElementById('tabFile').textContent = shortName(currentFile);
                document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
                if (el) el.classList.add('active');
                updatePreview();
                toast('Fisier incarcat: ' + currentFile);
                isDirty = false;
            } catch (e) {
                toast('Eroare la deschidere: ' + e.message);
            }
        }

        async function saveFile() {
            if (!currentFile) {
                toast('Acest fisier este nou (deschis prin Drag & Drop) si nu are o cale cunoscuta pe disc. Pentru a salva direct in locatia originala, deschide fisierul prin campul de cale sau din lista din stanga.');
                return;
            }
            try {
                const body = new URLSearchParams();
                body.append('file', currentFile);
                body.append('content', editor.getValue());
                const res = await fetch('?action=save', { method: 'POST', body });
                const data = await res.json();
                if (!data.ok) { toast('Eroare la salvare: ' + data.error); return; }
                document.getElementById('tabFile').textContent = shortName(currentFile);
                isDirty = false;
                toast('Salvat: ' + currentFile);
                updatePreview();
            } catch (e) {
                toast('Eroare la salvare');
            }
        }

        function isCurrentFileHtml() {
            if (!currentFile) return true;
            const n = currentFile.toLowerCase();
            return n.endsWith('.html') || n.endsWith('.htm');
        }

        function syncFromDesign() {
            if (isSyncFromCode) return;
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body || !editor) return;
            const full = editor.getValue();
            const bodyRe = /<body([^>]*)>[\s\S]*<\/body>/i;
            const bodyMatch = bodyRe.exec(full);
            if (!bodyMatch) return;
            const newBody = '<body' + bodyMatch[1] + '>' + doc.body.innerHTML + '</body>';
            isSyncFromDesign = true;
            const from = editor.posFromIndex(bodyMatch.index);
            const to = editor.posFromIndex(bodyMatch.index + bodyMatch[0].length);
            editor.replaceRange(newBody, from, to);
            isSyncFromDesign = false;
            isDirty = true;
            syncSelectionToCodeFromDesign();
        }

        function makeDesignEditable() {
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body || !isCurrentFileHtml()) return;
            doc.body.contentEditable = 'true';
            // Initialize the custom undo/redo stack for this freshly-loaded page.
            // The 500ms periodic timer inside captures typing snapshots automatically.
            designResetUndoStack();
            doc.body.addEventListener('input', () => {
                if (isApplyingUndoRedo) return;
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = setTimeout(syncFromDesign, 80);
            });
            doc.body.addEventListener('keyup', () => {
                if (isApplyingUndoRedo) return;
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = setTimeout(syncFromDesign, 80);
            });
            // Debounce selectionchange so we read the FINAL selection, not intermediate
            // states during a drag. 60ms is enough for the selection to settle.
            let _selChangeTimer = null;
            doc.addEventListener('selectionchange', () => {
                clearTimeout(_selChangeTimer);
                _selChangeTimer = setTimeout(() => {
                    if (!isSelectionFromCode) updatePropertiesPanelFromSelection();
                }, 60);
            });
            doc.addEventListener('mouseup', updatePropertiesPanelFromSelection);
            doc.addEventListener('keyup', updatePropertiesPanelFromSelection);
            // Prevent link navigation; sync images and anchors to code on click
            doc.addEventListener('click', e => {
                const target = e.target;
                if (!target || target === doc.body) return;
                // If the user has drag-selected text, don't override the selection
                // with a cursor-only sync. The selectionchange/mouseup handlers already
                // highlighted the selected text in the code editor.
                const iframeWin = document.getElementById('preview').contentWindow;
                const curSel = iframeWin && iframeWin.getSelection();
                if (curSel && !curSel.isCollapsed) return;
                const anchor = target.closest ? target.closest('a') : (target.tagName === 'A' ? target : null);
                if (anchor) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Sync to the actual clicked element (e.g. <img> inside <a>), not the anchor wrapper
                    if (typeof syncClickedElementToCode === 'function') syncClickedElementToCode(target);
                    return;
                }
                // Sync any clicked element to its position in the source code
                if (typeof syncClickedElementToCode === 'function') syncClickedElementToCode(target);
            }, true);
            doc.addEventListener('keydown', e => {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.parent && typeof window.parent.saveFile === 'function') {
                        window.parent.saveFile();
                    }
                } else if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) {
                    // Route Ctrl+Z through the parent's CodeMirror-based undo so that
                    // property-panel changes (font size, color) — which only add to the
                    // CodeMirror history, not the browser's native undo stack — are
                    // undone correctly too.
                    e.preventDefault();
                    if (window.parent && typeof window.parent.doUndoFromDesign === 'function') {
                        window.parent.doUndoFromDesign();
                    }
                } else if ((e.ctrlKey || e.metaKey) &&
                    (e.key === 'y' || e.key === 'Y' ||
                        (e.shiftKey && (e.key === 'z' || e.key === 'Z')))) {
                    e.preventDefault();
                    if (window.parent && typeof window.parent.doRedoFromDesign === 'function') {
                        window.parent.doRedoFromDesign();
                    }
                }
            }, true);
        }

        function cancelPreviewUpdateForUndoRedo() {
            clearTimeout(previewDebounceTimer);
            previewDebounceTimer = null;
            skipPreviewUpdateUntil = Date.now() + 700;
        }

        // Flush any pending design-to-code sync immediately
        function flushPendingDesignSync() {
            if (designInputDebounceTimer) {
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = null;
                syncFromDesign();
            }
        }

        // Apply current CodeMirror code back to the design panel body (fast, no iframe reload)
        function applyCodeToDesignPanel() {
            clearTimeout(designInputDebounceTimer);
            designInputDebounceTimer = null;
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body) return;
            const src = editor.getValue();
            const bodyMatch = /<body[^>]*>([\s\S]*)<\/body>/i.exec(src);
            if (!bodyMatch) return;
            isApplyingUndoRedo = true;
            isSyncFromCode = true;
            doc.body.innerHTML = bodyMatch[1];
            isSyncFromCode = false;
            isApplyingUndoRedo = false;
        }

        function doUndoFromDesign() {
            if (!editor || !isCurrentFileHtml()) return;
            const iframe = document.getElementById('preview');
            if (!iframe || !iframe.contentDocument || !iframe.contentDocument.body) return;
            // Flush any pending design→code sync so CodeMirror is in sync
            flushPendingDesignSync();
            // Capture the very latest state (in case typing hasn't been snapshot-ted yet)
            designSaveSnapshot();
            if (designUndoStack.length === 0) return;
            cancelPreviewUpdateForUndoRedo();
            // Push current state to redo
            const current = getDesignBodyHtml();
            if (current !== null) designRedoStack.push(current);
            // Pop previous state
            const prev = designUndoStack.pop();
            lastDesignSnapshot = prev;
            // Apply to iframe
            const win = iframe.contentWindow;
            const sx = win ? win.scrollX || 0 : 0;
            const sy = win ? win.scrollY || 0 : 0;
            isApplyingUndoRedo = true;
            isSyncFromCode = true;
            iframe.contentDocument.body.innerHTML = prev;
            if (win) win.scrollTo(sx, sy);
            isSyncFromCode = false;
            isApplyingUndoRedo = false;
            // Sync the reverted body back to the code editor
            syncFromDesign();
            focusDesignPanel(iframe);
        }

        function doRedoFromDesign() {
            if (!editor || !isCurrentFileHtml()) return;
            const iframe = document.getElementById('preview');
            if (!iframe || !iframe.contentDocument || !iframe.contentDocument.body) return;
            flushPendingDesignSync();
            if (designRedoStack.length === 0) return;
            cancelPreviewUpdateForUndoRedo();
            // Push current state to undo
            const current = getDesignBodyHtml();
            if (current !== null) {
                designUndoStack.push(current);
                if (designUndoStack.length > 100) designUndoStack.shift();
            }
            // Pop next state from redo
            const next = designRedoStack.pop();
            lastDesignSnapshot = next;
            // Apply to iframe
            const win = iframe.contentWindow;
            const sx = win ? win.scrollX || 0 : 0;
            const sy = win ? win.scrollY || 0 : 0;
            isApplyingUndoRedo = true;
            isSyncFromCode = true;
            iframe.contentDocument.body.innerHTML = next;
            if (win) win.scrollTo(sx, sy);
            isSyncFromCode = false;
            isApplyingUndoRedo = false;
            // Sync the redo-applied body back to the code editor
            syncFromDesign();
            focusDesignPanel(iframe);
        }

        function focusDesignPanel(iframe) {
            try {
                if (editor && editor.getWrapperElement) {
                    editor.getWrapperElement().blur();
                }
                if (iframe && iframe.contentWindow) {
                    iframe.contentWindow.focus();
                    const doc = iframe.contentDocument;
                    if (doc && doc.body) {
                        doc.body.focus();
                    }
                }
            } catch (e) { }
        }

        function refocusDesignAfterUndoRedo(iframe) {
            focusDesignPanel(iframe);
            setTimeout(function () { focusDesignPanel(iframe); }, 0);
            setTimeout(function () { focusDesignPanel(iframe); }, 50);
            setTimeout(function () { focusDesignPanel(iframe); }, 150);
            setTimeout(function () { focusDesignPanel(iframe); }, 300);
        }

        function applyUndoRedoToDesign(savedCodeScroll, savedIframeScroll) {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body || !editor || !isCurrentFileHtml()) return;
            const win = iframe.contentWindow;
            const scrollX = savedIframeScroll ? savedIframeScroll.x : (win.scrollX || 0);
            const scrollY = savedIframeScroll ? savedIframeScroll.y : (win.scrollY || 0);
            const full = editor.getValue();
            const bodyRe = /<body\b[^>]*>([\s\S]*?)<\/body>/i;
            const m = bodyRe.exec(full);
            if (!m) return;
            isApplyingUndoRedo = true;
            clearTimeout(designInputDebounceTimer);
            designInputDebounceTimer = null;
            isSyncFromCode = true;
            doc.body.innerHTML = m[1];
            win.scrollTo(scrollX, scrollY);
            if (savedCodeScroll) {
                editor.scrollTo(savedCodeScroll.left, savedCodeScroll.top);
            }
            isSyncFromCode = false;
            isApplyingUndoRedo = false;
        }

        // Called by the injected DOMContentLoaded script in the preview iframe,
        // so makeDesignEditable runs as soon as the DOM is parsed — not after
        // all external CSS/fonts finish loading (which can take 30+ seconds).
        window.__previewDOMReady = null;

        function updatePreview() {
            const iframe = document.getElementById('preview');

            // Files with a known path on disk → use PHP preview.
            // PHP strips scripts/loaders, rebuilds clean HTML, and serves it
            // as a normal HTTP response so relative CSS/images resolve correctly
            // (blob: URLs have issues with "preferred" stylesheets and encoded paths).
            if (currentFile) {
                let done = false;
                const doEdit = () => {
                    if (done) return;
                    done = true;
                    if (isCurrentFileHtml()) makeDesignEditable();
                };
                window.__previewDOMReady = doEdit;
                iframe.onload = doEdit;
                iframe.src = '?action=preview&file=' + encodeURIComponent(currentFile) + '&t=' + Date.now();
                return;
            }

            // No currentFile (e.g. drag & drop without path match) → blob approach
            if (!editor) return;
            let content = editor.getValue();

            // ── Strip ALL JavaScript from the entire content ──
            content = content.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
            content = content.replace(/<script\b[^>]*\/>/gi, '');
            content = content.replace(/<noscript\b[^>]*>[\s\S]*?<\/noscript>/gi, '');

            // ── Collect stylesheets ──
            let allStyles = '';
            const linkRe = /<link\b[^>]*\brel=["\']?stylesheet["\']?[^>]*\/?>/gi;
            let lm;
            while ((lm = linkRe.exec(content)) !== null) {
                let tag = lm[0];
                tag = tag.replace(/\s+on\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]*)/gi, '');
                tag = tag.replace(/\bmedia\s*=\s*["']?\s*print\s*["']?/i, 'media="all"');
                tag = tag.replace(/\s+title\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]*)/gi, '');
                allStyles += tag + '\n';
            }
            const styleRe = /<style\b[^>]*>([\s\S]*?)<\/style>/gi;
            let sm;
            while ((sm = styleRe.exec(content)) !== null) {
                if (sm[1].trim()) allStyles += '<style type="text/css">' + sm[1] + '</style>\n';
            }

            // ── Override CSS — force ALL elements visible, hide loaders ──
            const editorOverride = '<style type="text/css" id="editor-override">'
                + 'html,body{visibility:visible!important;opacity:1!important}'
                + 'body{display:block!important}'
                + 'body *{visibility:visible!important;opacity:1!important;'
                + 'animation:none!important;transition:none!important}'
                + '#preloader,.preloader,#loader,.loader,#loader-fade,'
                + '.loading-overlay,.page-loader,.loading-screen,'
                + '#loading-overlay,#page-loading,.site-loader,'
                + '.loader-container,.spinner,#spinner,'
                + '[class*="preload"],[id*="preload"],'
                + '[class*="page-load"],[id*="page-load"]'
                + '{display:none!important}'
                + '</style>\n';

            // ── Extract and sanitise body content ──
            const bodyOpenMatch = /<body\b[^>]*>/i.exec(content);
            if (bodyOpenMatch) {
                const bodyTagEnd = bodyOpenMatch.index + bodyOpenMatch[0].length;
                const lastBodyClose = content.toLowerCase().lastIndexOf('</body>');
                const bodyEnd = (lastBodyClose !== -1 && lastBodyClose >= bodyTagEnd)
                    ? lastBodyClose : content.length;
                let bodyInner = content.substring(bodyTagEnd, bodyEnd);
                bodyInner = bodyInner.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
                bodyInner = bodyInner.replace(/\s+on\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]*)/gi, '');
                bodyInner = bodyInner.replace(/\bhref\s*=\s*["']?\s*javascript\s*:[^"'>\s]*/gi, 'href="#"');
                let bodyTag = bodyOpenMatch[0].replace(/\s+on\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]*)/gi, '');
                content = '<!DOCTYPE html><html><head>' + allStyles + editorOverride + '</head>'
                    + bodyTag + bodyInner + '</body></html>';
            }

            const blob = new Blob([content], { type: 'text/html;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            iframe.onload = () => {
                makeDesignEditable();
                URL.revokeObjectURL(url);
            };
            iframe.src = url;
        }

        function hideOverlay() {
            const ov = document.getElementById('startOverlay');
            if (ov) ov.style.display = 'none';
        }

        function showOverlay() {
            const ov = document.getElementById('startOverlay');
            if (ov) {
                ov.style.display = 'flex';
                renderRecentFiles();
            }
        }

        function getDesignSelection() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return null;
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            let node = sel.anchorNode;
            if (!node) return null;
            if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
            if (!node || !node.closest) return node;
            return node.closest('p, span, div, td, th, li, h1, h2, h3, h4, h5, h6, a, strong, em');
        }

        function getDesignSelectionRange() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return null;
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            const range = sel.getRangeAt(0);
            return { win, doc, sel, range };
        }

        function getDesignSelectionText() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return '';
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return '';
            let text = sel.toString().trim();
            if (text) return text;
            const node = sel.anchorNode && sel.anchorNode.nodeType === Node.TEXT_NODE ? sel.anchorNode : null;
            if (!node) return '';
            const value = node.nodeValue || '';
            const offset = sel.anchorOffset || 0;
            const left = value.slice(0, offset).split(/\s+/).pop() || '';
            const right = value.slice(offset).split(/\s+/)[0] || '';
            text = (left + right).trim();
            return text;
        }

        // Calculate the text-only offset of the selection's start within the body
        function getDesignSelectionTextOffset() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc || !doc.body) return -1;
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return -1;
            const range = sel.getRangeAt(0);
            // Create a range from body start to selection start
            const preRange = doc.createRange();
            preRange.setStart(doc.body, 0);
            preRange.setEnd(range.startContainer, range.startOffset);
            // Get the text content before the selection
            const textBefore = preRange.toString();
            return textBefore.length;
        }

        function syncSelectionToCodeFromDesign() {
            if (!editor) return;
            const text = getDesignSelectionText();
            if (!text) return;
            const src = editor.getValue();
            const bodyRe = /<body[^>]*>([\s\S]*)<\/body>/i;
            const bodyMatch = bodyRe.exec(src);
            let bodyHTML, bodyStartIdx;
            if (bodyMatch) {
                bodyHTML = bodyMatch[1];
                const openTagLen = bodyMatch[0].length - bodyMatch[1].length - 7; // 7 = '</body>'.length
                bodyStartIdx = bodyMatch.index + openTagLen;
            } else {
                bodyHTML = src;
                bodyStartIdx = 0;
            }
            const needle = text.toLowerCase();

            // Get the text offset of the selection in the design panel
            const designTextOffset = getDesignSelectionTextOffset();

            if (designTextOffset >= 0) {
                // Position-aware search: scan through bodyHTML, tracking text offset
                // to find where designTextOffset falls in the source code
                let textPos = 0; // current position in plain text
                let inTag = false;
                let targetSourceIdx = -1;
                let targetLen = 0;

                for (let i = 0; i < bodyHTML.length; i++) {
                    if (bodyHTML[i] === '<') {
                        inTag = true;
                        continue;
                    }
                    if (bodyHTML[i] === '>') {
                        inTag = false;
                        continue;
                    }
                    if (!inTag) {
                        // Handle HTML entities: &amp; &lt; etc.
                        if (bodyHTML[i] === '&') {
                            const entityEnd = bodyHTML.indexOf(';', i);
                            if (entityEnd !== -1 && entityEnd - i < 10) {
                                // This is an HTML entity, counts as 1 character in text
                                if (textPos === designTextOffset) {
                                    targetSourceIdx = i;
                                }
                                textPos++;
                                i = entityEnd; // skip to end of entity
                                continue;
                            }
                        }
                        if (textPos === designTextOffset) {
                            targetSourceIdx = i;
                        }
                        textPos++;
                    }
                }

                // Now search for the needle near targetSourceIdx in the source
                if (targetSourceIdx >= 0) {
                    // Search within a window around the target position
                    const searchStart = Math.max(0, targetSourceIdx - 50);
                    const searchEnd = Math.min(bodyHTML.length, targetSourceIdx + needle.length + 50);
                    const searchArea = bodyHTML.substring(searchStart, searchEnd).toLowerCase();
                    const localIdx = searchArea.indexOf(needle, Math.max(0, targetSourceIdx - searchStart - 20));
                    if (localIdx !== -1) {
                        const globalIdx = bodyStartIdx + searchStart + localIdx;
                        highlightSelectionInCode(globalIdx, text.length);
                        return;
                    }
                }
            }

            // Fallback: simple text search (first occurrence) if position-aware failed
            const bodyLower = bodyHTML.toLowerCase();
            const localIdx = bodyLower.indexOf(needle);
            if (localIdx === -1) return;
            highlightSelectionInCode(bodyStartIdx + localIdx, text.length);
        }

        // Move the CodeMirror selection to [globalIdx, globalIdx+len] and apply
        // a yellow markText so the selected design text is clearly visible in the code.
        function highlightSelectionInCode(globalIdx, len) {
            const from = editor.posFromIndex(globalIdx);
            const to   = editor.posFromIndex(globalIdx + len);
            // Clear any previous selection highlight mark
            if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
            isSelectionFromDesign = true;
            editor.setSelection(from, to);
            editor.scrollIntoView({ from, to }, 100);
            isSelectionFromDesign = false;
            // Yellow markText — stays until the next selection or a click in the code editor
            syncSelectionMark = editor.markText(from, to, { className: 'cm-selection-highlight' });
        }

        // Scan forward from position j in html, skipping past the end of the current tag
        // (properly handling quoted attribute values that may contain '>').
        // Returns the index of '>' that closes the tag, or -1.
        function findTagClose(html, j) {
            while (j < html.length) {
                const ch = html[j];
                if (ch === '>') return j;
                if (ch === '"') { j++; while (j < html.length && html[j] !== '"') j++; }
                else if (ch === "'") { j++; while (j < html.length && html[j] !== "'") j++; }
                j++;
            }
            return -1;
        }

        // Walk source HTML to find the (childIdx)-th element child starting at offset.
        // Returns the position of the '<' that opens that element, or -1.
        function findChildOpenInSource(html, offset, childIdx) {
            const VOID = new Set(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']);
            let depth = 0;
            let elemCount = 0;
            let i = offset;
            while (i < html.length) {
                const lt = html.indexOf('<', i);
                if (lt === -1) break;
                i = lt;
                const c1 = html[i + 1];

                // HTML comment: <!-- ... --> — must end with -->, NOT just first >
                if (c1 === '!' && html[i + 2] === '-' && html[i + 3] === '-') {
                    const end = html.indexOf('-->', i + 4);
                    i = end === -1 ? html.length : end + 3;
                    continue;
                }
                // DOCTYPE, CDATA, processing instruction — skip to next >
                if (c1 === '!' || c1 === '?') {
                    const end = html.indexOf('>', i + 1);
                    i = end === -1 ? html.length : end + 1;
                    continue;
                }

                const isClose = c1 === '/';
                const nameStart = isClose ? i + 2 : i + 1;

                // Parse tag name using char codes — avoids slow slice().match()
                let nameEnd = nameStart;
                while (nameEnd < html.length) {
                    const cc = html.charCodeAt(nameEnd);
                    if (!((cc >= 65 && cc <= 90) || (cc >= 97 && cc <= 122) ||
                          (cc >= 48 && cc <= 57) || cc === 45)) break; // A-Z a-z 0-9 -
                    nameEnd++;
                }
                if (nameEnd === nameStart) { i++; continue; }
                const currTag = html.slice(nameStart, nameEnd).toLowerCase();

                // Find real end of tag, respecting quoted attribute values
                const gt = findTagClose(html, nameEnd);
                if (gt === -1) break;
                const isSelfClose = html[gt - 1] === '/';
                const isVoid = VOID.has(currTag);
                const tagEnd = gt + 1;

                if (!isClose) {
                    if (depth === 0) {
                        if (elemCount === childIdx) return i;
                        elemCount++;
                    }
                    if (!isVoid && !isSelfClose) depth++;
                } else {
                    if (depth > 0) depth--;
                }
                i = tagEnd;
            }
            return -1;
        }

        // Walk a path array through bodyHTML using findChildOpenInSource.
        // Returns the source offset of the final element, or -1.
        function walkPathInSource(bodyHTML, path) {
            let offset = 0;
            for (let step = 0; step < path.length; step++) {
                const pos = findChildOpenInSource(bodyHTML, offset, path[step]);
                if (pos === -1) return -1;
                if (step === path.length - 1) return pos;
                // Move past the opening tag into element content, respecting quoted attrs
                const gt = findTagClose(bodyHTML, pos + 1);
                if (gt === -1) return -1;
                offset = gt + 1;
            }
            return -1;
        }

        // Find the position of a clicked DOM element in the HTML source body.
        // Returns character offset within bodyHTML, or -1.
        function findElementPositionInSource(el, bodyHTML) {
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body) return -1;
            // Elements browsers auto-insert inside <table> (not in original HTML)
            const AUTO_WRAP = new Set(['tbody', 'thead', 'tfoot']);
            // Build path from body to element: array of child-element-indices at each level
            const buildPath = (skipAutoWrap) => {
                const path = [];
                let node = el;
                while (node && node.parentElement && node !== doc.body) {
                    const parent = node.parentElement;
                    const pTag = (parent.tagName || '').toLowerCase();
                    if (skipAutoWrap && AUTO_WRAP.has(pTag) && parent.attributes.length === 0) {
                        // Auto-inserted wrapper: use node's index within wrapper, skip wrapper level
                        let ci = 0;
                        for (let k = 0; k < parent.children.length; k++) {
                            if (parent.children[k] === node) { ci = k; break; }
                        }
                        path.unshift(ci);
                        node = parent.parentElement || parent; // jump up past the auto-wrapper
                        continue;
                    }
                    let ci = 0;
                    for (let k = 0; k < parent.children.length; k++) {
                        if (parent.children[k] === node) { ci = k; break; }
                    }
                    path.unshift(ci);
                    node = parent;
                }
                return path;
            };
            // Try normal path first; if it fails, retry skipping auto-inserted wrappers
            const path = buildPath(false);
            if (!path.length) return -1;
            const result = walkPathInSource(bodyHTML, path);
            if (result !== -1) return result;
            const altPath = buildPath(true);
            if (altPath.length && altPath.join(',') !== path.join(',')) {
                return walkPathInSource(bodyHTML, altPath);
            }
            return -1;
        }

        // Sync the code editor cursor to the position of a DOM element clicked in design panel.
        function syncClickedElementToCode(el) {
            if (!editor || !el) return;
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body || el === doc.body) return;
            const src = editor.getValue();
            const bodyRe = /<body[^>]*>([\s\S]*)<\/body>/i;
            const bodyMatch = bodyRe.exec(src);
            let bodyHTML, bodyStartIdx;
            if (bodyMatch) {
                bodyHTML = bodyMatch[1];
                // Robust: body content starts after opening <body...> tag
                const openTagLen = bodyMatch[0].length - bodyMatch[1].length - 7; // 7 = '</body>'.length
                bodyStartIdx = bodyMatch.index + openTagLen;
            } else {
                // No <body> tags — search entire source (bare HTML fragment)
                bodyHTML = src;
                bodyStartIdx = 0;
            }
            const tagName = (el.tagName || '').toLowerCase();
            let idx = -1;

            // Strategy 1: match image by src attribute
            if (tagName === 'img') {
                const srcAttr = el.getAttribute('src') || '';
                if (srcAttr) {
                    const esc = srcAttr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const m = new RegExp('<img\\b[^>]*\\bsrc=["\']?' + esc, 'i').exec(bodyHTML);
                    if (m) idx = m.index;
                    if (idx === -1) {
                        const fname = srcAttr.split('/').pop();
                        if (fname) {
                            const esc2 = fname.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                            const m2 = new RegExp('<img\\b[^>]*' + esc2, 'i').exec(bodyHTML);
                            if (m2) idx = m2.index;
                        }
                    }
                }
            }

            // Strategy 2: match anchor by href attribute
            if (idx === -1 && tagName === 'a') {
                const hrefAttr = el.getAttribute('href') || '';
                if (hrefAttr) {
                    const esc = hrefAttr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const m = new RegExp('<a\\b[^>]*\\bhref=["\']?' + esc, 'i').exec(bodyHTML);
                    if (m) idx = m.index;
                }
            }

            // Strategy 3: match by id attribute
            if (idx === -1) {
                const idAttr = el.getAttribute ? (el.getAttribute('id') || '') : '';
                if (idAttr) {
                    const esc = idAttr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const m = new RegExp('<' + tagName + '\\b[^>]*\\bid=["\']' + esc + '["\']', 'i').exec(bodyHTML);
                    if (m) idx = m.index;
                }
            }

            // Strategy 4: DOM-tree position
            if (idx === -1) {
                idx = findElementPositionInSource(el, bodyHTML);
            }

            // Strategy 5: nearest block-level ancestor (fallback for inline elements)
            if (idx === -1) {
                const BLOCK = new Set(['p','div','h1','h2','h3','h4','h5','h6','li','td','th','tr','section','article','blockquote','pre','figure','header','footer','nav','main']);
                let ancestor = el.parentElement;
                while (ancestor && ancestor !== doc.body) {
                    const aTag = (ancestor.tagName || '').toLowerCase();
                    if (BLOCK.has(aTag)) {
                        idx = findElementPositionInSource(ancestor, bodyHTML);
                        if (idx !== -1) break;
                    }
                    ancestor = ancestor.parentElement;
                }
            }

            if (idx !== -1) {
                const globalIdx = bodyStartIdx + idx;
                const from = editor.posFromIndex(globalIdx);
                // Clear any previous text-selection highlight before setting cursor
                if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
                isSelectionFromDesign = true;
                editor.setCursor(from);
                editor.scrollIntoView(from, 100);
                isSelectionFromDesign = false;
                // Flash the line yellow to give clear visual feedback
                const lh = editor.addLineClass(from.line, 'background', 'cm-sync-highlight');
                setTimeout(() => editor.removeLineClass(lh, 'background', 'cm-sync-highlight'), 900);
            }
        }

        function syncSelectionToDesignFromCode() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc || !doc.body || !editor) return;
            const selText = (editor.getSelection() || editor.getTokenAt(editor.getCursor()).string || '').trim();
            if (!selText) return;

            // Verificam daca pozitia din cod este in interiorul <body>...</body>
            const src = editor.getValue();
            const bodyRe = /<body[^>]*>([\s\S]*)<\/body>/i;
            const bodyMatch = bodyRe.exec(src);
            if (!bodyMatch) return;
            const bodyStart = bodyMatch.index;
            const bodyContentStart = bodyMatch.index + bodyMatch[0].indexOf(bodyMatch[1]);
            const bodyEnd = bodyMatch.index + bodyMatch[0].length;
            const curIndex = editor.indexFromPos(editor.getCursor());
            if (curIndex < bodyStart || curIndex >= bodyEnd) {
                return;
            }

            // Calculate text offset of cursor position within body HTML (skipping tags)
            const bodyHTML = bodyMatch[1];
            const cursorInBody = curIndex - bodyContentStart;
            let textOffset = 0;
            let inTag = false;
            for (let i = 0; i < bodyHTML.length && i < cursorInBody; i++) {
                if (bodyHTML[i] === '<') {
                    inTag = true;
                    continue;
                }
                if (bodyHTML[i] === '>') {
                    inTag = false;
                    continue;
                }
                if (!inTag) {
                    if (bodyHTML[i] === '&') {
                        const entityEnd = bodyHTML.indexOf(';', i);
                        if (entityEnd !== -1 && entityEnd - i < 10) {
                            textOffset++;
                            i = entityEnd;
                            continue;
                        }
                    }
                    textOffset++;
                }
            }

            // Find the text node at this offset in the design panel
            const target = selText.toLowerCase();
            const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, null);
            let currentOffset = 0;
            let tNode;
            let bestNode = null, bestStart = 0;
            while ((tNode = walker.nextNode())) {
                const nodeLen = tNode.nodeValue.length;
                // Check if our target offset falls within or near this text node
                if (currentOffset + nodeLen > textOffset - selText.length || currentOffset >= textOffset - selText.length) {
                    // Search for the needle in this text node
                    const nodeLower = tNode.nodeValue.toLowerCase();
                    let searchFrom = 0;
                    // If the target offset is within this node, start searching near that position
                    if (textOffset >= currentOffset && textOffset < currentOffset + nodeLen) {
                        searchFrom = Math.max(0, textOffset - currentOffset - selText.length);
                    }
                    const idx = nodeLower.indexOf(target, searchFrom);
                    if (idx !== -1) {
                        bestNode = tNode;
                        bestStart = idx;
                        break;
                    }
                }
                currentOffset += nodeLen;
            }

            if (!bestNode) {
                // Fallback: search all text nodes for first match
                const walker2 = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, {
                    acceptNode(node) {
                        if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                        return node.nodeValue.toLowerCase().includes(target) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
                    }
                });
                bestNode = walker2.nextNode();
                if (!bestNode) return;
                bestStart = bestNode.nodeValue.toLowerCase().indexOf(target);
                if (bestStart === -1) return;
            }

            const range = doc.createRange();
            range.setStart(bestNode, bestStart);
            range.setEnd(bestNode, bestStart + selText.length);
            const sel = win.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            if (bestNode.parentElement && bestNode.parentElement.scrollIntoView) {
                bestNode.parentElement.scrollIntoView({ block: 'center' });
            }
        }

        function updatePropertiesPanelFromSelection() {
            const el = getDesignSelection();
            const fontSel = document.getElementById('propFont');
            const sizeSel = document.getElementById('propSize');
            const boldBtn = document.getElementById('propBold');
            const italicBtn = document.getElementById('propItalic');
            const colorInp = document.getElementById('propColor');
            const bgInp = document.getElementById('propBg');
            const classSel = document.getElementById('propClass');
            if (!el) return;
            const st = el.style;
            const computed = el.ownerDocument.defaultView.getComputedStyle(el);
            const fontVal = (st.fontFamily || computed.fontFamily || '').split(',')[0].replace(/['"]/g, '').trim();
            const opt = [].find.call(fontSel.options, o => o.value === fontVal || (o.value && o.value.indexOf(fontVal) === 0));
            fontSel.value = opt ? opt.value : '';
            const px = computed.fontSize ? parseFloat(computed.fontSize) : 0;
            sizeSel.value = px ? String(Math.round(px)) : '';
            boldBtn.style.background = (computed.fontWeight === '700' || computed.fontWeight === 'bold') ? 'rgba(59,130,246,0.3)' : '';
            italicBtn.style.background = computed.fontStyle === 'italic' ? 'rgba(59,130,246,0.3)' : '';
            colorInp.value = rgbToHex(computed.color) || '#000000';
            bgInp.value = rgbToHex(computed.backgroundColor) || '#ffffff';
            if (classSel) {
                const cls = (el.className || '').trim().split(/\s+/)[0] || '';
                classSel.value = cssClasses.includes(cls) ? cls : '';
            }
            if (!isSelectionFromCode) {
                syncSelectionToCodeFromDesign();
            }
        }

        function rgbToHex(rgb) {
            if (!rgb || rgb === 'rgba(0, 0, 0, 0)' || rgb === 'transparent') return '#ffffff';
            const m = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
            if (!m) return null;
            return '#' + [1, 2, 3].map(i => ('0' + parseInt(m[i], 10).toString(16)).slice(-2)).join('');
        }

        function applyFontProperty(prop, value) {
            // Push the current body state BEFORE the change so undo can revert it.
            // Uses 500ms grouping so dragging the color picker only creates ONE undo entry.
            designPushCurrentState();
            const info = getDesignSelectionRange();
            if (info && !info.range.collapsed) {
                const { doc, sel, range } = info;
                const span = doc.createElement('span');
                const frag = range.extractContents();
                span.appendChild(frag);
                if (prop === 'fontFamily') span.style.fontFamily = value || null;
                else if (prop === 'fontSize') span.style.fontSize = value ? (value + 'px') : null;
                else if (prop === 'color') span.style.color = value || null;
                else if (prop === 'backgroundColor') span.style.backgroundColor = value || null;
                range.insertNode(span);
                sel.removeAllRanges();
                sel.selectAllChildren(span);
            } else {
                const el = getDesignSelection();
                if (!el) return;
                if (prop === 'fontFamily') el.style.fontFamily = value || null;
                else if (prop === 'fontSize') el.style.fontSize = value ? (value + 'px') : null;
                else if (prop === 'color') el.style.color = value || null;
                else if (prop === 'backgroundColor') el.style.backgroundColor = value || null;
            }
            syncFromDesign();
            // Record the post-change state so the next snapshot won't double-push
            lastDesignSnapshot = getDesignBodyHtml();
        }

        function toggleInlineFormat(kind) {
            // Push the current body state BEFORE the change so undo can revert it
            designPushCurrentState();
            const tagName = kind === 'bold' ? 'strong' : 'em';
            const info = getDesignSelectionRange();
            if (info && !info.range.collapsed) {
                const { doc, sel, range } = info;
                // Save selected text for re-selection
                const selectedText = range.toString();
                let node = range.commonAncestorContainer;
                if (node.nodeType === 3) node = node.parentElement;
                let fmt = node && node.closest ? node.closest(tagName) : null;
                if (fmt && fmt.tagName.toLowerCase() === tagName) {
                    // Remove formatting: unwrap the tag but keep selection on original text
                    const parent = fmt.parentNode;
                    // Collect child nodes before unwrapping
                    const childNodes = Array.from(fmt.childNodes);
                    // Get the text content that was selected inside fmt
                    const firstChild = fmt.firstChild;
                    const lastChild = fmt.lastChild;
                    while (fmt.firstChild) parent.insertBefore(fmt.firstChild, fmt);
                    parent.removeChild(fmt);
                    parent.normalize();
                    // Re-select the same text in the parent
                    sel.removeAllRanges();
                    if (selectedText) {
                        const newRange = findTextRangeInNode(doc, parent, selectedText);
                        if (newRange) {
                            sel.addRange(newRange);
                        }
                    }
                } else {
                    const wrapper = doc.createElement(tagName);
                    try {
                        range.surroundContents(wrapper);
                    } catch (e) {
                        const frag = range.extractContents();
                        wrapper.appendChild(frag);
                        range.insertNode(wrapper);
                    }
                    sel.removeAllRanges();
                    const newRange = doc.createRange();
                    newRange.selectNodeContents(wrapper);
                    sel.addRange(newRange);
                }
            } else {
                const el = getDesignSelection();
                if (!el) return;
                const doc = el.ownerDocument;
                const existing = el.querySelector(tagName);
                if (existing) {
                    const parent = existing.parentNode;
                    while (existing.firstChild) parent.insertBefore(existing.firstChild, existing);
                    parent.removeChild(existing);
                } else {
                    const wrapper = doc.createElement(tagName);
                    while (el.firstChild) wrapper.appendChild(el.firstChild);
                    el.appendChild(wrapper);
                }
            }
            syncFromDesign();
            // Record the post-change state so the next snapshot won't double-push
            lastDesignSnapshot = getDesignBodyHtml();
        }

        // Helper: find a text range inside a node that matches the given text
        function findTextRangeInNode(doc, container, searchText) {
            if (!searchText || !container) return null;
            const walker = doc.createTreeWalker(container, NodeFilter.SHOW_TEXT, null);
            let accumulated = '';
            const textNodes = [];
            let tNode;
            while ((tNode = walker.nextNode())) {
                textNodes.push({ node: tNode, start: accumulated.length });
                accumulated += tNode.nodeValue;
            }
            const idx = accumulated.indexOf(searchText);
            if (idx === -1) return null;
            const endIdx = idx + searchText.length;
            let startNode = null, startOffset = 0, endNode = null, endOffset = 0;
            for (let i = 0; i < textNodes.length; i++) {
                const tn = textNodes[i];
                const tnEnd = tn.start + tn.node.nodeValue.length;
                if (!startNode && idx >= tn.start && idx < tnEnd) {
                    startNode = tn.node;
                    startOffset = idx - tn.start;
                }
                if (endIdx > tn.start && endIdx <= tnEnd) {
                    endNode = tn.node;
                    endOffset = endIdx - tn.start;
                    break;
                }
            }
            if (!startNode || !endNode) return null;
            const range = doc.createRange();
            range.setStart(startNode, startOffset);
            range.setEnd(endNode, endOffset);
            return range;
        }

        function applyClassProperty(value) {
            // Push the current body state BEFORE the change so undo can revert it
            designPushCurrentState();
            const info = getDesignSelectionRange();
            if (info && !info.range.collapsed) {
                const { doc, sel, range } = info;
                const selectedText = range.toString();
                let node = range.commonAncestorContainer;
                if (node.nodeType === 3) node = node.parentElement;
                let span = node && node.closest ? node.closest('span') : null;
                if (span && span !== doc.body) {
                    // Save before execCommand modifies the DOM
                    const innerHTML = span.innerHTML;
                    const parentNode = span.parentNode;
                    const r = doc.createRange();
                    r.selectNode(span);
                    sel.removeAllRanges();
                    sel.addRange(r);
                    if (value && span.classList.contains(value)) {
                        // Toggle off: unwrap the span via execCommand (records in native undo history)
                        doc.execCommand('insertHTML', false, innerHTML);
                        if (parentNode) parentNode.normalize();
                        if (selectedText) {
                            const newRange = findTextRangeInNode(doc, parentNode || doc.body, selectedText);
                            if (newRange) { sel.removeAllRanges(); sel.addRange(newRange); }
                        }
                    } else if (value) {
                        // Replace existing class with new one via execCommand
                        doc.execCommand('insertHTML', false, '<span class="' + value + '">' + innerHTML + '</span>');
                    } else {
                        // Remove class: unwrap the span via execCommand
                        doc.execCommand('insertHTML', false, innerHTML);
                        if (parentNode) parentNode.normalize();
                        if (selectedText) {
                            const newRange = findTextRangeInNode(doc, parentNode || doc.body, selectedText);
                            if (newRange) { sel.removeAllRanges(); sel.addRange(newRange); }
                        }
                    }
                } else {
                    // No existing span - wrap selection in new span via execCommand
                    if (value) {
                        const frag = range.cloneContents();
                        const temp = doc.createElement('div');
                        temp.appendChild(frag);
                        const selectedHTML = temp.innerHTML;
                        doc.execCommand('insertHTML', false, '<span class="' + value + '">' + selectedHTML + '</span>');
                    }
                }
            } else {
                const el = getDesignSelection();
                if (!el) return;
                const iframe = document.getElementById('preview');
                const win = iframe.contentWindow;
                const iDoc = iframe.contentDocument;
                const iSel = win.getSelection();
                const newEl = el.cloneNode(true);
                if (value) {
                    newEl.className = value;
                } else {
                    newEl.removeAttribute('class');
                }
                const r = iDoc.createRange();
                r.selectNode(el);
                iSel.removeAllRanges();
                iSel.addRange(r);
                iDoc.execCommand('insertHTML', false, newEl.outerHTML);
            }
            syncFromDesign();
            // Record the post-change state so the next snapshot won't double-push
            lastDesignSnapshot = getDesignBodyHtml();
        }

        async function openFromPath() {
            const inp = document.getElementById('pathInput');
            let p = (inp.value || '').trim();
            p = p.replace(/^["']+|["']+$/g, '').trim();
            if (!p) { dropStatus('Scrie calea catre fisier', '#ef4444'); return; }
            dropStatus('Se deschide...', '#60a5fa');
            try {
                const res = await fetch('?action=load&file=' + encodeURIComponent(p));
                if (!res.ok) { dropStatus('Eroare HTTP ' + res.status, '#ef4444'); return; }
                const txt = await res.text();
                let data;
                try { data = JSON.parse(txt); } catch (e) { dropStatus('Raspuns invalid (nu e JSON)', '#ef4444'); return; }
                if (!data.ok) { dropStatus('Eroare: ' + (data.error || 'necunoscuta'), '#ef4444'); return; }
                currentFile = data.file;
                addToRecentFiles(currentFile);
                editor.setValue(data.content || '');
                editor.getDoc().clearHistory();
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = null;
                document.getElementById('tabFile').textContent = shortName(currentFile);
                document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
                updatePreview();
                toast('Fisier incarcat: ' + currentFile);
                hideOverlay();
                dropStatus('');
                isDirty = false;
            } catch (e) {
                dropStatus('Eroare: ' + e.message, '#ef4444');
            }
        }

        function closeEditor() {
            if (!editor) { showOverlay(); return; }
            if (currentFile) {
                addToRecentFiles(currentFile);
            }
            const hasContent = editor.getValue().trim().length > 0;
            if (isDirty && hasContent) {
                const doSave = confirm('Exista modificari nesalvate. Vrei sa le salvezi inainte de inchidere?');
                if (doSave) {
                    saveFile();
                }
            }
            editor.setValue('');
            currentFile = null;
            document.getElementById('tabFile').textContent = 'Niciun fisier deschis';
            const iframe = document.getElementById('preview');
            if (iframe) iframe.src = 'about:blank';
            document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
            isDirty = false;
            showOverlay();
        }

        const ALLOWED_EXT = ['.html', '.htm', '.css', '.js', '.php'];
        function hasAllowedExt(filename) {
            const n = (filename || '').toLowerCase();
            return ALLOWED_EXT.some(ext => n.endsWith(ext));
        }

        function dropStatus(msg, color) {
            const el = document.getElementById('dropStatus');
            if (!el) return;
            el.style.display = msg ? 'block' : 'none';
            el.style.color = color || '#facc15';
            el.textContent = msg || '';
        }

        function handleDropFile(file) {
            if (!file) { dropStatus('Niciun fisier primit', '#ef4444'); return; }
            if (!hasAllowedExt(file.name)) { dropStatus('Doar fisiere HTML, CSS, JS sau PHP', '#ef4444'); return; }
            dropStatus('Se citeste fisierul "' + file.name + '"...', '#60a5fa');
            const reader = new FileReader();
            reader.onload = function () {
                editor.setValue(reader.result || '');
                editor.getDoc().clearHistory();
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = null;
                currentFile = null;
                document.getElementById('tabFile').textContent = file.name;
                updatePreview();
                document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
                hideOverlay();
                dropStatus('');
                // Cauta automat calea fisierului pe disc dupa nume
                const extraDirs = getRecentDirs();
                const searchUrl = '?action=search&name=' + encodeURIComponent(file.name)
                    + (extraDirs.length ? '&dirs=' + encodeURIComponent(JSON.stringify(extraDirs)) : '');
                fetch(searchUrl)
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok && data.results && data.results.length === 1) {
                            // Exact one match — safe to use it directly
                            currentFile = data.results[0];
                            document.getElementById('tabFile').textContent = shortName(currentFile);
                            isDirty = false;
                            addToRecentFiles(currentFile);
                            toast('Fisier gasit: ' + currentFile);
                            updatePreview(); // re-render via PHP cu <base> corect pentru CSS/imagini relative
                        } else if (data.ok && data.results && data.results.length > 1) {
                            // Multiple files with the same name exist on disk (e.g. Principal/ and
                            // Principal 2022/).  Picking results[0] alphabetically is WRONG — it
                            // would silently redirect the preview to a different design folder.
                            // Instead, compare the dragged file's content against each candidate
                            // and only auto-set currentFile when we find an exact content match.
                            const draggedContent = editor.getValue().replace(/\r\n/g, '\n');
                            Promise.all(
                                data.results.map(path =>
                                    fetch('?action=load&file=' + encodeURIComponent(path))
                                        .then(r => r.json())
                                        .catch(() => null)
                                )
                            ).then(loaded => {
                                let matched = null;
                                for (let i = 0; i < loaded.length; i++) {
                                    if (loaded[i] && loaded[i].ok &&
                                            (loaded[i].content || '').replace(/\r\n/g, '\n') === draggedContent) {
                                        matched = data.results[i];
                                        break;
                                    }
                                }
                                if (matched) {
                                    currentFile = matched;
                                    document.getElementById('tabFile').textContent = shortName(currentFile);
                                    isDirty = false;
                                    addToRecentFiles(currentFile);
                                    toast('Fisier gasit: ' + currentFile);
                                    updatePreview();
                                } else {
                                    // Cannot determine which copy — leave currentFile=null and
                                    // let the user open it manually from the tree.
                                    toast('Fisier incarcat: ' + file.name +
                                        ' (' + data.results.length + ' copii gasite — deschide din lista stanga calea corecta)');
                                }
                            });
                        } else {
                            toast('Fisier incarcat: ' + file.name + ' (cale necunoscuta)');
                        }
                    })
                    .catch(() => {
                        toast('Fisier incarcat: ' + file.name);
                    });
            };
            reader.onerror = function () { dropStatus('Eroare la citirea fisierului', '#ef4444'); };
            reader.readAsText(file, 'UTF-8');
        }

        window.addEventListener('load', () => {
            initEditor();
            refreshList('');
            renderRecentFiles();
            renderSidebarRecent();
            // Restore sidebar visibility from last session
            try {
                const sv = localStorage.getItem('htmlEditorSidebarVisible');
                if (sv === '0') {
                    sidebarVisible = false;
                    const sb = document.querySelector('.sidebar');
                    if (sb) sb.classList.add('collapsed');
                }
            } catch (e) {}
            document.addEventListener('dragover', e => e.preventDefault(), false);
            document.addEventListener('drop', e => e.preventDefault(), false);

            document.getElementById('pathInput').addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); openFromPath(); }
            });


            const dz = document.getElementById('dropZone');
            const fp = document.getElementById('filePicker');
            if (dz) {
                dz.addEventListener('click', () => { if (fp) fp.click(); });
                ['dragenter', 'dragover'].forEach(evt => dz.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); dz.classList.add('over'); }));
                dz.addEventListener('dragleave', e => { e.preventDefault(); e.stopPropagation(); dz.classList.remove('over'); });
                dz.addEventListener('drop', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    dz.classList.remove('over');
                    const f = e.dataTransfer.files && e.dataTransfer.files[0];
                    handleDropFile(f);
                });
            }
            if (fp) {
                fp.addEventListener('change', () => {
                    const f = fp.files && fp.files[0];
                    if (f) handleDropFile(f);
                    fp.value = '';
                });
            }

            // F12: open current file as a local file:// URL in a new browser tab.
            // NOTE: Chrome may block window.open('file://...') from an http:// page.
            // If the new tab does not open, copy the path shown in the toast and
            // paste it directly into the browser's address bar.
            document.addEventListener('keydown', e => {
                if (e.key === 'F12') {
                    if (!currentFile) return;
                    // Convert Windows path (e:\...) to file:/// URL
                    const localUrl = 'file:///' + currentFile.replace(/\\/g, '/').replace(/^\/+/, '');
                    const opened = window.open(localUrl, '_blank');
                    if (!opened) {
                        // Popup blocked — show path so user can open manually
                        toast('F12: copiaza calea in browser: ' + localUrl);
                    }
                    // Note: e.preventDefault() does NOT stop Chrome DevTools from
                    // opening (it's a browser-level shortcut), but the file tab
                    // will still open alongside DevTools.
                }
            });

            document.getElementById('propFont').addEventListener('change', () => applyFontProperty('fontFamily', document.getElementById('propFont').value));
            document.getElementById('propClass').addEventListener('change', () => applyClassProperty(document.getElementById('propClass').value));
            document.getElementById('propSize').addEventListener('change', () => applyFontProperty('fontSize', document.getElementById('propSize').value));
            document.getElementById('propBold').addEventListener('click', () => {
                toggleInlineFormat('bold');
            });
            document.getElementById('propItalic').addEventListener('click', () => {
                toggleInlineFormat('italic');
            });
            document.getElementById('propColor').addEventListener('input', () => applyFontProperty('color', document.getElementById('propColor').value));
            document.getElementById('propBg').addEventListener('input', () => applyFontProperty('backgroundColor', document.getElementById('propBg').value));
        });
    </script>
</body>

</html>