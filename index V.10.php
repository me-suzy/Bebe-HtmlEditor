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

// Read a file and guarantee UTF-8 output (no BOM).
// Handles: UTF-8 BOM, UTF-16 LE BOM, UTF-16 BE BOM, ISO-8859-1, Windows-1252.
// Also updates the in-content charset declaration so the editor and saved file stay consistent.
function read_file_as_utf8($path)
{
    $content = file_get_contents($path);
    if ($content === false)
        return false;

    // ── Detect and strip BOM signatures ──
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        // UTF-8 BOM: just strip the BOM, content is already UTF-8
        return substr($content, 3);
    }
    if (substr($content, 0, 4) === "\xFF\xFE\x00\x00") {
        // UTF-32 LE BOM
        $content = mb_convert_encoding(substr($content, 4), 'UTF-8', 'UTF-32LE');
        return preg_replace('/(\bcharset=)["\']?[a-zA-Z0-9_-]+["\']?/i', '${1}utf-8', $content, 1);
    }
    if (substr($content, 0, 4) === "\x00\x00\xFE\xFF") {
        // UTF-32 BE BOM
        $content = mb_convert_encoding(substr($content, 4), 'UTF-8', 'UTF-32BE');
        return preg_replace('/(\bcharset=)["\']?[a-zA-Z0-9_-]+["\']?/i', '${1}utf-8', $content, 1);
    }
    if (substr($content, 0, 2) === "\xFF\xFE") {
        // UTF-16 LE BOM
        $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        return preg_replace('/(\bcharset=)["\']?[a-zA-Z0-9_-]+["\']?/i', '${1}utf-8', $content, 1);
    }
    if (substr($content, 0, 2) === "\xFE\xFF") {
        // UTF-16 BE BOM
        $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        return preg_replace('/(\bcharset=)["\']?[a-zA-Z0-9_-]+["\']?/i', '${1}utf-8', $content, 1);
    }

    // ── No BOM: check if already valid UTF-8 ──
    if (mb_check_encoding($content, 'UTF-8'))
        return $content;

    // ── Not UTF-8: detect charset from HTML meta tag and convert ──
    $charset = 'ISO-8859-1'; // safe default
    if (preg_match('/<meta\b[^>]*\bhttp-equiv=["\']?Content-Type["\']?[^>]*\bcharset=([a-zA-Z0-9_-]+)/i', $content, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta\b[^>]*\bcharset=["\']?([a-zA-Z0-9_-]+)/i', $content, $m)) {
        $charset = $m[1];
    }
    $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
    if (!$converted)
        $converted = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    // Update charset declaration so saved file stays consistent
    return preg_replace('/(\bcharset=)["\']?[a-zA-Z0-9_-]+["\']?/i', '${1}utf-8', $converted, 1);
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
        $html = read_file_as_utf8($full);
        if ($html === false) {
            echo '<!DOCTYPE html><html><body>Eroare: nu se poate citi fisierul</body></html>';
            exit;
        }
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
            $cleanLinks = array_map(function ($tag) {
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
            if (trim($css))
                $allStyles .= "<style type=\"text/css\">\n" . $css . "\n</style>\n";
        }

        // <meta charset> and <meta viewport>
        $metaTags = '';
        preg_match_all('/<meta\b(?=[^>]*(?:charset|viewport))[^>]*>/i', $html, $metaM);
        if (!empty($metaM[0]))
            $metaTags = implode("\n", $metaM[0]) . "\n";

        // <title>
        $titleTag = '';
        if (preg_match('/<title\b[^>]*>[\s\S]*?<\/title>/i', $html, $titleM)) {
            $titleTag = $titleM[0] . "\n";
        }

        // ── STEP 3: Extract and sanitise body content ──
        // Find the real <body> tag by splitting into comment/non-comment sections,
        // so that a commented-out body tag (<!-- <body ...> -->) is never matched.
        $bodyTagEnd = false;
        $bodyOpenTag = '<body>';
        $htmlParts = preg_split('/(<!--(?:(?!-->)[\s\S])*-->)/U', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $cumOffset = 0;
        foreach ($htmlParts as $pi => $part) {
            if ($pi % 2 === 0 && $bodyTagEnd === false) { // non-comment section
                if (preg_match('/<body\b[^>]*>/i', $part, $bm, PREG_OFFSET_CAPTURE)) {
                    $bodyTagEnd = $cumOffset + $bm[0][1] + strlen($bm[0][0]);
                    // Strip on* from the real <body> opening tag
                    $bodyOpenTag = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $bm[0][0]);
                }
            }
            $cumOffset += strlen($part);
        }
        if ($bodyTagEnd !== false) {
            $bodyClosePos = strripos($html, '</body>');
            if ($bodyClosePos !== false && $bodyClosePos >= $bodyTagEnd) {
                $bodyInner = substr($html, $bodyTagEnd, $bodyClosePos - $bodyTagEnd);

                // Remove <style> from body (already collected into $allStyles above)
                $bodyInner = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $bodyInner);

                // Strip ALL on* event handler attributes from every element in body
                $bodyInner = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $bodyInner);

                // Neutralise javascript: URLs in href attributes
                $bodyInner = preg_replace('/\bhref\s*=\s*["\']?\s*javascript\s*:[^"\'>\s]*/i', 'href="#"', $bodyInner);

                // Normalise italic tags: păstrează mereu <em> / </em> în loc de <i> / </i>.
                // Astfel, când înlocuiești doar textul în interior, numele tag-ului rămâne consecvent.
                $bodyInner = preg_replace('/<\s*i(\b[^>]*)>/i', '<em$1>', $bodyInner);
                $bodyInner = preg_replace('/<\s*\/\s*i\s*>/i', '</em>', $bodyInner);

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
                    . "a img { cursor: pointer !important; }\n"
                    . "img { cursor: default !important; }\n"
                    . "a img { cursor: pointer !important; }\n"
                    . "#preloader, .preloader, #loader, .loader, #loader-fade,\n"
                    . ".loading-overlay, .page-loader, .loading-screen,\n"
                    . "#loading-overlay, #page-loading, .site-loader,\n"
                    . ".loader-container, .spinner, #spinner,\n"
                    . "[class*=\"preload\"], [id*=\"preload\"],\n"
                    . "[class*=\"page-load\"], [id*=\"page-load\"] {\n"
                    . "  display: none !important; }\n"
                    . "</style>\n";

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
            if ($line)
                $results[] = str_replace("\\", "/", $line);
            if (count($results) >= 5)
                break;
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
                    if (!$items)
                        continue;
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..')
                            continue;
                        $path = $dir . '/' . $item;
                        if (is_file($path) && strcasecmp($item, $name) === 0) {
                            $results[] = str_replace("\\", "/", $path);
                            if (count($results) >= 5)
                                break 3;
                        } elseif (is_dir($path)) {
                            $nextStack[] = $path;
                        }
                    }
                }
                $stack = $nextStack;
                $depth++;
                if ($depth > 8)
                    break;
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
                    if ($parent === $ancestor || $parent === '.' || strlen($parent) <= 3)
                        break;
                    $ancestor = $parent;
                }
                $ancestor = str_replace("\\", "/", $ancestor);
                // Skip if it's under $ROOT (already searched) or is a drive root
                if (stripos($ancestor, $rootNorm) === 0)
                    continue;
                if (strlen($ancestor) <= 3)
                    continue; // e.g. "D:/"
                $searchRoots[$ancestor] = true;
            }
            foreach (array_keys($searchRoots) as $sr) {
                $srWin = str_replace('/', '\\', $sr);
                $cmd = 'dir /s /b "' . $srWin . '\\' . $name . '" 2>nul';
                $lines = [];
                @exec($cmd, $lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!$line)
                        continue;
                    $norm = str_replace("\\", "/", $line);
                    if (!isset($existing[$norm])) {
                        $results[] = $norm;
                        $existing[$norm] = true;
                    }
                    if (count($results) >= 20)
                        break;
                }
                if (count($results) >= 20)
                    break;
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
        $txt = read_file_as_utf8($full);
        if ($txt === false) {
            echo json_encode(['ok' => false, 'error' => 'Nu se poate citi fisierul']);
            exit;
        }
        echo json_encode(['ok' => true, 'file' => $full, 'content' => $txt], JSON_UNESCAPED_UNICODE);
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

    // ── Web proxy: fetch external URLs server-side to bypass X-Frame-Options ──
    if ($action === 'webproxy') {
        $url = isset($_GET['url']) ? $_GET['url'] : '';
        if (!$url || !preg_match('#^https?://#i', $url)) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<h3>URL invalid</h3>';
            exit;
        }
        // Build the base URL for rewriting relative links
        $parsed = parse_url($url);
        $baseOrigin = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $basePath = isset($parsed['path']) ? dirname($parsed['path']) : '/';
        if ($basePath === '\\' || $basePath === '.') $basePath = '/';
        $baseHref = $baseOrigin . $basePath . '/';

        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                            "Accept-Language: ro,en;q=0.9\r\n",
                'follow_location' => true,
                'timeout' => 15
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $html = @file_get_contents($url, false, $ctx);
        if ($html === false) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body style="font-family:sans-serif;padding:40px;text-align:center"><h3>Nu s-a putut incarca pagina</h3><p>' . htmlspecialchars($url) . '</p></body></html>';
            exit;
        }
        // Inject <base href> so relative URLs resolve correctly
        $baseTag = '<base href="' . htmlspecialchars($baseHref) . '" target="_self">';
        if (preg_match('/<head(\s|>)/i', $html)) {
            $html = preg_replace('/<head(\s|>)/i', '<head$1' . $baseTag, $html, 1);
        } else {
            $html = $baseTag . $html;
        }
        // Rewrite links to go through proxy so navigation stays inside the iframe
        $selfUrl = strtok($_SERVER['REQUEST_URI'], '?');
        // Inject a tiny script that intercepts link clicks and form submits
        $proxyScript = '<script>
        document.addEventListener("click", function(e) {
            var a = e.target.closest("a[href]");
            if (!a) return;
            var href = a.href;
            if (!href || href.startsWith("javascript:") || href.startsWith("#")) return;
            e.preventDefault();
            window.location.href = "' . $selfUrl . '?action=webproxy&url=" + encodeURIComponent(href);
        }, true);
        document.addEventListener("submit", function(e) {
            var form = e.target;
            if (form.method && form.method.toLowerCase() === "post") return;
            e.preventDefault();
            var fd = new FormData(form);
            var params = new URLSearchParams(fd).toString();
            var action = form.action || window.location.href;
            var sep = action.includes("?") ? "&" : "?";
            window.location.href = "' . $selfUrl . '?action=webproxy&url=" + encodeURIComponent(action + sep + params);
        }, true);
        // Notify parent of URL changes
        try { window.parent.postMessage({type:"proxyUrlChange", url: "' . addslashes($url) . '"}, "*"); } catch(e){}
        </script>';
        // Insert before </body> or at end
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $proxyScript . '</body>', $html);
        } else {
            $html .= $proxyScript;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    // ── API: text translation via Google Translate public endpoint (no API key) ──
    if (isset($_GET['action']) && $_GET['action'] === 'translate') {
        header('Content-Type: application/json; charset=utf-8');
        $sl = isset($_GET['sl']) ? $_GET['sl'] : 'auto';
        $tl = isset($_GET['tl']) ? $_GET['tl'] : 'en';
        $q = isset($_GET['q']) ? $_GET['q'] : '';
        $q = trim($q);
        if ($q === '') {
            echo json_encode(['ok' => false, 'error' => 'Text gol.']);
            exit;
        }

        $translated = false;

        // ── Method 1: cURL with Google Translate (client=gtx) ──
        if (function_exists('curl_init')) {
            $apiUrl = 'https://translate.googleapis.com/translate_a/single?client=gtx&dt=t'
                . '&sl=' . urlencode($sl)
                . '&tl=' . urlencode($tl)
                . '&q=' . urlencode($q);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: */*',
                ],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $httpCode === 200) {
                $data = json_decode($resp, true);
                if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                    $translated = '';
                    foreach ($data[0] as $chunk) {
                        if (isset($chunk[0])) $translated .= $chunk[0];
                    }
                }
            }
        }

        // ── Method 2: fallback file_get_contents ──
        if ($translated === false) {
            $apiUrl = 'https://translate.googleapis.com/translate_a/single?client=gtx&dt=t'
                . '&sl=' . urlencode($sl)
                . '&tl=' . urlencode($tl)
                . '&q=' . urlencode($q);
            $ctx = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: */*\r\n",
                    'timeout' => 10
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $resp = @file_get_contents($apiUrl, false, $ctx);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                    $translated = '';
                    foreach ($data[0] as $chunk) {
                        if (isset($chunk[0])) $translated .= $chunk[0];
                    }
                }
            }
        }

        if ($translated !== false && $translated !== '') {
            echo json_encode(['ok' => true, 'text' => $translated]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Nu s-a putut traduce. Verifică conexiunea la internet.']);
        }
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
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
    <link rel="icon" type="image/png" sizes="256x256" href="favicon-256.png">
    <link rel="shortcut icon" href="favicon.ico">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#252633">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="HTML Editor">
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

        /* ---- Tab Bar ---- */
        #tabBar {
            display: flex;
            align-items: center;
            gap: 2px;
            flex: 1;
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: thin;
            padding: 2px 0;
        }

        #tabBar::-webkit-scrollbar {
            height: 4px
        }

        #tabBar::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 2px
        }

        .editor-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            background: #1e1f26;
            color: #9ca3af;
            border-radius: 6px 6px 0 0;
            border: 1px solid #333;
            border-bottom: none;
            font-size: 13px;
            cursor: pointer;
            white-space: nowrap;
            max-width: 180px;
            min-width: 60px;
            user-select: none;
            flex-shrink: 0;
            transition: background .15s, color .15s;
        }

        .editor-tab:hover {
            background: #2b2c3a;
            color: #e5e7eb
        }

        .editor-tab.active {
            background: #252633;
            color: #fff;
            border-color: #3b82f6;
            border-bottom: 1px solid #252633;
            font-weight: 600;
        }

        .editor-tab.dirty .tab-label::after {
            content: ' \2731';
            color: #facc15;
            font-size: 13px;
            vertical-align: middle;
            margin-left: 1px
        }

        .editor-tab.drag-over {
            border-left: 3px solid #3b82f6
        }

        .editor-tab.dragging {
            opacity: 0.4
        }

        .tab-label {
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px
        }

        .tab-close {
            font-size: 16px;
            line-height: 1;
            color: #6b7280;
            border-radius: 3px;
            padding: 0 3px;
            margin-left: 2px;
        }

        .tab-close:hover {
            background: #ef4444;
            color: #fff
        }

        .tab-new {
            font-size: 18px;
            font-weight: 700;
            color: #6b7280;
            padding: 5px 12px;
            border: 1px dashed #4b5563;
            background: transparent;
            min-width: auto;
        }

        .tab-new:hover {
            color: #3b82f6;
            border-color: #3b82f6
        }

        #tabBar.file-drag-over,
        #tabBarSpacer.file-drag-over {
            background: rgba(59, 130, 246, 0.15);
            border-radius: 6px;
        }

        #tabBar.file-drag-over .tab-new {
            color: #3b82f6;
            border-color: #3b82f6;
            border-style: solid;
            background: rgba(59, 130, 246, 0.2);
        }

        #tabBarSpacer.file-drag-over {
            position: relative;
        }

        #tabBarSpacer.file-drag-over::after {
            content: 'Drop fisier aici';
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            color: #3b82f6;
            font-size: 12px;
            white-space: nowrap;
            pointer-events: none;
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

        /* Find & Replace match highlights in code editor */
        .cm-find-highlight {
            background: rgba(255, 165, 0, 0.35) !important;
            border-bottom: 1px solid #f59e0b;
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

        /* Diacritics popup */
        .diac-popup {
            position: fixed;
            background: #252633;
            border: 1px solid #4b5563;
            border-radius: 8px;
            padding: 14px 18px;
            z-index: 3100;
            box-shadow: 0 8px 32px rgba(0,0,0,.5);
            display: none;
            min-width: 260px;
            color: #e5e7eb;
            font-size: 13px;
            max-height: calc(100vh - 80px);
            overflow: hidden;
        }

        /* Dimensiune minimă/implicită pentru panoul de traducere */
        #translatePopup {
            width: 520px;
            min-width: 520px;
            min-height: 260px;
        }
        .diac-popup.visible { display: block; }
        .diac-popup h4 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #93c5fd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .diac-popup .diac-close {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 18px;
            cursor: pointer;
            padding: 0 2px;
            line-height: 1;
        }
        .diac-popup .diac-close:hover { color: #fff; }
        .diac-popup.resizable {
            resize: both;
            overflow: auto;
        }
        .diac-popup .drag-handle {
            cursor: move;
        }
        .diac-popup table { width: 100%; border-collapse: collapse; }
        .diac-popup th {
            text-align: left;
            padding: 3px 6px;
            color: #9ca3af;
            font-weight: 500;
            font-size: 11px;
            border-bottom: 1px solid #374151;
        }
        .diac-popup td { padding: 4px 6px; }
        .diac-popup td:first-child {
            font-size: 18px;
            text-align: center;
            width: 36px;
        }
        .diac-popup td:last-child {
            color: #9ca3af;
            font-size: 12px;
            font-family: monospace;
        }
        .diac-popup tr { cursor: pointer; border-radius: 4px; }
        .diac-popup tr:hover td { background: #374151; }

        /* Find & Replace dialog */
        .find-replace-dialog {
            position: fixed;
            top: 60px;
            right: 30px;
            background: #252633;
            border: 1px solid #4b5563;
            border-radius: 8px;
            padding: 16px 20px;
            z-index: 3000;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .5);
            min-width: 420px;
            display: none;
            font-size: 14px;
        }

        .find-replace-dialog.visible {
            display: block;
        }

        .find-replace-dialog h4 {
            margin: 0 0 12px 0;
            color: #e5e7eb;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .find-replace-dialog .fr-close {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 20px;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }

        .find-replace-dialog .fr-close:hover {
            color: #fff;
        }

        .find-replace-dialog .fr-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .find-replace-dialog .fr-row label {
            color: #9ca3af;
            min-width: 70px;
            text-align: right;
        }

        .find-replace-dialog .fr-row input[type="text"] {
            flex: 1;
            background: #15161d;
            border: 1px solid #444;
            border-radius: 4px;
            padding: 6px 10px;
            color: #eee;
            font-size: 14px;
        }

        .find-replace-dialog .fr-row input[type="text"]:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .find-replace-dialog .fr-btns {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            justify-content: flex-end;
        }

        .find-replace-dialog .fr-btns button {
            padding: 6px 14px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
        }

        /* Generic error popup with selectable text */
        .error-popup {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 4000;
        }

        .error-popup.visible {
            display: flex;
        }

        .error-popup-inner {
            background: #111827;
            border: 1px solid #4b5563;
            border-radius: 8px;
            padding: 16px 18px;
            width: 520px;
            max-width: 95vw;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.7);
        }

        .error-popup-title {
            color: #f87171;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .error-popup-inner textarea {
            width: 100%;
            min-height: 90px;
            resize: vertical;
            background: #020617;
            border: 1px solid #4b5563;
            color: #e5e7eb;
            font-family: Consolas, monospace;
            font-size: 13px;
            padding: 8px;
            border-radius: 4px;
        }

        .error-popup-buttons {
            margin-top: 10px;
            text-align: right;
        }

        .error-popup-buttons button {
            background: #374151;
            color: #e5e7eb;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }

        .error-popup-buttons button:hover {
            background: #4b5563;
        }

        /* Reusable button styles (Find & Replace + Translate panel) */
        .fr-btn-primary {
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 6px 14px;
            font-size: 13px;
            cursor: pointer;
        }

        .fr-btn-primary:hover {
            background: #2563eb;
        }

        .fr-btn-ghost {
            background: #2b2c3a;
            color: #ccc;
            border: none;
            border-radius: 4px;
            padding: 6px 14px;
            font-size: 13px;
            cursor: pointer;
        }

        .fr-btn-ghost:hover {
            background: #3b3d4d;
        }

        .find-replace-dialog .fr-options {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 10px;
        }

        .find-replace-dialog .fr-options label {
            color: #9ca3af;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .find-replace-dialog .fr-info {
            color: #9ca3af;
            font-size: 13px;
            margin-top: 8px;
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

        .recent-item .recent-name-wrap {
            flex: 1;
            overflow: hidden;
            min-width: 0
        }

        .recent-item .recent-name {
            font-weight: 500;
            color: #eee;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block
        }

        .recent-item .recent-folder {
            font-size: 11px;
            color: #6b7280;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            margin-top: 1px
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>
    <!-- Save Confirm Dialog -->
    <div id="saveConfirmOverlay"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;align-items:center;justify-content:center"
        onkeydown="if(event.key==='Escape' && _saveConfirmResolve) _saveConfirmResolve('cancel');">
        <div
            style="background:#252633;border:1px solid #3b82f6;border-radius:10px;padding:24px 30px;max-width:420px;color:#eaeaea;font-size:15px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.5)">
            <div id="saveConfirmMsg" style="margin-bottom:20px;line-height:1.5"></div>
            <div style="display:flex;gap:10px;justify-content:center">
                <button onclick="_saveConfirmResolve('save')"
                    style="padding:8px 20px;background:#3b82f6;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px">Salveaza</button>
                <button onclick="_saveConfirmResolve('no')"
                    style="padding:8px 20px;background:#ef4444;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px">Nu</button>
                <button onclick="_saveConfirmResolve('cancel')"
                    style="padding:8px 20px;background:#4b5563;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px">Cancel</button>
            </div>
        </div>
    </div>
    <!-- Diacritics Popup -->
    <div class="diac-popup" id="diacPopup" onkeydown="if(event.key==='Escape') closeDiacPopup();" tabindex="-1">
        <h4 class="drag-handle">Diacritice <button class="diac-close" onclick="closeDiacPopup()" title="Inchide">&times;</button></h4>
        <table>
            <tr><th>Char</th><th>Descriere</th><th>Shortcut</th></tr>
            <tr onclick="insertDiac('ă')"><td>ă</td><td>a cu breve</td><td>Ctrl + A</td></tr>
            <tr onclick="insertDiac('â')"><td>â</td><td>a cu circumflex</td><td>Alt + A</td></tr>
            <tr onclick="insertDiac('î')"><td>î</td><td>i cu circumflex</td><td>Ctrl + I</td></tr>
            <tr onclick="insertDiac('Î')"><td>Î</td><td>I cu circumflex (mare)</td><td>Alt + I</td></tr>
            <tr onclick="insertDiac('ș')"><td>ș</td><td>s cu virgulă</td><td>Ctrl + Shift + S</td></tr>
            <tr onclick="insertDiac('Ș')"><td>Ș</td><td>S cu virgulă (mare)</td><td>Alt + S</td></tr>
            <tr onclick="insertDiac('ț')"><td>ț</td><td>t cu virgulă</td><td>Alt + T</td></tr>
            <tr onclick="insertDiac('Ț')"><td>Ț</td><td>T cu virgulă (mare)</td><td>Alt + Shift + T</td></tr>
        </table>
    </div>
    <!-- Find & Replace Dialog -->
    <div class="find-replace-dialog" id="findReplaceDialog" onkeydown="if(event.key==='Escape') closeFindReplace();">
        <h4>Find & Replace <button class="fr-close" onclick="closeFindReplace()" title="Close">&times;</button></h4>
        <div class="fr-row">
            <label>Find:</label>
            <input type="text" id="frFindInput" placeholder="Search text..."
                oninput="_frLastQuery='__invalidated__';"
                onkeydown="if(event.key==='Escape'){closeFindReplace();} else if(event.key==='Enter'){event.preventDefault();findNext();} else if(event.key==='F3'){event.preventDefault();event.shiftKey?findPrev():findNext();}">
        </div>
        <div class="fr-row">
            <label>Replace:</label>
            <input type="text" id="frReplaceInput" placeholder="Replace with..."
                onkeydown="if(event.key==='Escape'){closeFindReplace();} else if(event.key==='Enter'){event.preventDefault();replaceCurrent();}">
        </div>
        <div class="fr-options">
            <label><input type="checkbox" id="frCaseSensitive" onchange="_frLastQuery='__invalidated__';findNext();"> Case sensitive</label>
            <label><input type="checkbox" id="frWholeWord" onchange="_frLastQuery='__invalidated__';findNext();"> Whole word</label>
        </div>
        <div class="fr-btns">
            <button class="fr-btn-ghost" onclick="findNext()">Find Next</button>
            <button class="fr-btn-ghost" onclick="findPrev()">Find Prev</button>
            <button class="fr-btn-primary" onclick="replaceCurrent()">Replace</button>
            <button class="fr-btn-primary" onclick="replaceAll()">Replace All</button>
        </div>
        <div class="fr-info" id="frInfo"></div>
    </div>

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
    <!-- Translation Panel -->
    <div class="diac-popup" id="translatePopup" onkeydown="if(event.key==='Escape') closeTranslatePopup();" tabindex="-1" style="max-width:520px">
        <h4 class="drag-handle">Traducere <button class="diac-close" onclick="closeTranslatePopup()" title="Inchide">&times;</button></h4>
        <div style="display:flex;gap:10px;margin-bottom:8px">
            <div style="flex:1">
                <label style="font-size:11px;color:#9ca3af">Din limba</label>
                <select id="trSourceLang" style="width:100%;padding:4px 6px;border-radius:4px;border:1px solid #4b5563;background:#1f2937;color:#e5e7eb;font-size:12px">
                    <option value="auto">Detectează automat</option>
                    <option value="ro" selected>Română</option>
                    <option value="en">Engleză</option>
                </select>
            </div>
            <div style="flex:1">
                <label style="font-size:11px;color:#9ca3af">În limba</label>
                <select id="trTargetLang" style="width:100%;padding:4px 6px;border-radius:4px;border:1px solid #4b5563;background:#1f2937;color:#e5e7eb;font-size:12px">
                    <option value="en" selected>Engleză</option>
                    <option value="ro">Română</option>
                    <option value="fr">Franceză</option>
                    <option value="de">Germană</option>
                    <option value="es">Spaniolă</option>
                    <option value="it">Italiană</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:10px">
            <div style="flex:1;display:flex;flex-direction:column">
                <label style="font-size:11px;color:#9ca3af">Text sursă</label>
                <textarea id="trSourceText" rows="6" style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #4b5563;background:#111827;color:#e5e7eb;font-size:13px;resize:vertical"></textarea>
            </div>
            <div style="flex:1;display:flex;flex-direction:column">
                <label style="font-size:11px;color:#9ca3af">Text tradus</label>
                <textarea id="trResultText" rows="6" readonly style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #4b5563;background:#020617;color:#e5e7eb;font-size:13px;resize:vertical"></textarea>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
            <div style="font-size:11px;color:#9ca3af" id="trStatus"></div>
            <div style="display:flex;gap:8px">
                <button class="fr-btn-ghost" onclick="loadSelectionIntoTranslate()" title="Preia selecția curentă">Preia selecția</button>
                <button class="fr-btn-primary" onclick="applyTranslatedToSelection()" title="Înlocuiește selecția cu traducerea">Înlocuiește în document</button>
                <button class="fr-btn-primary" onclick="runTranslate()">Tradu</button>
            </div>
        </div>
    </div>
    <div class="topbar">
        <button class="btn btn-ghost" id="btnToggleSidebar" onclick="toggleSidebar()"
            title="Arata/Ascunde lista de fisiere (fisiere HTML)">☰</button>
        <div class="brand">Mini Dreamweaver</div>
        <div id="tabBar">
            <div class="editor-tab tab-new" id="tabNew" onclick="onNewTabClick()" title="Deschide fisier nou">+</div>
        </div>
        <div id="tabBarSpacer" style="flex:1"></div>
        <button class="btn btn-ghost" onclick="closeActiveTab()">Inchide tab</button>
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
                    <button type="button" id="btnClassObisnuit2" class="btn btn-ghost"
                        title="Aplică clasa text_obisnuit2 pe selecția din Design">B</button>
                </div>
                <div class="viewmode-tabs" style="margin-left:12px">
                    <button type="button" id="btnBrowserTab" onclick="toggleBrowserPanel()"
                        title="Deschide Google Translate" style="font-weight:600">Google</button>
                    <button type="button" id="btnTranslatePanel" onclick="toggleTranslatePopup()"
                        title="Traducere text selectat" style="margin-left:6px">Tradu</button>
                </div>
                <div class="viewmode-tabs" style="margin-left:20px">
                    <button type="button" id="btnSelectSasa" onclick="selectSasaRegion()"
                        title="Select between SASA-1 and SASA-2">Select</button>
                    <button type="button" id="btnCropSasa" onclick="cropSasaRegion()"
                        title="Highlight paragraphs between SASA-1 and SASA-2 (visual only)">Crop</button>
                    <button type="button" id="btnFind" onclick="toggleFindReplace()"
                        title="Find & Replace (Ctrl+H)">Find</button>
                    <button type="button" id="btnDiac" onclick="toggleDiacPopup()"
                        title="Diacritice romanesti" style="font-size:15px;padding:4px 7px;line-height:1">&#258;</button>
                </div>
                <div class="tabs" style="min-width:0">
                    <div class="status" id="status" style="position:absolute;right:12px;pointer-events:none;white-space:nowrap;opacity:0.7"></div>
                </div>
            </div>
            <div class="split mode-design" id="splitContainer">
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
            <!-- Browser Panel (Google Translate / web browsing) -->
            <div id="browserPanel" style="display:none;flex:1;flex-direction:column;background:#1a1b26;overflow:hidden">
                <div style="display:flex;align-items:center;gap:6px;padding:6px 10px;background:#20212c;border-bottom:1px solid #333">
                    <button onclick="browserGoBack()" title="Inapoi" style="background:none;border:1px solid #4b5563;border-radius:4px;color:#e5e7eb;cursor:pointer;padding:3px 8px;font-size:14px">&#9664;</button>
                    <button onclick="browserGoForward()" title="Inainte" style="background:none;border:1px solid #4b5563;border-radius:4px;color:#e5e7eb;cursor:pointer;padding:3px 8px;font-size:14px">&#9654;</button>
                    <button onclick="browserRefresh()" title="Reincarca" style="background:none;border:1px solid #4b5563;border-radius:4px;color:#e5e7eb;cursor:pointer;padding:3px 8px;font-size:14px">&#8635;</button>
                    <input type="text" id="browserUrlBar" placeholder="URL..."
                        style="flex:1;padding:5px 10px;border:1px solid #4b5563;border-radius:4px;background:#1e1f26;color:#e5e7eb;font-size:13px;outline:none"
                        onkeydown="if(event.key==='Enter'){event.preventDefault();browserNavigate();}">
                    <button onclick="browserNavigate()" title="Navigheaza" style="background:#3b82f6;border:none;border-radius:4px;color:#fff;cursor:pointer;padding:5px 12px;font-size:13px;font-weight:600">Go</button>
                    <button onclick="if(activeTabId) closeTab(activeTabId);" title="Inchide tab browser" style="background:none;border:1px solid #ef4444;border-radius:4px;color:#ef4444;cursor:pointer;padding:3px 8px;font-size:14px;font-weight:bold">&times;</button>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;background:#1a1b26">
                    <iframe id="browserFrame"
                        style="flex:1;width:100%;border:none;background:#050816;color:#e5e7eb"></iframe>
                </div>
            </div>
            <div id="propertiesPanel" class="properties-panel">
                <label>Link:</label><input type="text" id="propLink" placeholder="URL" title="Link href" style="width:140px;padding:2px 5px;border:1px solid #4b5563;border-radius:3px;background:#1e1f26;color:#e5e7eb;font-size:12px">
                <select id="propLinkTarget" title="Link target" style="padding:2px 3px;border:1px solid #4b5563;border-radius:3px;background:#1e1f26;color:#e5e7eb;font-size:12px">
                    <option value="">Target</option>
                    <option value="_blank">_blank</option>
                    <option value="_new">_new</option>
                    <option value="_parent">_parent</option>
                    <option value="_self">_self</option>
                    <option value="_top">_top</option>
                </select>
                <span style="color:#4b5563;margin:0 4px">|</span>
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
        let _imgClickMark = null;       // persistent markText for image/icon click highlight
        let isDirty = false;
        let viewMode = 'design';
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
        let designCleanCode = null; // exact editor code when design undo stack was initialized
        let designCleanBodyHtml = null; // initial browser-serialized body innerHTML for dirty comparison

        // ===== MULTI-TAB SYSTEM =====
        let tabs = [];
        let activeTabId = null;
        let tabIdCounter = 0;
        let _isRestoringTab = false;

        function createTabState(opts) {
            opts = opts || {};
            tabIdCounter++;
            var _oc = (opts.originalContent || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            return {
                id: tabIdCounter,
                type: opts.type || 'file',       // 'file' or 'browser'
                browserUrl: opts.browserUrl || null,
                browserWindowRef: null,           // window.open() reference
                filePath: opts.filePath || null,
                fileName: opts.fileName || '',
                tabLabel: opts.tabLabel || 'Nou',
                fullTitle: opts.fullTitle || '',
                editorContent: opts.editorContent || '',
                originalContent: _oc,
                originalContentNorm: normalizeHtmlForCompare(_oc),
                cursorPos: opts.cursorPos || { line: 0, ch: 0 },
                scrollInfo: opts.scrollInfo || { left: 0, top: 0 },
                undoHistory: opts.undoHistory || null,
                isDirty: false,
                viewMode: opts.viewMode || 'design',
                lastPreviewHadBody: opts.lastPreviewHadBody || false,
                designUndoStack: [],
                designRedoStack: [],
                lastDesignSnapshot: null,
                designCleanBodyHtml: null
            };
        }

        function escapeHtml(str) {
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Normalize HTML for dirty comparison. Browsers serialize innerHTML
        // slightly differently from the original source (e.g. <br/> → <br>,
        // <img .../> → <img ...>). This function normalizes those differences
        // so that undo-back-to-original correctly detects "not dirty".
        function normalizeHtmlForCompare(html) {
            return html
                // Self-closing void elements: <br/> → <br>, <img .../> → <img ...>
                .replace(/<(br|hr|img|input|meta|link|col|area|source|track|wbr|embed|param)((?:\s[^>]*?)?)(?:\s*\/)>/gi, '<$1$2>')
                // Trim trailing whitespace before > in void elements
                .replace(/<(br|hr|img|input|meta|link|col|area|source|track|wbr|embed|param)((?:\s[^>]*?)?)\s+>/gi, '<$1$2>');
        }

        function getTabFullTitle(content, fileName) {
            var m = /<title[^>]*>([\s\S]*?)<\/title>/i.exec(content);
            if (m && m[1].trim()) return m[1].trim().replace(/\s+/g, ' ');
            if (fileName) return fileName.replace(/^.*[\\\/]/, '');
            return 'Nou';
        }

        function getTabLabel(content, fileName) {
            var full = getTabFullTitle(content, fileName);
            var words = full.split(' ');
            var label = words.slice(0, 2).join(' ');
            if (label.length > 18) label = label.substring(0, 17) + '\u2026';
            return label || 'Nou';
        }

        function findTabByPath(path) {
            if (!path) return null;
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].filePath === path) return tabs[i];
            }
            return null;
        }

        var _dragTabId = null;

        function renderTabs() {
            var bar = document.getElementById('tabBar');
            if (!bar) return;
            var existing = bar.querySelectorAll('.editor-tab:not(.tab-new)');
            for (var i = 0; i < existing.length; i++) existing[i].remove();
            var newBtn = document.getElementById('tabNew');
            tabs.forEach(function (tab) {
                var div = document.createElement('div');
                div.className = 'editor-tab' + (tab.id === activeTabId ? ' active' : '') + (tab.isDirty ? ' dirty' : '');
                div.dataset.tabId = tab.id;
                div.title = tab.fullTitle || tab.tabLabel;
                div.draggable = true;
                var labelPrefix = tab.type === 'browser' ? '🌐 ' : '';
                div.innerHTML = '<span class="tab-label">' + labelPrefix + escapeHtml(tab.tabLabel) + '</span>'
                    + '<span class="tab-close" title="Inchide tab">&times;</span>';
                (function (tid) {
                    div.addEventListener('click', function (e) {
                        if (e.target.classList.contains('tab-close')) return;
                        switchToTab(tid);
                    });
                    div.querySelector('.tab-close').addEventListener('click', function (e) {
                        e.stopPropagation();
                        closeTab(tid);
                    });
                    // Drag & drop reordering
                    div.addEventListener('dragstart', function (e) {
                        _dragTabId = tid;
                        div.classList.add('dragging');
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    div.addEventListener('dragend', function () {
                        _dragTabId = null;
                        div.classList.remove('dragging');
                        bar.querySelectorAll('.editor-tab').forEach(function (el) { el.classList.remove('drag-over'); });
                    });
                    div.addEventListener('dragover', function (e) {
                        if (_dragTabId === null || _dragTabId === tid) return;
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        bar.querySelectorAll('.editor-tab').forEach(function (el) { el.classList.remove('drag-over'); });
                        div.classList.add('drag-over');
                    });
                    div.addEventListener('dragleave', function () {
                        div.classList.remove('drag-over');
                    });
                    div.addEventListener('drop', function (e) {
                        e.preventDefault();
                        div.classList.remove('drag-over');
                        if (_dragTabId === null || _dragTabId === tid) return;
                        var fromIdx = -1, toIdx = -1;
                        for (var j = 0; j < tabs.length; j++) {
                            if (tabs[j].id === _dragTabId) fromIdx = j;
                            if (tabs[j].id === tid) toIdx = j;
                        }
                        if (fromIdx < 0 || toIdx < 0) return;
                        var moved = tabs.splice(fromIdx, 1)[0];
                        tabs.splice(toIdx, 0, moved);
                        _dragTabId = null;
                        renderTabs();
                        backupDirtyTabs();
                    });
                })(tab.id);
                bar.insertBefore(div, newBtn);
            });
            // Scroll active tab into view
            var activeEl = bar.querySelector('.editor-tab.active');
            if (activeEl) activeEl.scrollIntoView({ behavior: 'smooth', inline: 'nearest', block: 'nearest' });
        }

        function saveCurrentTabState() {
            if (!activeTabId) return;
            var tab = null;
            for (var i = 0; i < tabs.length; i++) { if (tabs[i].id === activeTabId) { tab = tabs[i]; break; } }
            if (!tab || !editor) return;
            // Browser tabs don't have CodeMirror state to save
            if (tab.type === 'browser') return;
            tab.editorContent = editor.getValue();
            tab.cursorPos = editor.getCursor();
            tab.scrollInfo = editor.getScrollInfo();
            tab.undoHistory = editor.getDoc().getHistory();
            tab.isDirty = isDirty;
            tab.viewMode = viewMode;
            tab.lastPreviewHadBody = lastPreviewHadBody;
            tab.designUndoStack = designUndoStack.slice();
            tab.designRedoStack = designRedoStack.slice();
            tab.lastDesignSnapshot = lastDesignSnapshot;
            tab.designCleanBodyHtml = designCleanBodyHtml;
            // Save preview iframe scroll position (for Design/Split mode)
            try {
                var iframe = document.getElementById('preview');
                if (iframe && iframe.contentWindow) {
                    tab.previewScroll = {
                        x: iframe.contentWindow.scrollX || 0,
                        y: iframe.contentWindow.scrollY || 0
                    };
                }
            } catch(e) { /* cross-origin iframe, ignore */ }
        }

        function restoreTabState(tab) {
            if (typeof deactivateSasa === 'function') deactivateSasa();
            if (typeof deactivateCrop === 'function' && cropHighlightActive) deactivateCrop();
            _isRestoringTab = true;
            currentFile = tab.filePath;
            isDirty = tab.isDirty;
            lastPreviewHadBody = tab.lastPreviewHadBody;
            designUndoStack = tab.designUndoStack.slice();
            designRedoStack = tab.designRedoStack.slice();
            lastDesignSnapshot = tab.lastDesignSnapshot;
            designCleanBodyHtml = tab.designCleanBodyHtml;

            // Suppress change-triggered preview updates during restore
            isSyncFromDesign = true;
            editor.setValue(tab.editorContent);
            if (tab.undoHistory) {
                editor.getDoc().setHistory(tab.undoHistory);
            } else {
                editor.getDoc().clearHistory();
            }
            editor.setCursor(tab.cursorPos);
            isSyncFromDesign = false;
            _isRestoringTab = false;

            // Restore view mode
            setViewMode(tab.viewMode);

            // Reload preview (restore iframe scroll via _pendingPreviewScroll)
            clearTimeout(previewDebounceTimer);
            previewDebounceTimer = null;
            if (tab.previewScroll) {
                _pendingPreviewScroll = { x: tab.previewScroll.x, y: tab.previewScroll.y };
            }
            updatePreview();

            // Restore scroll AFTER everything has settled
            // (setValue + setViewMode + updatePreview all cause re-layout that resets scroll)
            var savedScroll = tab.scrollInfo;
            if (savedScroll) {
                setTimeout(function() {
                    editor.refresh();
                    editor.scrollTo(savedScroll.left, savedScroll.top);
                    // Double-check: sometimes first scrollTo is eaten by pending layout
                    requestAnimationFrame(function() {
                        editor.scrollTo(savedScroll.left, savedScroll.top);
                    });
                }, 50);
            }
        }

        function switchToTab(tabId) {
            if (tabId === activeTabId) return;
            if (typeof flushPendingDesignSync === 'function') flushPendingDesignSync();
            // Deactivate CROP and SELECT modes when switching tabs
            if (typeof deactivateSasa === 'function') deactivateSasa();
            if (typeof deactivateCrop === 'function' && typeof cropHighlightActive !== 'undefined' && cropHighlightActive) deactivateCrop();
            saveCurrentTabState();
            activeTabId = tabId;
            var tab = null;
            for (var i = 0; i < tabs.length; i++) { if (tabs[i].id === tabId) { tab = tabs[i]; break; } }
            if (!tab) return;

            // Show/hide browser panel vs editor based on tab type
            var splitContainer = document.getElementById('splitContainer');
            var browserPanel = document.getElementById('browserPanel');
            var propsPanel = document.getElementById('propertiesPanel');
            var btnGoogle = document.getElementById('btnBrowserTab');

            if (tab.type === 'browser') {
                splitContainer.style.display = 'none';
                if (propsPanel) propsPanel.style.display = 'none';
                browserPanel.style.display = 'flex';
                browserPanelOpen = true;
                if (btnGoogle) btnGoogle.classList.add('active');
                // Focus/reopen the browser window if needed
                if (tab.browserWindowRef && !tab.browserWindowRef.closed) {
                    tab.browserWindowRef.focus();
                }
                // Update URL bar
                var urlBar = document.getElementById('browserUrlBar');
                if (urlBar && tab.browserUrl) urlBar.value = tab.browserUrl;
            } else {
                browserPanel.style.display = 'none';
                splitContainer.style.display = '';
                browserPanelOpen = false;
                if (btnGoogle) btnGoogle.classList.remove('active');
                restoreTabState(tab);
            }
            renderTabs();
        }

        var _saveConfirmResolve = null;
        function showSaveConfirm(msg) {
            return new Promise(function (resolve) {
                document.getElementById('saveConfirmMsg').textContent = msg;
                var ov = document.getElementById('saveConfirmOverlay');
                ov.style.display = 'flex';
                _saveConfirmResolve = function (choice) {
                    ov.style.display = 'none';
                    _saveConfirmResolve = null;
                    resolve(choice);
                };
            });
        }

        async function closeTab(tabId) {
            var tab = null, idx = -1;
            for (var i = 0; i < tabs.length; i++) { if (tabs[i].id === tabId) { tab = tabs[i]; idx = i; break; } }
            if (!tab) return;

            // Browser tabs: close popup window, no save prompt needed
            if (tab.type === 'browser') {
                if (tab.browserWindowRef && !tab.browserWindowRef.closed) {
                    try { tab.browserWindowRef.close(); } catch (e) { }
                }
                tabs.splice(idx, 1);
                var btnGoogle = document.getElementById('btnBrowserTab');
                if (btnGoogle) btnGoogle.classList.remove('active');
                // If this was the active tab, switch to another
                if (tabId === activeTabId) {
                    var browserPanel = document.getElementById('browserPanel');
                    var splitContainer = document.getElementById('splitContainer');
                    browserPanel.style.display = 'none';
                    splitContainer.style.display = '';
                    browserPanelOpen = false;
                    if (tabs.length > 0) {
                        var newIdx = Math.min(idx, tabs.length - 1);
                        activeTabId = tabs[newIdx].id;
                        restoreTabState(tabs[newIdx]);
                    } else {
                        activeTabId = null;
                        currentFile = null;
                        isDirty = false;
                        editor.setValue('');
                        editor.getDoc().clearHistory();
                        document.getElementById('preview').src = 'about:blank';
                        showOverlay();
                    }
                }
                renderTabs();
                return;
            }

            var tabDirty = (tabId === activeTabId) ? isDirty : tab.isDirty;
            if (tabDirty) {
                var choice = await showSaveConfirm('Fisierul "' + tab.tabLabel + '" are modificari nesalvate. Salvezi inainte de inchidere?');
                if (choice === 'cancel') return;
                if (choice === 'save') {
                    if (tabId !== activeTabId) switchToTab(tabId);
                    await saveFile();
                }
            }

            removeTabBackup(tabId);
            // Re-find index (might have changed if switchToTab was called)
            idx = -1;
            for (var i = 0; i < tabs.length; i++) { if (tabs[i].id === tabId) { idx = i; break; } }
            if (idx < 0) return;
            tabs.splice(idx, 1);

            if (tabs.length === 0) {
                activeTabId = null;
                currentFile = null;
                isDirty = false;
                editor.setValue('');
                editor.getDoc().clearHistory();
                document.getElementById('preview').src = 'about:blank';
                designUndoStack = [];
                designRedoStack = [];
                lastDesignSnapshot = null;
                if (typeof deactivateSasa === 'function') deactivateSasa();
                if (typeof deactivateCrop === 'function' && cropHighlightActive) deactivateCrop();
                removeAllBackups();
                showOverlay();
                renderTabs();
                return;
            }

            if (tabId === activeTabId) {
                var newIdx = Math.min(idx, tabs.length - 1);
                activeTabId = tabs[newIdx].id;
                restoreTabState(tabs[newIdx]);
            }
            backupDirtyTabs();
            renderTabs();
        }

        function closeActiveTab() {
            if (activeTabId) {
                closeTab(activeTabId);
            } else {
                if (typeof deactivateSasa === 'function') deactivateSasa();
                if (typeof deactivateCrop === 'function' && cropHighlightActive) deactivateCrop();
                hideOverlay(); // just to be safe, though showOverlay is more likely
                showOverlay();
            }
        }

        function onNewTabClick() {
            saveCurrentTabState();
            if (typeof deactivateSasa === 'function') deactivateSasa();
            if (typeof deactivateCrop === 'function' && cropHighlightActive) deactivateCrop();
            showOverlay();
        }

        // ===== BACKUP SYSTEM =====
        var _backupTimer = null;

        function backupDirtyTabs() {
            // Debounce — save at most every 2 seconds
            clearTimeout(_backupTimer);
            _backupTimer = setTimeout(function () {
                try {
                    saveCurrentTabState();
                    var dirtyTabs = [];
                    for (var i = 0; i < tabs.length; i++) {
                        if (tabs[i].isDirty && tabs[i].type !== 'browser') {
                            dirtyTabs.push({
                                id: tabs[i].id,
                                filePath: tabs[i].filePath,
                                fileName: tabs[i].fileName,
                                tabLabel: tabs[i].tabLabel,
                                fullTitle: tabs[i].fullTitle,
                                editorContent: tabs[i].editorContent,
                                originalContent: tabs[i].originalContent,
                                viewMode: tabs[i].viewMode
                            });
                        }
                    }
                    if (dirtyTabs.length > 0) {
                        localStorage.setItem('htmlEditorBackupTabs', JSON.stringify(dirtyTabs));
                    } else {
                        localStorage.removeItem('htmlEditorBackupTabs');
                    }
                } catch (e) { }
            }, 2000);
        }

        function removeTabBackup(tabId) {
            try {
                var raw = localStorage.getItem('htmlEditorBackupTabs');
                if (!raw) return;
                var arr = JSON.parse(raw);
                arr = arr.filter(function (b) { return b.id !== tabId; });
                if (arr.length > 0) {
                    localStorage.setItem('htmlEditorBackupTabs', JSON.stringify(arr));
                } else {
                    localStorage.removeItem('htmlEditorBackupTabs');
                }
            } catch (e) { }
        }

        function removeAllBackups() {
            try { localStorage.removeItem('htmlEditorBackupTabs'); } catch (e) { }
        }

        function restoreBackupTabs() {
            try {
                var raw = localStorage.getItem('htmlEditorBackupTabs');
                if (!raw) return;
                var arr = JSON.parse(raw);
                if (!arr || !arr.length) return;
                var restored = 0;
                for (var i = 0; i < arr.length; i++) {
                    var b = arr[i];
                    // Skip if already open
                    if (b.filePath && findTabByPath(b.filePath)) continue;
                    var tab = createTabState({
                        filePath: b.filePath,
                        fileName: b.fileName,
                        tabLabel: b.tabLabel,
                        fullTitle: b.fullTitle,
                        editorContent: b.editorContent,
                        originalContent: b.originalContent,
                        viewMode: b.viewMode
                    });
                    // Only mark dirty if content actually differs from original
                    var isActuallyDirty = (normalizeHtmlForCompare((b.editorContent || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n')) !== tab.originalContentNorm);
                    tab.isDirty = isActuallyDirty;
                    tabs.push(tab);
                    restored++;
                }
                if (restored > 0) {
                    // Remove tabs that turned out to be clean (content matches original)
                    var actuallyDirty = tabs.filter(function (t) { return t.isDirty; }).length;
                    activeTabId = tabs[0].id;
                    restoreTabState(tabs[0]);
                    renderTabs();
                    hideOverlay();
                    // (backup restored silently — dirty star on tab is enough indication)
                    // Clean up backup for tabs that are no longer dirty
                    backupDirtyTabs();
                }
            } catch (e) { }
        }
        // ===== END BACKUP SYSTEM =====

        // ===== END MULTI-TAB SYSTEM =====

        function getDesignBodyHtml() {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body) return null;
            // Strip CROP highlight styles before reading so snapshots are always clean
            if (cropHighlightActive) {
                clearSasaDesignHighlight(doc);
                const html = doc.body.innerHTML;
                highlightSasaInDesign('crop');
                return html;
            }
            return doc.body.innerHTML;
        }

        // Push current body state into the undo stack.
        // Call this BEFORE making a change, or periodically (every 500ms) to
        // capture incremental typing so undo reverts ~500ms of keystrokes at a time.
        // Helper: get current Design iframe scroll position
        function getDesignScrollY() {
            try {
                const iframe = document.getElementById('preview');
                return iframe && iframe.contentWindow ? (iframe.contentWindow.scrollY || 0) : 0;
            } catch (e) { return 0; }
        }

        // After undo/redo, sync the code editor scroll to match the
        // Design panel scroll position (proportional mapping).
        function syncCodeScrollToDesignScroll(restoreScrollY) {
            if (!editor) return;
            try {
                const iframe = document.getElementById('preview');
                const doc = iframe && iframe.contentDocument;
                if (!doc || !doc.documentElement) return;
                const totalHeight = doc.documentElement.scrollHeight || 1;
                const pct = restoreScrollY / totalHeight;
                const codeInfo = editor.getScrollInfo();
                const codeScrollY = Math.round(pct * codeInfo.height);
                editor.scrollTo(null, codeScrollY);
            } catch (e) { /* ignore */ }
        }

        function designSaveSnapshot(fromTimer) {
            const html = getDesignBodyHtml();
            if (html === null) return;
            if (lastDesignSnapshot === null) {
                lastDesignSnapshot = html;
                return;
            }
            if (html === lastDesignSnapshot) return; // nothing changed
            designUndoStack.push({ html: lastDesignSnapshot, scrollY: getDesignScrollY() });
            if (designUndoStack.length > 1000) designUndoStack.shift();
            lastDesignSnapshot = html;
            // Only clear the redo future for real user edits, NOT for
            // periodic timer snapshots (which just chunk continuous typing
            // into undo steps — they shouldn't destroy the redo history).
            if (!fromTimer) {
                designRedoStack = [];
            }
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
            if (designUndoStack.length > 0 && designUndoStack[designUndoStack.length - 1].html === html) return;
            designUndoStack.push({ html: html, scrollY: getDesignScrollY() });
            if (designUndoStack.length > 1000) designUndoStack.shift();
            designRedoStack = [];
        }

        // Reset stacks (called when the preview iframe reloads / new file is opened)
        function designResetUndoStack() {
            designUndoStack = [];
            designRedoStack = [];
            lastDesignSnapshot = getDesignBodyHtml();
            designCleanBodyHtml = lastDesignSnapshot; // save initial body for dirty comparison
            designCleanCode = editor ? editor.getValue() : null;
            if (designSnapshotTimer) clearInterval(designSnapshotTimer);
            // Periodically save snapshots while the user types in design, so each
            // undo step covers ~500ms of keystroke activity.
            designSnapshotTimer = setInterval(() => {
                if (!isApplyingUndoRedo && !isSyncFromCode && !sasaSelectionActive && !cropHighlightActive) {
                    designSaveSnapshot(true);
                }
            }, 500);
        }

        function toggleSidebar() {
            sidebarVisible = !sidebarVisible;
            const sb = document.querySelector('.sidebar');
            if (sb) sb.classList.toggle('collapsed', !sidebarVisible);
            try { localStorage.setItem('htmlEditorSidebarVisible', sidebarVisible ? '1' : '0'); } catch (e) { }
        }

        function toast(msg) {
            const st = document.getElementById('status');
            st.textContent = msg;
            setTimeout(() => { st.textContent = 'Pregatit'; }, 4000);
        }

        function showErrorPopup(msg) {
            let dlg = document.getElementById('errorPopup');
            if (!dlg) {
                dlg = document.createElement('div');
                dlg.id = 'errorPopup';
                dlg.className = 'error-popup';
                dlg.innerHTML =
                    '<div class="error-popup-inner">' +
                    '<div class="error-popup-title">Eroare</div>' +
                    '<textarea id="errorPopupText" readonly></textarea>' +
                    '<div class="error-popup-buttons">' +
                    '<button type="button" id="errorPopupClose">Închide</button>' +
                    '</div>' +
                    '</div>';
                document.body.appendChild(dlg);
                const closeBtn = document.getElementById('errorPopupClose');
                if (closeBtn) {
                    closeBtn.addEventListener('click', function () {
                        dlg.classList.remove('visible');
                    });
                }
            }
            const ta = document.getElementById('errorPopupText');
            if (ta) {
                ta.value = msg || '';
                ta.focus();
                ta.select();
            }
            dlg.classList.add('visible');
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

                    // Extract parent folder for context (e.g. "ABOUT" for about.html)
                    const parts = path.replace(/\\/g, '/').split('/').filter(Boolean);
                    const parentDir = parts.length >= 2 && !/^[a-zA-Z]:$/.test(parts[parts.length - 2])
                        ? parts[parts.length - 2] : '';

                    el.title = path;
                    el.innerHTML = `
                        <i class="recent-icon ${iconClass}"></i>
                        <span class="recent-name-wrap">
                            <span class="recent-name">${name}</span>
                            ${parentDir ? `<span class="recent-folder">${parentDir}</span>` : ''}
                        </span>
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
            } catch (e) { sectionEl.style.display = 'none'; }
        }

        // ── SELECT / CROP between SASA-1 / SASA-2 markers ──
        let sasaSelectionActive = false;
        let cropHighlightActive = false;

        function getSasaRange() {
            if (!editor) return null;
            const src = editor.getValue();
            const marker1 = '<!-- SASA-1 -->';
            const marker2 = '<!-- SASA-2 -->';
            const idx1 = src.indexOf(marker1);
            const idx2 = src.indexOf(marker2);
            if (idx1 === -1 || idx2 === -1) return null;
            // Start from the NEXT LINE after <!-- SASA-1 -->
            let startIdx = idx1 + marker1.length;
            const nlPos = src.indexOf('\n', startIdx);
            if (nlPos !== -1 && nlPos < idx2) startIdx = nlPos + 1;
            // End just before <!-- SASA-2 --> (trim trailing newline)
            let endIdx = idx2;
            if (endIdx > startIdx && src[endIdx - 1] === '\n') endIdx--;
            if (endIdx > startIdx && src[endIdx - 1] === '\r') endIdx--;
            return {
                from: editor.posFromIndex(startIdx),
                to: editor.posFromIndex(endIdx),
                startIdx, endIdx
            };
        }

        function isCursorInsideSasa() {
            if (!editor) return false;
            const range = getSasaRange();
            if (!range) return false;
            const curIdx = editor.indexFromPos(editor.getCursor());
            return curIdx >= range.startIdx && curIdx <= range.endIdx;
        }

        function selectSasaRegion() {
            if (!editor) return;
            // Toggle: if already active, deactivate
            if (sasaSelectionActive) {
                deactivateSasa();
                return;
            }
            const range = getSasaRange();
            if (!range) {
                toast('Marcajele <!-- SASA-1 --> si <!-- SASA-2 --> nu au fost gasite!');
                return;
            }
            // Deactivate CROP if it's on
            if (cropHighlightActive) deactivateCrop();
            // Select in code editor (needed for code/split modes, and as
            // the source-of-truth range even in design mode).
            editor.setSelection(range.from, range.to);
            editor.scrollIntoView({ from: range.from, to: range.to }, 60);
            // Activate SASA for ALL view modes — including design.
            sasaSelectionActive = true;
            document.getElementById('btnSelectSasa').classList.add('active');
            // Visual highlight in design panel (SELECT mode — only between SASA markers)
            highlightSasaInDesign('select');
            // Focus the right panel; în modul DESIGN selectăm și textul vizibil
            if (viewMode === 'design') {
                const iframe = document.getElementById('preview');
                const iDoc = iframe && iframe.contentDocument;
                if (iDoc && iDoc.body) {
                    iDoc.body.focus();
                    selectSasaTextInDesign();
                }
            } else {
                editor.focus();
            }
        }

        // CROP — visual-only highlight of paragraphs between SASA markers.
        // Does NOT activate sasaSelectionActive, so typing doesn't replace all
        // content.  Clicking inside a paragraph allows normal editing.
        // Highlight is PERSISTENT — stays on regardless of clicks or typing.
        function cropSasaRegion() {
            if (!editor) return;
            // Toggle: if already active, deactivate
            if (cropHighlightActive) {
                deactivateCrop();
                return;
            }
            const range = getSasaRange();
            if (!range) {
                toast('Marcajele <!-- SASA-1 --> si <!-- SASA-2 --> nu au fost gasite!');
                return;
            }
            // Deactivate SELECT if it's on
            if (sasaSelectionActive) deactivateSasa();
            // Visual highlight only — no code selection, no SASA activation
            cropHighlightActive = true;
            document.getElementById('btnCropSasa').classList.add('active');
            highlightSasaInDesign('crop');
            // In code/split mode, scroll to show the SASA region (but don't select)
            if (viewMode !== 'design') {
                editor.scrollIntoView({ from: range.from, to: range.to }, 60);
            }
        }

        function deactivateCrop() {
            if (!cropHighlightActive) return;
            cropHighlightActive = false;
            document.getElementById('btnCropSasa').classList.remove('active');
            clearSasaDesignHighlight();
        }

        // Add background highlight to design content between SASA markers.
        // mode='select' — only highlights between SASA-1 and SASA-2
        // mode='crop'   — also highlights h1.den_articol and td.text_dreapta
        // Focus/selection for SELECT este făcut în selectSasaRegion().
        function highlightSasaInDesign(mode) {
            const iframe = document.getElementById('preview');
            if (!iframe) return;
            const doc = iframe.contentDocument;
            if (!doc || !doc.body) return;
            // Remove any previous highlight
            clearSasaDesignHighlight(doc);
            // Find comment nodes
            let sasa1 = null, sasa2 = null;
            const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT, null);
            let node;
            while ((node = walker.nextNode())) {
                const val = (node.nodeValue || '').trim();
                if (val === 'SASA-1') sasa1 = node;
                else if (val === 'SASA-2') sasa2 = node;
            }
            if (!sasa1 || !sasa2) return;
            // Collect all sibling nodes between the two comments for visual highlight
            let current = sasa1.nextSibling;
            while (current && current !== sasa2) {
                if (current.nodeType === Node.ELEMENT_NODE) {
                    current.setAttribute('data-sasa-highlight', '1');
                    current.style.outline = '2px solid #3b82f6';
                    current.style.backgroundColor = 'rgba(59,130,246,0.12)';
                }
                current = current.nextSibling;
            }
            // CROP mode: also highlight h1.den_articol and td.text_dreapta
            if (mode === 'crop') {
                doc.querySelectorAll('h1.den_articol').forEach(h1 => {
                    h1.setAttribute('data-sasa-highlight', '1');
                    h1.style.outline = '2px solid #3b82f6';
                    h1.style.backgroundColor = 'rgba(59,130,246,0.12)';
                });
                doc.querySelectorAll('td.text_dreapta').forEach(td => {
                    td.setAttribute('data-sasa-highlight', '1');
                    td.style.outline = '2px solid #3b82f6';
                    td.style.backgroundColor = 'rgba(59,130,246,0.12)';
                });
            }
        }

        // Selectează în Design (iframe) DOAR textul dintre comentariile <!-- SASA-1 --> și <!-- SASA-2 -->,
        // astfel încât Ctrl+C să copieze direct textul vizibil (fără cod) după apăsarea butonului Select.
        function selectSasaTextInDesign() {
            const iframe = document.getElementById('preview');
            if (!iframe) return;
            const doc = iframe.contentDocument;
            if (!doc || !doc.body) return;

            // Găsim comentariile SASA-1 și SASA-2
            let sasa1 = null, sasa2 = null;
            const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT, null);
            let node;
            while ((node = walker.nextNode())) {
                const val = (node.nodeValue || '').trim();
                if (val === 'SASA-1') sasa1 = node;
                else if (val === 'SASA-2') sasa2 = node;
            }
            if (!sasa1 || !sasa2) return;

            const selection = doc.getSelection();
            if (!selection) return;
            selection.removeAllRanges();

            const range = doc.createRange();
            // Range-ul începe imediat după comentariul SASA-1
            range.setStartAfter(sasa1);
            // și se termină chiar înainte de comentariul SASA-2
            range.setEndBefore(sasa2);
            selection.addRange(range);
        }

        function clearSasaDesignHighlight(doc) {
            if (!doc) {
                const iframe = document.getElementById('preview');
                if (iframe) doc = iframe.contentDocument;
            }
            if (!doc || !doc.body) return;
            doc.querySelectorAll('[data-sasa-highlight]').forEach(el => {
                el.removeAttribute('data-sasa-highlight');
                el.style.outline = '';
                el.style.backgroundColor = '';
                // Remove the style attribute entirely if it became empty
                if (el.getAttribute('style') === '' || el.getAttribute('style') === null) {
                    el.removeAttribute('style');
                }
            });
        }

        // Normalize Romanian diacritics so design-mode and code use the same characters.
        // Maps older sedilă / punct-dedesubt variante (ş, ţ, ṣ, ṭ, etc.) la forma corectă cu virgulă (ș, ț).
        function normalizeRomanianDiacritics(str) {
            if (!str) return str;
            return str
                // ș mic
                .replace(/[\u015F\u1E63]/g, 'ș')
                // Ș mare
                .replace(/[\u015E\u1E62]/g, 'Ș')
                // ț mic
                .replace(/[\u0163\u1E6D]/g, 'ț')
                // Ț mare
                .replace(/[\u0162\u1E6C]/g, 'Ț');
        }

        // Replace &nbsp; with regular space only in the SASA region and h1.den_articol,
        // and normalize diacritics to UTF-8 românești standard. Operates on the HTML string
        // — never at the DOM level, deci nu afectează poziția cursorului.
        function cleanNbspInHtml(html) {
            if (!html) return html;
            // Between <!-- SASA-1 --> and <!-- SASA-2 -->
            html = html.replace(
                /(<!-- SASA-1 -->)([\s\S]*?)(<!-- SASA-2 -->)/,
                (_, s1, content, s2) => s1 + content.replace(/&nbsp;/gi, ' ') + s2
            );
            // In <h1 class="den_articol ...">...</h1>
            html = html.replace(
                /(<h1\b[^>]*\bden_articol\b[^>]*>)([\s\S]*?)(<\/h1>)/gi,
                (_, open, content, close) => open + content.replace(/&nbsp;/gi, ' ') + close
            );
            // Normalize diacritics everywhere in body html
            html = normalizeRomanianDiacritics(html);
            return html;
        }

        // Clear SASA state when user clicks elsewhere or edits
        function deactivateSasa() {
            sasaSelectionActive = false;
            document.getElementById('btnSelectSasa').classList.remove('active');
            clearSasaDesignHighlight();
        }

        // Wrap raw text in <p class="text_obisnuit">…</p> for SASA insert.
        // Each non-empty line becomes its own paragraph.
        function wrapTextForSasa(rawText) {
            if (!rawText) return '';
            const prefix = '<p class="text_obisnuit">';
            const suffix = '</p>';
            const lines = rawText.split(/\r?\n/).filter(l => l.trim().length > 0);
            if (lines.length === 0) return '';
            return lines.map(l => prefix + l.trim() + suffix).join('\n');
        }

        // After any edit (type/delete/paste) with SASA selection active in code,
        // ensure cursor lands on the empty line between markers
        function positionCursorBetweenSasa() {
            if (!editor) return;
            const src = editor.getValue();
            const marker1 = '<!-- SASA-1 -->';
            const idx1 = src.indexOf(marker1);
            if (idx1 === -1) return;
            let pos = idx1 + marker1.length;
            const nl = src.indexOf('\n', pos);
            if (nl !== -1) pos = nl + 1;
            const cursorPos = editor.posFromIndex(pos);
            editor.setCursor(cursorPos);
            editor.scrollIntoView(cursorPos, 60);
        }

        // Position the design iframe caret between SASA markers so that
        // subsequent typing in design-only mode goes into the right place.
        function positionDesignCaretBetweenSasa() {
            const iframe = document.getElementById('preview');
            if (!iframe) return;
            const iDoc = iframe.contentDocument;
            const win = iframe.contentWindow;
            if (!iDoc || !iDoc.body || !win) return;
            // Find SASA comment nodes
            let sasa1 = null, sasa2 = null;
            const walker = iDoc.createTreeWalker(iDoc.body, NodeFilter.SHOW_COMMENT, null);
            let cNode;
            while ((cNode = walker.nextNode())) {
                const val = (cNode.nodeValue || '').trim();
                if (val === 'SASA-1') sasa1 = cNode;
                else if (val === 'SASA-2') sasa2 = cNode;
            }
            if (!sasa1 || !sasa2) return;
            // Walk backwards from SASA-2 to find the last text/element node
            // between the markers, and place the caret at the END of content
            // so the user can keep typing naturally.
            const range = iDoc.createRange();
            let prev = sasa2.previousSibling;
            // Skip pure-whitespace text nodes right before SASA-2
            while (prev && prev !== sasa1 && prev.nodeType === Node.TEXT_NODE &&
                !prev.nodeValue.replace(/[\r\n]/g, '').length) {
                prev = prev.previousSibling;
            }
            if (prev && prev !== sasa1) {
                if (prev.nodeType === Node.TEXT_NODE) {
                    // Place caret at end of the text node content
                    range.setStart(prev, prev.nodeValue.length);
                } else {
                    // Place caret after the last element
                    range.setStartAfter(prev);
                }
            } else {
                // Nothing meaningful between markers — place caret after SASA-1
                range.setStartAfter(sasa1);
            }
            range.collapse(true);
            const sel = win.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }

        // Position the design caret inside the last <em> element between SASA
        // markers. This is used after SASA edits that wrap content in
        // <p class="text_obisnuit"><em>…</em></p> so that continued typing in
        // design mode stays inside the formatted element.
        function positionDesignCaretInSasaEm() {
            const iframe = document.getElementById('preview');
            if (!iframe) return;
            const iDoc = iframe.contentDocument;
            const win = iframe.contentWindow;
            if (!iDoc || !iDoc.body || !win) return;
            // Find SASA comment nodes
            let sasa1 = null, sasa2 = null;
            const walker = iDoc.createTreeWalker(iDoc.body, NodeFilter.SHOW_COMMENT, null);
            let cNode;
            while ((cNode = walker.nextNode())) {
                const val = (cNode.nodeValue || '').trim();
                if (val === 'SASA-1') sasa1 = cNode;
                else if (val === 'SASA-2') sasa2 = cNode;
            }
            if (!sasa1 || !sasa2) return;
            // Walk backwards from SASA-2 looking for the last <em> inside SASA zone
            let lastEm = null;
            let lastBlock = null;
            let node = sasa2.previousSibling;
            while (node && node !== sasa1) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    const em = node.querySelector ? node.querySelector('em') : null;
                    if (em) { lastEm = em; break; }
                    if (!lastBlock) lastBlock = node;
                }
                node = node.previousSibling;
            }
            const range = iDoc.createRange();
            if (lastEm) {
                // Place caret at end of text inside <em>
                if (lastEm.lastChild && lastEm.lastChild.nodeType === Node.TEXT_NODE) {
                    range.setStart(lastEm.lastChild, lastEm.lastChild.nodeValue.length);
                } else if (lastEm.lastChild) {
                    range.setStartAfter(lastEm.lastChild);
                } else {
                    range.setStart(lastEm, 0);
                }
            } else if (lastBlock) {
                // No <em>: place caret at end of deepest text node inside last block
                let deepNode = lastBlock;
                while (deepNode.lastChild) deepNode = deepNode.lastChild;
                if (deepNode.nodeType === Node.TEXT_NODE) {
                    range.setStart(deepNode, deepNode.nodeValue.length);
                } else {
                    range.setStartAfter(deepNode);
                }
            } else {
                // Fallback: after SASA-1
                range.setStartAfter(sasa1);
            }
            range.collapse(true);
            const sel = win.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }

        // ── Browser Tab (Google Translate / web browsing) ──
        let browserPanelOpen = false;
        const BROWSER_DEFAULT_URL = 'https://translate.google.com/?sl=ro&tl=en&op=translate';

        // Construiește URL-ul către proxy-ul PHP pentru o adresă dată.
        function buildProxyUrl(url) {
            // Folosim același script (index V.4.php) cu ?action=webproxy&url=...
            const base = window.location.pathname.replace(/[#?].*$/, '');
            return base + '?action=webproxy&url=' + encodeURIComponent(url);
        }

        function toggleBrowserPanel() {
            // If a browser tab already exists, switch to it (or away from it)
            var existingBrowserTab = null;
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].type === 'browser') { existingBrowserTab = tabs[i]; break; }
            }
            if (existingBrowserTab) {
                if (activeTabId === existingBrowserTab.id) {
                    // Already on browser tab — switch to previous file tab
                    var fileTab = null;
                    for (var i = 0; i < tabs.length; i++) {
                        if (tabs[i].type === 'file') { fileTab = tabs[i]; break; }
                    }
                    if (fileTab) switchToTab(fileTab.id);
                } else {
                    switchToTab(existingBrowserTab.id);
                }
                return;
            }
            // Create a new browser tab
            saveCurrentTabState();
            var tab = createTabState({
                type: 'browser',
                tabLabel: 'Google Translate',
                fullTitle: 'Google Translate (RO → EN)',
                browserUrl: BROWSER_DEFAULT_URL
            });
            tabs.push(tab);
            activeTabId = tab.id;
            // Show browser panel
            var splitContainer = document.getElementById('splitContainer');
            var browserPanel = document.getElementById('browserPanel');
            var propsPanel = document.getElementById('propertiesPanel');
            var btnGoogle = document.getElementById('btnBrowserTab');
            splitContainer.style.display = 'none';
            if (propsPanel) propsPanel.style.display = 'none';
            browserPanel.style.display = 'flex';
            browserPanelOpen = true;
            if (btnGoogle) btnGoogle.classList.add('active');
            var urlBar = document.getElementById('browserUrlBar');
            if (urlBar) urlBar.value = BROWSER_DEFAULT_URL;
            // Încarcă Google Translate în iframe prin proxy server-side
            const frame = document.getElementById('browserFrame');
            if (frame) {
                frame.src = buildProxyUrl(BROWSER_DEFAULT_URL);
            }
            renderTabs();
        }

        function closeBrowserPanel() {
            var splitContainer = document.getElementById('splitContainer');
            var browserPanel = document.getElementById('browserPanel');
            var propsPanel = document.getElementById('propertiesPanel');
            var btn = document.getElementById('btnBrowserTab');
            browserPanel.style.display = 'none';
            splitContainer.style.display = '';
            if (propsPanel && typeof isCurrentFileHtml === 'function' && isCurrentFileHtml() &&
                (viewMode === 'design' || viewMode === 'split')) {
                propsPanel.style.display = '';
            }
            browserPanelOpen = false;
            if (btn) btn.classList.remove('active');
            const frame = document.getElementById('browserFrame');
            if (frame) frame.src = 'about:blank';
            if (editor) setTimeout(() => editor.refresh(), 50);
        }

        function browserNavigate() {
            const urlBar = document.getElementById('browserUrlBar');
            let url = urlBar.value.trim();
            if (!url) return;
            // Auto-add https:// if no protocol
            if (!/^https?:\/\//i.test(url)) {
                if (/^[a-zA-Z0-9].*\.[a-zA-Z]/.test(url)) {
                    url = 'https://' + url;
                } else {
                    url = 'https://www.google.com/search?q=' + encodeURIComponent(url);
                }
            }
            urlBar.value = url;
            // Update the tab's stored URL
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].id === activeTabId && tabs[i].type === 'browser') {
                    tabs[i].browserUrl = url;
                    break;
                }
            }
            // Navighează în iframe prin proxy, pentru a rămâne în aplicație
            const frame = document.getElementById('browserFrame');
            if (frame) {
                frame.src = buildProxyUrl(url);
            }
        }

        function browserGoBack() {
            const frame = document.getElementById('browserFrame');
            if (frame && frame.contentWindow) {
                try { frame.contentWindow.history.back(); } catch (e) { }
            }
        }

        function browserGoForward() {
            const frame = document.getElementById('browserFrame');
            if (frame && frame.contentWindow) {
                try { frame.contentWindow.history.forward(); } catch (e) { }
            }
        }

        function browserRefresh() {
            const frame = document.getElementById('browserFrame');
            if (frame && frame.contentWindow) {
                try { frame.contentWindow.location.reload(); } catch (e) { }
            }
        }

        function browserReopenWindow() {
            var tab = null;
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].id === activeTabId && tabs[i].type === 'browser') { tab = tabs[i]; break; }
            }
            if (!tab) return;
            var url = document.getElementById('browserUrlBar').value || tab.browserUrl || BROWSER_DEFAULT_URL;
            // Deschide separat doar la cerere explicită (butonul din panel),
            // nu automat când deschidem tabul.
            tab.browserWindowRef = window.open(url, 'googleTranslate');
        }

        // Actualizează bara de adrese când proxy-ul notifică schimbarea URL-ului
        window.addEventListener('message', function (e) {
            if (!e.data || e.data.type !== 'proxyUrlChange') return;
            const newUrl = e.data.url;
            const urlBar = document.getElementById('browserUrlBar');
            if (urlBar) urlBar.value = newUrl;
            // actualizează și tab-ul curent de tip browser
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].id === activeTabId && tabs[i].type === 'browser') {
                    tabs[i].browserUrl = newUrl;
                    break;
                }
            }
        });

        // ── Diacritics Popup ──
        function toggleDiacPopup() {
            const dlg = document.getElementById('diacPopup');
            if (dlg.classList.contains('visible')) {
                closeDiacPopup();
            } else {
                dlg.classList.add('visible');
                dlg.focus();
            }
        }
        function closeDiacPopup() {
            document.getElementById('diacPopup').classList.remove('visible');
        }
        // Global Escape listener (covers focus on button, body, or anywhere outside code editor)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const dlg = document.getElementById('diacPopup');
                if (dlg && dlg.classList.contains('visible')) {
                    closeDiacPopup();
                }
            }
        });

        // ── Translation helper popup ──
        function toggleTranslatePopup() {
            const dlg = document.getElementById('translatePopup');
            if (dlg.classList.contains('visible')) {
                closeTranslatePopup();
            } else {
                // Reset la dimensiunea implicită de fiecare dată când se deschide
                dlg.style.width = '';
                dlg.style.height = '';
                dlg.style.bottom = 'auto';

                // Poziționare implicită: dreapta sus, sub toolbar
                const margin = 12;
                dlg.style.left = (window.innerWidth - 520 - margin) + 'px';
                dlg.style.top = '70px';
                dlg.classList.add('visible');
                loadSelectionIntoTranslate();
                dlg.focus();
            }
        }
        function closeTranslatePopup() {
            document.getElementById('translatePopup').classList.remove('visible');
        }

        function getCurrentSelectionText() {
            // 1) Dacă suntem în Design / Split, încercăm din iframe
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if ((viewMode === 'design' || viewMode === 'split') && doc && doc.body) {
                const win = iframe.contentWindow;
                const sel = win && win.getSelection && win.getSelection();
                if (sel && sel.toString().trim()) {
                    return sel.toString();
                }
            }
            // 2) Altfel, din CodeMirror
            if (editor) {
                const sel = editor.getSelection();
                if (sel && sel.trim()) return sel;
            }
            return '';
        }

        function loadSelectionIntoTranslate() {
            const src = document.getElementById('trSourceText');
            const status = document.getElementById('trStatus');
            if (!src) return;
            const txt = getCurrentSelectionText();
            if (txt) {
                src.value = txt;
                status.textContent = 'Text preluat din selecție.';
            } else {
                status.textContent = 'Nicio selecție; poți scrie manual textul.';
            }
        }

        function runTranslate() {
            const src = document.getElementById('trSourceText');
            const dst = document.getElementById('trResultText');
            const status = document.getElementById('trStatus');
            const sl = document.getElementById('trSourceLang').value || 'auto';
            const tl = document.getElementById('trTargetLang').value || 'en';
            const text = (src.value || '').trim();
            if (!text) {
                status.textContent = 'Introdu sau preia un text pentru traducere.';
                return;
            }
            status.textContent = 'Se traduce...';
            dst.value = '';
            // Folosim un API public neoficial Google Translate (fără autentificare),
            // prin endpoint-ul PHP ?action=translate.
            const url = window.location.pathname.replace(/[#?].*$/, '') +
                '?action=translate&sl=' + encodeURIComponent(sl) +
                '&tl=' + encodeURIComponent(tl) +
                '&q=' + encodeURIComponent(text);
            fetch(url)
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(data => {
                    if (data && data.ok && typeof data.text === 'string') {
                        dst.value = data.text;
                        status.textContent = 'Traducere finalizată.';
                    } else {
                        status.textContent = 'Eroare la traducere (răspuns nevalid).';
                    }
                })
                .catch(err => {
                    console.error(err);
                    status.textContent = 'Eroare la traducere: ' + err.message;
                });
        }

        function applyTranslatedToSelection() {
            const dst = document.getElementById('trResultText');
            const status = document.getElementById('trStatus');
            const text = (dst && dst.value) || '';
            if (!text) {
                status.textContent = 'Nu există un text tradus de inserat.';
                return;
            }
            let replaced = false;
            // 1) Dacă suntem în Design, înlocuim selecția acolo
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if ((viewMode === 'design' || viewMode === 'split') && doc && doc.body) {
                const win = iframe.contentWindow;
                const sel = win && win.getSelection && win.getSelection();
                if (sel && sel.rangeCount > 0 && sel.toString()) {
                    const range = sel.getRangeAt(0);
                    range.deleteContents();
                    range.insertNode(doc.createTextNode(text));
                    sel.removeAllRanges();
                    const r2 = doc.createRange();
                    r2.setStart(range.endContainer, range.endOffset);
                    r2.collapse(true);
                    sel.addRange(r2);
                    clearTimeout(designInputDebounceTimer);
                    designInputDebounceTimer = setTimeout(syncFromDesign, 100);
                    replaced = true;
                }
            }
            // 2) dacă nu s-a reușit în Design, încearcă în CodeMirror
            if (!replaced && editor) {
                const sel = editor.getSelection();
                if (sel) {
                    editor.replaceSelection(text);
                    replaced = true;
                }
            }
            if (replaced) {
                status.textContent = 'Textul tradus a fost inserat în document.';
            } else {
                status.textContent = 'Nu există o selecție de înlocuit; poți copia manual din caseta „Text tradus”.';
            }
        }
        // Insert a diacritical character at the current cursor position
        // (works in both code editor and design panel)
        function insertDiac(ch) {
            const vm = viewMode; // 'code', 'design', or 'split'
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            // Try design panel first (if it has focus / a selection)
            if ((vm === 'design' || vm === 'split') && doc && doc.body) {
                const win = iframe.contentWindow;
                const sel = win && win.getSelection();
                if (sel && sel.rangeCount > 0) {
                    // If the design body is contentEditable and focused, insert there
                    if (doc.body.isContentEditable) {
                        designSaveSnapshot();
                        doc.execCommand('insertText', false, ch);
                        clearTimeout(designInputDebounceTimer);
                        designInputDebounceTimer = setTimeout(syncFromDesign, 80);
                        closeDiacPopup();
                        win.focus();
                        return;
                    }
                }
            }
            // Otherwise insert in code editor
            if (editor) {
                editor.replaceSelection(ch);
                editor.focus();
            }
            closeDiacPopup();
        }

        // ── Find & Replace ──
        let frCurrentMatches = [];
        let frCurrentIdx = -1;
        let frMarks = [];
        let _frLastQuery = '';       // last search string used to build matches
        let _frLastCaseSens = false; // last Case Sensitive state
        let _frLastWholeWord = false; // last Whole Word state
        let _frLastDesignScope = false; // was last search design-scoped?

        // Returns true when the search should be limited to the article zone
        // (between <!-- ARTICOL START --> and <!-- ARTICOL FINAL -->).
        function _isFindDesignScoped() {
            if (viewMode === 'design') return true;
            if (viewMode === 'code') return false;
            // split mode — check if the iframe (design panel) had focus recently
            const iframe = document.getElementById('preview');
            try {
                if (document.activeElement === iframe) return true;
                // When find dialog takes focus, remember last active panel.
                // The iframe's body being contentEditable means the user was
                // working in design before opening Find.
            } catch (e) {}
            return _frDesignWasLastFocused;
        }

        // Track which panel the user interacted with last (for split mode).
        let _frDesignWasLastFocused = false;

        function toggleFindReplace() {
            const dlg = document.getElementById('findReplaceDialog');
            if (dlg.classList.contains('visible')) {
                closeFindReplace();
            } else {
                // Deactivate SELECT / CROP so they don't interfere with FIND
                if (sasaSelectionActive) deactivateSasa();
                if (cropHighlightActive) deactivateCrop();
                dlg.classList.add('visible');
                const inp = document.getElementById('frFindInput');
                const repInp = document.getElementById('frReplaceInput');
                // Pre-fill with current selection, or clear if nothing selected
                if (editor) {
                    const sel = editor.getSelection();
                    if (sel) {
                        inp.value = sel;
                    } else {
                        inp.value = '';
                        repInp.value = '';
                    }
                } else {
                    inp.value = '';
                    repInp.value = '';
                }
                inp.focus();
                inp.select();
            }
        }

        function closeFindReplace() {
            document.getElementById('findReplaceDialog').classList.remove('visible');
            clearFindMarks();
            document.getElementById('frInfo').textContent = '';
            _frLastQuery = '';
            frCurrentIdx = -1;
        }

        function clearFindMarks() {
            frMarks.forEach(m => m.clear());
            frMarks = [];
            clearDesignFindHighlights();
        }

        function clearDesignFindHighlights() {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body) return;
            // Remove all find highlight marks from design panel
            const marks = doc.querySelectorAll('mark[data-fr-highlight]');
            marks.forEach(mark => {
                const parent = mark.parentNode;
                while (mark.firstChild) parent.insertBefore(mark.firstChild, mark);
                parent.removeChild(mark);
                parent.normalize(); // merge adjacent text nodes back
            });
        }

        function highlightFindInDesign() {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body) return;
            if (viewMode !== 'design' && viewMode !== 'split') return;

            clearDesignFindHighlights();

            const regex = buildSearchRegex();
            if (!regex) return;

            // Walk all text nodes in the design body and highlight matches
            const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, {
                acceptNode(node) {
                    // Skip script/style/empty nodes
                    const tag = node.parentNode && node.parentNode.tagName;
                    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'MARK') return NodeFilter.FILTER_REJECT;
                    if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_SKIP;
                    return NodeFilter.FILTER_ACCEPT;
                }
            });

            const textNodes = [];
            let tNode;
            while ((tNode = walker.nextNode())) textNodes.push(tNode);

            textNodes.forEach(node => {
                const text = node.nodeValue;
                regex.lastIndex = 0;
                let match;
                const parts = [];
                let lastIdx = 0;
                while ((match = regex.exec(text)) !== null) {
                    if (match.index > lastIdx) {
                        parts.push(doc.createTextNode(text.slice(lastIdx, match.index)));
                    }
                    const mark = doc.createElement('mark');
                    mark.setAttribute('data-fr-highlight', '1');
                    mark.style.background = 'rgba(255, 165, 0, 0.45)';
                    mark.style.borderBottom = '2px solid #f59e0b';
                    mark.style.padding = '0';
                    mark.style.color = 'inherit';
                    mark.textContent = match[0];
                    parts.push(mark);
                    lastIdx = match.index + match[0].length;
                    if (match[0].length === 0) break; // prevent infinite loop on zero-length match
                }
                if (parts.length > 0) {
                    if (lastIdx < text.length) {
                        parts.push(doc.createTextNode(text.slice(lastIdx)));
                    }
                    const parent = node.parentNode;
                    parts.forEach(p => parent.insertBefore(p, node));
                    parent.removeChild(node);
                }
            });
        }

        function buildDiacInsensitivePattern(text) {
            const specials = /[.*+?^${}()|[\]\\]/;
            let result = '';
            for (const ch of text) {
                if (ch === 'a' || ch === 'A') {
                    result += '[aăâAĂÂ]';
                } else if (ch === 'i' || ch === 'I') {
                    result += '[iîIÎ]';
                } else if (ch === 's' || ch === 'S') {
                    result += '[sșşSȘŞ]';
                } else if (ch === 't' || ch === 'T') {
                    result += '[tțţTȚŢ]';
                } else if (specials.test(ch)) {
                    result += '\\' + ch;
                } else {
                    result += ch;
                }
            }
            return result;
        }

        function buildSearchRegex() {
            const query = document.getElementById('frFindInput').value;
            if (!query) return null;
            const caseSensitive = document.getElementById('frCaseSensitive').checked;
            const wholeWord = document.getElementById('frWholeWord').checked;
            let pattern = buildDiacInsensitivePattern(query);
            if (wholeWord) pattern = '\\b' + pattern + '\\b';
            const flags = caseSensitive ? 'g' : 'gi';
            try { return new RegExp(pattern, flags); } catch (e) { return null; }
        }

        function findAllMatches() {
            clearFindMarks();
            frCurrentMatches = [];
            frCurrentIdx = -1;
            if (!editor) return;
            const regex = buildSearchRegex();
            if (!regex) {
                document.getElementById('frInfo').textContent = '';
                return;
            }
            const src = editor.getValue();

            // Determine search boundaries.
            // In design scope, restrict to <!-- ARTICOL START --> … <!-- ARTICOL FINAL -->
            let searchStart = 0;
            let searchEnd = src.length;
            const designScoped = _isFindDesignScoped();
            _frLastDesignScope = designScoped;

            if (designScoped) {
                const artStartMatch = src.indexOf('<!-- ARTICOL START -->');
                const artEndMatch = src.indexOf('<!-- ARTICOL FINAL -->');
                if (artStartMatch !== -1 && artEndMatch !== -1 && artEndMatch > artStartMatch) {
                    searchStart = artStartMatch + '<!-- ARTICOL START -->'.length;
                    searchEnd = artEndMatch;
                }
            }

            let m;
            while ((m = regex.exec(src)) !== null) {
                // Skip matches outside the search boundaries
                if (m.index < searchStart) continue;
                if (m.index + m[0].length > searchEnd) break;

                const from = editor.posFromIndex(m.index);
                const to = editor.posFromIndex(m.index + m[0].length);
                frCurrentMatches.push({ from, to, index: m.index, length: m[0].length });
                // Highlight all matches with a dim background
                const mark = editor.markText(from, to, {
                    className: 'cm-find-highlight'
                });
                frMarks.push(mark);
            }
            document.getElementById('frInfo').textContent = frCurrentMatches.length + ' rezultate gasite';
            // Also highlight matches in the Design panel
            highlightFindInDesign();
        }

        // Rebuild match list only when query or options changed since last search.
        function _ensureFindMatches() {
            const q = document.getElementById('frFindInput').value;
            const cs = document.getElementById('frCaseSensitive').checked;
            const ww = document.getElementById('frWholeWord').checked;
            const ds = _isFindDesignScoped();
            if (q === _frLastQuery && cs === _frLastCaseSens && ww === _frLastWholeWord
                && ds === _frLastDesignScope && frCurrentMatches.length > 0) {
                return; // matches are still valid
            }
            _frLastQuery = q;
            _frLastCaseSens = cs;
            _frLastWholeWord = ww;
            findAllMatches();
            // After a fresh search, position index so the NEXT call lands on
            // the first match at or after the current cursor.
            if (frCurrentMatches.length > 0) {
                const cursorIdx = editor.indexFromPos(editor.getCursor('from'));
                frCurrentIdx = -1; // will be incremented by findNext
                for (let i = 0; i < frCurrentMatches.length; i++) {
                    if (frCurrentMatches[i].index >= cursorIdx) {
                        frCurrentIdx = i - 1; // so +1 lands on i
                        break;
                    }
                }
                // If cursor is past all matches, wrap to start
                if (frCurrentIdx === -1 && frCurrentMatches[0].index < cursorIdx) {
                    frCurrentIdx = frCurrentMatches.length - 1; // wrap: next +1 → 0
                }
            }
        }

        function findNext() {
            _ensureFindMatches();
            if (frCurrentMatches.length === 0) {
                document.getElementById('frInfo').textContent = 'Niciun rezultat';
                return;
            }
            frCurrentIdx = (frCurrentIdx + 1) % frCurrentMatches.length;
            goToMatch(frCurrentIdx);
        }

        function findPrev() {
            _ensureFindMatches();
            if (frCurrentMatches.length === 0) {
                document.getElementById('frInfo').textContent = 'Niciun rezultat';
                return;
            }
            frCurrentIdx = (frCurrentIdx - 1 + frCurrentMatches.length) % frCurrentMatches.length;
            goToMatch(frCurrentIdx);
        }

        function goToMatch(idx) {
            const match = frCurrentMatches[idx];
            if (!match) return;
            editor.setSelection(match.from, match.to);
            editor.scrollIntoView({ from: match.from, to: match.to }, 80);
            editor.focus();
            // Scroll to the active match in Design panel and highlight it
            try {
                if (viewMode === 'design' || viewMode === 'split') {
                    scrollToDesignMatch(idx);
                }
            } catch (e) {
                // daca apare vreo eroare, nu blocam cautarea in cod
            }
            document.getElementById('frInfo').textContent =
                'Rezultat ' + (idx + 1) + ' din ' + frCurrentMatches.length;
        }

        function scrollToDesignMatch(idx) {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body) return;
            const marks = doc.querySelectorAll('mark[data-fr-highlight]');
            if (marks.length === 0) return;
            // Reset all to default style
            marks.forEach(m => {
                m.style.background = 'rgba(255, 165, 0, 0.45)';
                m.style.outline = 'none';
            });
            // Highlight active match more prominently
            // idx is the code match index; design marks may differ in count
            // but usually they correspond, so we use min(idx, marks.length-1)
            const activeIdx = Math.min(idx, marks.length - 1);
            if (marks[activeIdx]) {
                marks[activeIdx].style.background = 'rgba(255, 140, 0, 0.8)';
                marks[activeIdx].style.outline = '2px solid #f59e0b';
                marks[activeIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function replaceCurrent() {
            if (!editor) return;
            const replaceText = document.getElementById('frReplaceInput').value;
            // If we have a current match selected, replace it
            if (frCurrentIdx >= 0 && frCurrentIdx < frCurrentMatches.length) {
                const match = frCurrentMatches[frCurrentIdx];
                editor.setSelection(match.from, match.to);
                editor.replaceSelection(replaceText);
                isDirty = true;
                // Re-find from current position
                findAllMatches();
                if (frCurrentMatches.length > 0) {
                    if (frCurrentIdx >= frCurrentMatches.length) frCurrentIdx = 0;
                    goToMatch(frCurrentIdx);
                } else {
                    document.getElementById('frInfo').textContent = 'Niciun rezultat ramas';
                }
            } else {
                // No active match — find first then replace
                findNext();
            }
        }

        function replaceAll() {
            if (!editor) return;
            const findText = document.getElementById('frFindInput').value;
            const replaceText = document.getElementById('frReplaceInput').value;
            if (!findText) return;
            const regex = buildSearchRegex();
            if (!regex) return;
            const src = editor.getValue();
            const count = (src.match(regex) || []).length;
            if (count === 0) {
                document.getElementById('frInfo').textContent = 'Niciun rezultat';
                return;
            }
            const newSrc = src.replace(regex, replaceText);
            const cursor = editor.getCursor();
            editor.setValue(newSrc);
            editor.setCursor(cursor);
            isDirty = true;
            clearFindMarks();
            frCurrentMatches = [];
            frCurrentIdx = -1;
            document.getElementById('frInfo').textContent = count + ' inlocuiri efectuate';
            toast(count + ' inlocuiri effectuate');
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
            // Diacritice românești în editorul de cod
            editor.addKeyMap({
                'Ctrl-A': cm => { cm.replaceSelection('ă'); },
                'Ctrl-I': cm => { cm.replaceSelection('î'); },
                'Shift-Ctrl-S': cm => { cm.replaceSelection('ș'); },
                'Alt-T': cm => { cm.replaceSelection('ț'); },
                'Alt-Shift-T': cm => { cm.replaceSelection('Ț'); },
                'Alt-S': cm => { cm.replaceSelection('Ș'); },
                'Alt-I': cm => { cm.replaceSelection('Î'); },
                'Alt-A': cm => { cm.replaceSelection('â'); },
            });

            // ── SASA code-side interception ──
            // When SASA is active and user types/deletes/pastes in the CODE editor,
            // cancel CodeMirror's default change, manually replace the SASA range,
            // and position the cursor between markers — all in one atomic operation.
            editor.on('beforeChange', (cm, change) => {
                if (!sasaSelectionActive) return;
                const o = change.origin;
                if (o !== '+input' && o !== '+delete' && o !== 'paste' && o !== 'cut') return;
                change.cancel();
                const range = getSasaRange();
                if (!range) { deactivateSasa(); return; }
                // Strip SASA visual highlights from DOM BEFORE saving snapshot
                // so that undo states never contain highlight artefacts.
                clearSasaDesignHighlight();
                // Save the current design state to undo stack BEFORE the SASA edit
                designSaveSnapshot();
                const rawText = (o === '+delete' || o === 'cut') ? '' : change.text.join('\n');
                // Wrap typed/pasted text in <p class="text_obisnuit"><em>…</em></p>
                const insertText = rawText.length > 0 ? wrapTextForSasa(rawText) : '';
                deactivateSasa();
                isSyncFromDesign = true;
                editor.operation(() => {
                    editor.replaceRange(insertText, range.from, range.to);
                    if (insertText.length > 0) {
                        if (o === '+input') {
                            // Typing: position cursor INSIDE <em>, right after
                            // the typed character so continued typing stays
                            // inside the tag.
                            // Wrapped: <p class="text_obisnuit"><em>X</em></p>
                            //           prefix = 28 chars ─────────^
                            const prefix = '<p class="text_obisnuit"><em>';
                            const endPos = {
                                line: range.from.line,
                                ch: range.from.ch + prefix.length + rawText.length
                            };
                            editor.setCursor(endPos);
                            editor.scrollIntoView(endPos, 60);
                        } else {
                            // Paste: position cursor at end of all wrapped content
                            const lines = insertText.split('\n');
                            const endPos = {
                                line: range.from.line + lines.length - 1,
                                ch: lines[lines.length - 1].length
                            };
                            editor.setCursor(endPos);
                            editor.scrollIntoView(endPos, 60);
                        }
                    } else {
                        // Delete/cut: position at start of empty line between markers
                        positionCursorBetweenSasa();
                    }
                });
                isSyncFromDesign = false;
                applyCodeToDesignPanel();
            });

            editor.on('change', () => {
                // Clear design-click highlight when user edits in code
                if (_imgClickMark) { _imgClickMark.clear(); _imgClickMark = null; }
                refreshClassListFromCode();
                // Skip dirty recalculation during tab restore — the tab already has correct isDirty
                if (!_isRestoringTab && activeTabId) {
                    var _t = null;
                    for (var _i = 0; _i < tabs.length; _i++) { if (tabs[_i].id === activeTabId) { _t = tabs[_i]; break; } }
                    if (_t) {
                        var nowDirty = (normalizeHtmlForCompare(editor.getValue()) !== _t.originalContentNorm);
                        if (nowDirty !== _t.isDirty) {
                            _t.isDirty = nowDirty;
                            isDirty = nowDirty;
                            var _el = document.querySelector('.editor-tab[data-tab-id="' + activeTabId + '"]');
                            if (_el) { if (nowDirty) _el.classList.add('dirty'); else _el.classList.remove('dirty'); }
                            if (nowDirty) backupDirtyTabs();
                        }
                    }
                }
                if (isSyncFromDesign) return;
                if (isApplyingUndoRedo) return;
                // Collapse multiple consecutive spaces in code (preserve leading indentation).
                // Runs on every change; uses replaceRange only when something actually changed.
                (function collapseSpacesInCode() {
                    if (_isRestoringTab) return;
                    // Only collapse spaces on the line where the cursor is (preserve indentation elsewhere)
                    const cursor = editor.getCursor();
                    const i = cursor.line;
                    const line = editor.getLine(i);
                    if (!line) return;
                    // Find leading whitespace length to preserve indentation
                    const leadingMatch = line.match(/^(\s*)/);
                    const leadLen = leadingMatch ? leadingMatch[1].length : 0;
                    const afterIndent = line.substring(leadLen);
                    // Only collapse 2+ spaces inside text content (between tags),
                    // not in tag attributes or indentation. Mirrors Notepad++ regex:
                    // \x20{2,}(?=[^<>]*<)  — spaces followed by non-tag chars then <
                    // Also handle trailing text after last > (no < follows):
                    const cleaned = afterIndent
                        .replace(/ {2,}(?=[^<>]*<)/g, ' ')
                        .replace(/(?<=>)([^<]*)$/g, function(m) { return m.replace(/ {2,}/g, ' '); });
                    if (cleaned !== afterIndent) {
                        const cursorCh = cursor.ch;
                        isSyncFromDesign = true; // prevent re-trigger
                        editor.operation(() => {
                            editor.replaceRange(cleaned,
                                { line: i, ch: leadLen },
                                { line: i, ch: leadLen + afterIndent.length });
                        });
                        // Adjust cursor position for removed spaces
                        const diff = afterIndent.length - cleaned.length;
                        const newCh = Math.max(leadLen, cursorCh - diff);
                        editor.setCursor({ line: i, ch: newCh });
                        isSyncFromDesign = false;
                    }
                })();
                if (Date.now() < skipPreviewUpdateUntil) return;
                // If the page previously had a <body> structure but undo/change
                // removed it (or vice-versa), force a full blob reload so CSS
                // and override styles are rebuilt correctly.
                const nowHasBody = /<body\b/i.test(editor.getValue());
                if (nowHasBody !== lastPreviewHadBody) {
                    lastPreviewHadBody = nowHasBody;
                    clearTimeout(previewDebounceTimer);
                    previewDebounceTimer = null;
                    isSyncFromCode = true;
                    updatePreview();
                    setTimeout(() => { isSyncFromCode = false; }, 500);
                    return;
                }
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = setTimeout(() => {
                    const _iframe = document.getElementById('preview');
                    const _doc = _iframe && _iframe.contentDocument;
                    if (isCurrentFileHtml() && _doc && _doc.body) {
                        // Design panel already loaded — inject body directly without disk reload
                        applyCodeToDesignPanel();
                        lastPreviewHadBody = /<body\b/i.test(editor.getValue());
                    } else {
                        isSyncFromCode = true;
                        updatePreview();
                        setTimeout(() => { isSyncFromCode = false; }, 500);
                    }
                }, 500);
            });

            let _isCodeDragging = false;
            editor.getWrapperElement().addEventListener('mousedown', () => {
                _isCodeDragging = true;
                _frDesignWasLastFocused = false; // user clicked in code panel
                // Clear persistent image/icon highlight when user clicks in code
                if (_imgClickMark) { _imgClickMark.clear(); _imgClickMark = null; }
            });
            window.addEventListener('mouseup', () => {
                if (_isCodeDragging) {
                    _isCodeDragging = false;
                    // Trigger sync once drag ends
                    if (editor && !isCursorInsideSasa() && !sasaSelectionActive) {
                        syncSelectionToDesignFromCode();
                    }
                }
            });
            // ── Double/triple-click in code editor: select inner content of tag ──
            // Shared logic: find the tag content surrounding the cursor and highlight it.
            // Returns true if a selection was made, false otherwise.
            function _selectTagContentAtCursor() {
                const cursor = editor.getCursor();
                const src = editor.getValue();
                const cursorIdx = editor.indexFromPos(cursor);

                // ── Strategy 1: Cursor inside a tag — select attribute value between quotes ──
                let tagStart = -1;
                for (let i = cursorIdx; i >= 0; i--) {
                    if (src[i] === '<') { tagStart = i; break; }
                    if (src[i] === '>' && i < cursorIdx) { tagStart = -1; break; }
                }

                if (tagStart !== -1) {
                    const tagEnd = findTagClose(src, tagStart + 1);
                    if (tagEnd !== -1 && cursorIdx <= tagEnd) {
                        let i = tagStart + 1;
                        while (i <= tagEnd) {
                            const ch = src[i];
                            if (ch === '"' || ch === "'") {
                                const quoteChar = ch;
                                const valStart = i + 1;
                                let valEnd = valStart;
                                while (valEnd <= tagEnd && src[valEnd] !== quoteChar) valEnd++;
                                if (cursorIdx >= valStart && cursorIdx <= valEnd) {
                                    const from = editor.posFromIndex(valStart);
                                    const to = editor.posFromIndex(valEnd);
                                    if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
                                    isSelectionFromDesign = true;
                                    editor.setSelection(from, to);
                                    editor.scrollIntoView({ from, to }, 100);
                                    isSelectionFromDesign = false;
                                    syncSelectionMark = editor.markText(from, to, { className: 'cm-selection-highlight' });
                                    return true;
                                }
                                i = valEnd + 1;
                                continue;
                            }
                            i++;
                        }
                        return false; // inside tag but not in attribute value
                    }
                }

                // ── Strategy 2: Cursor between tags — select inner content of enclosing element ──
                const beforeCursor = src.substring(0, cursorIdx);
                const openTagPatterns = [
                    /<p\s+class="text_obisnuit">/gi,
                    /<p\s+class="text_obisnuit2">/gi,
                    /<h1\s+class="den_articol"[^>]*>/gi,
                    /<title>/gi,
                ];
                const closeTagMap = {
                    'p': /<\/p>/gi,
                    'h1': /<\/h1>/gi,
                    'title': /<\/title>/gi,
                };

                let bestMatchEnd = -1;
                let bestTagName = '';

                for (const pattern of openTagPatterns) {
                    pattern.lastIndex = 0;
                    let m;
                    while ((m = pattern.exec(beforeCursor)) !== null) {
                        const matchEnd = m.index + m[0].length;
                        if (matchEnd > bestMatchEnd) {
                            bestMatchEnd = matchEnd;
                            const tn = m[0].match(/^<(\w+)/);
                            bestTagName = tn ? tn[1].toLowerCase() : '';
                        }
                    }
                }

                if (bestMatchEnd !== -1 && bestTagName) {
                    const closePattern = closeTagMap[bestTagName];
                    if (closePattern) {
                        closePattern.lastIndex = 0;
                        const restSrc = src.substring(bestMatchEnd);
                        const closeMatch = closePattern.exec(restSrc);
                        if (closeMatch) {
                            const contentStart = bestMatchEnd;
                            const contentEnd = bestMatchEnd + closeMatch.index;
                            if (cursorIdx >= contentStart && cursorIdx <= contentEnd) {
                                const from = editor.posFromIndex(contentStart);
                                const to = editor.posFromIndex(contentEnd);
                                if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
                                isSelectionFromDesign = true;
                                editor.setSelection(from, to);
                                editor.scrollIntoView({ from, to }, 100);
                                isSelectionFromDesign = false;
                                syncSelectionMark = editor.markText(from, to, { className: 'cm-selection-highlight' });
                                return true;
                            }
                        }
                    }
                }
                return false;
            }

            // Track whether our tag-content selection is active so triple-click
            // doesn't override it with CodeMirror's default line selection.
            let _tagContentSelected = false;

            // Intercept triple-click (and beyond) at mousedown BEFORE CodeMirror
            // processes it, so it cannot select the whole line.
            editor.getWrapperElement().addEventListener('mousedown', (e) => {
                if (e.detail >= 3 && _tagContentSelected) {
                    // Block CodeMirror's line/paragraph selection
                    e.preventDefault();
                    e.stopPropagation();
                    // Re-apply tag content selection (it was set by dblclick)
                    setTimeout(() => { _selectTagContentAtCursor(); }, 0);
                }
            }, true); // capture phase — fires before CodeMirror's handler

            editor.getWrapperElement().addEventListener('dblclick', () => {
                setTimeout(() => {
                    _tagContentSelected = _selectTagContentAtCursor();
                }, 10);
            });
            editor.on('cursorActivity', () => {
                if (isSelectionFromDesign) return;
                if (isSyncFromDesign) return;
                if (isApplyingUndoRedo) return;
                // Always clear design-click highlights when user moves cursor in code
                if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
                if (_imgClickMark) { _imgClickMark.clear(); _imgClickMark = null; }
                _tagContentSelected = false;
                // Daca cursorul este in interiorul zonei SASA, nu incercam sa facem
                // „sync selection” catre Design (altfel se comporta ca un FIND si sare).
                if (isCursorInsideSasa()) return;
                // If the user manually moves the cursor/clicks while SASA is active,
                // deactivate the SASA selection (the initial setSelection inside
                // selectSasaRegion() fires cursorActivity BEFORE sasaSelectionActive
                // is set to true, so this won't interfere with the initial selection).
                if (sasaSelectionActive) deactivateSasa();
                // In afara zonei SASA putem sincroniza selectia spre Design.
                // IMPORTANT: Nu sincronizam cat timp user-ul tine click apasat 
                // pentru a trage o selectie in mod cursiv, altfel scroll-ul 
                // din Design va rupe focus-ul si va arunca selectia din Code-Mirror in jos.
                if (!sasaSelectionActive && !_isCodeDragging) {
                    syncSelectionToDesignFromCode();
                }
            });

            window.addEventListener('keydown', e => {
                if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 's') {
                    e.preventDefault();
                    saveFile();
                }
                // F3 / Shift+F3 — Find Next / Find Prev (only when Find dialog is open)
                if (e.key === 'F3') {
                    const dlg = document.getElementById('findReplaceDialog');
                    if (dlg && dlg.classList.contains('visible')) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (e.shiftKey) findPrev(); else findNext();
                        return;
                    }
                }
                // Ctrl+H — open Find & Replace
                if ((e.ctrlKey || e.metaKey) && (e.key === 'h' || e.key === 'H')) {
                    e.preventDefault();
                    toggleFindReplace();
                }
                // Ctrl+F — open Find & Replace (find mode)
                if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'F') && !e.shiftKey) {
                    e.preventDefault();
                    toggleFindReplace();
                }
                // Escape — close Find & Replace / Diacritics popup if open
                if (e.key === 'Escape') {
                    const diacDlg = document.getElementById('diacPopup');
                    if (diacDlg && diacDlg.classList.contains('visible')) {
                        closeDiacPopup();
                    }
                    const dlg = document.getElementById('findReplaceDialog');
                    if (dlg && dlg.classList.contains('visible')) {
                        closeFindReplace();
                    }
                }
            }, true);
            setViewMode('design');
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

        // Style the class combo options with colors/sizes from the CSS in the preview iframe.
        // Called ONLY after preview is fully loaded (from makeDesignEditable), never during
        // undo/redo/sync flows, to avoid manipulating the iframe body at unsafe times.
        function styleClassOptions() {
            const sel = document.getElementById('propClass');
            if (!sel) return;
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            const win = iframe && iframe.contentWindow;
            if (!doc || !doc.body || !win) return;

            let hiddenSpan = doc.createElement('span');
            hiddenSpan.style.visibility = 'hidden';
            hiddenSpan.style.position = 'absolute';
            hiddenSpan.style.whiteSpace = 'nowrap';
            hiddenSpan.innerHTML = 'Test';
            doc.body.appendChild(hiddenSpan);

            let defaultBg = '#ffffff';
            const bodyComp = win.getComputedStyle(doc.body);
            if (bodyComp && bodyComp.backgroundColor && bodyComp.backgroundColor !== 'rgba(0, 0, 0, 0)' && bodyComp.backgroundColor !== 'transparent') {
                defaultBg = bodyComp.backgroundColor;
            }

            // Style each <option> in the combo
            const options = sel.querySelectorAll('option');
            for (let i = 0; i < options.length; i++) {
                const opt = options[i];
                if (!opt.value) continue; // skip "(fara)"
                hiddenSpan.className = opt.value;
                const comp = win.getComputedStyle(hiddenSpan);
                if (comp) {
                    opt.style.backgroundColor = defaultBg;
                    if (comp.color && comp.color !== 'rgba(0, 0, 0, 0)' && comp.color !== 'transparent') {
                        opt.style.color = comp.color;
                    } else {
                        opt.style.color = '#000000';
                    }
                    if (comp.fontSize) {
                        opt.style.fontSize = comp.fontSize;
                    }
                    if (comp.fontWeight) {
                        opt.style.fontWeight = comp.fontWeight;
                    }
                    if (comp.backgroundColor && comp.backgroundColor !== 'rgba(0, 0, 0, 0)' && comp.backgroundColor !== 'transparent') {
                        opt.style.backgroundColor = comp.backgroundColor;
                    }
                }
            }

            hiddenSpan.parentNode.removeChild(hiddenSpan);
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
            // Check if file is already open in a tab
            var existing = findTabByPath(path);
            if (existing) {
                switchToTab(existing.id);
                hideOverlay();
                return;
            }
            try {
                toast('Se deschide...');
                const res = await fetch('?action=load&file=' + encodeURIComponent(path));
                const data = await res.json();
                if (!data.ok) { toast('Eroare: ' + data.error); return; }

                // Close browser panel if open (switching to a file tab)
                if (browserPanelOpen) {
                    document.getElementById('browserPanel').style.display = 'none';
                    document.getElementById('splitContainer').style.display = '';
                    browserPanelOpen = false;
                    var _bg = document.getElementById('btnBrowserTab');
                    if (_bg) _bg.classList.remove('active');
                }
                // Deactivate CROP and SELECT modes when opening a new file
                if (typeof deactivateSasa === 'function') deactivateSasa();
                if (typeof deactivateCrop === 'function' && typeof cropHighlightActive !== 'undefined' && cropHighlightActive) deactivateCrop();

                // Save current tab state before creating new one
                saveCurrentTabState();

                var label = getTabLabel(data.content, data.file);
                var fullT = getTabFullTitle(data.content, data.file);
                var tab = createTabState({
                    filePath: data.file,
                    fileName: shortName(data.file),
                    tabLabel: label,
                    fullTitle: fullT,
                    editorContent: data.content,
                    originalContent: data.content,
                    lastPreviewHadBody: /<body\b/i.test(data.content)
                });
                tabs.push(tab);
                activeTabId = tab.id;

                currentFile = data.file;
                addToRecentFiles(currentFile);
                _isRestoringTab = true;
                isSyncFromDesign = true;
                editor.setValue(data.content);
                isSyncFromDesign = false;
                _isRestoringTab = false;
                editor.getDoc().clearHistory();
                lastPreviewHadBody = tab.lastPreviewHadBody;
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = null;
                document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
                if (el) el.classList.add('active');
                updatePreview();
                isDirty = false;
                designUndoStack = [];
                designRedoStack = [];
                lastDesignSnapshot = null;

                renderTabs();
                hideOverlay();
            } catch (e) {
                toast('Eroare la deschidere: ' + e.message);
            }
        }

        async function saveFile() {
            if (!editor) return;
            if (!currentFile) {
                const hint =
                    'Acest fisier a fost deschis prin Drag & Drop si nu are o cale cunoscuta pe disc.\n\n' +
                    'Scrie mai jos CALEA COMPLETA unde vrei sa fie salvat,\n' +
                    'de exemplu:\n' +
                    'e:/Carte/BB/17 - Site Leadership/Principal/en/the-feelings-of-an-artist-fascinated-by-the-meticulousness-of-his-passion.html';
                const input = window.prompt(hint, '');
                if (!input) {
                    showErrorPopup(
                        'Salvarea a fost anulata.\n\n' +
                        'Motiv: fisierul nu are o cale cunoscuta pe disc, iar nicio cale noua nu a fost furnizata.\n\n' +
                        'Poti deschide fisierul prin campul de cale din ecranul initial\n' +
                        'sau poti apasa din nou Save si sa introduci o cale completa.');
                    return;
                }
                const trimmed = input.trim();
                if (!trimmed) {
                    showErrorPopup('Calea introdusa este goala. Te rog sa incerci din nou cu o cale completa catre fisier.');
                    return;
                }
                currentFile = trimmed;
            }
            try {
                const body = new URLSearchParams();
                body.append('file', currentFile);
                body.append('content', editor.getValue());
                const res = await fetch('?action=save', { method: 'POST', body });
                const data = await res.json();
                if (!data.ok) { toast('Eroare la salvare: ' + data.error); return; }
                isDirty = false;
                if (activeTabId) {
                    var tab = null;
                    for (var i = 0; i < tabs.length; i++) { if (tabs[i].id === activeTabId) { tab = tabs[i]; break; } }
                    if (tab) {
                        tab.isDirty = false;
                        tab.filePath = currentFile;
                        tab.originalContent = editor.getValue();
                        tab.originalContentNorm = normalizeHtmlForCompare(tab.originalContent);
                        tab.tabLabel = getTabLabel(editor.getValue(), currentFile);
                        tab.fullTitle = getTabFullTitle(editor.getValue(), currentFile);
                        removeTabBackup(tab.id);
                        renderTabs();
                    }
                }
                // Silent save: NO preview reload — avoids page flicker
                // Preview is already up-to-date (changes are synced live)
                toast('Salvat!');
            } catch (e) {
                toast('Eroare la salvare');
            }
        }

        function isCurrentFileHtml() {
            if (!currentFile) return true;
            const n = currentFile.toLowerCase();
            return n.endsWith('.html') || n.endsWith('.htm');
        }

        // Preserve original tag names when the browser normalises equivalent
        // inline tags during contentEditable editing (e.g. <i>→<em>, <b>→<strong>).
        // Compares old body HTML (from code editor) with new innerHTML (from design
        // panel) and restores the original tag variant wherever the browser swapped it.
        function preserveEquivTags(oldBodyContent, newBodyContent) {
            var EQUIV = {'i':'em','em':'i','b':'strong','strong':'b'};
            // Extract all tags with their positions
            function extractTags(html) {
                var result = [];
                var re = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/g;
                var m;
                while ((m = re.exec(html)) !== null) {
                    result.push({
                        index: m.index,
                        length: m[0].length,
                        closing: m[1] === '/',
                        name: m[2].toLowerCase(),
                        attrs: m[3],
                        raw: m[0]
                    });
                }
                return result;
            }
            var oldTags = extractTags(oldBodyContent);
            var newTags = extractTags(newBodyContent);
            // Keep only tags that participate in equiv-pairs for alignment
            var oldEquiv = oldTags.filter(function(t) { return !!EQUIV[t.name]; });
            var newEquiv = newTags.filter(function(t) { return !!EQUIV[t.name]; });
            var replacements = [];
            var oi = 0;
            for (var ni = 0; ni < newEquiv.length && oi < oldEquiv.length; ni++) {
                var nt = newEquiv[ni];
                var ot = oldEquiv[oi];
                // Tags must match in open/close direction and be from the same equiv pair
                if (nt.closing === ot.closing &&
                    (nt.name === ot.name || EQUIV[nt.name] === ot.name)) {
                    if (nt.name !== ot.name) {
                        // Browser swapped the tag name — restore original
                        var prefix = nt.closing ? '</' : '<';
                        replacements.push({
                            index: nt.index,
                            length: nt.raw.length,
                            replacement: prefix + ot.name + nt.attrs + '>'
                        });
                    }
                    oi++;
                }
                // If they don't match at all (different pair, e.g. i vs b) we leave nt
                // as-is and try the next newEquiv against the same oldEquiv position.
            }
            if (replacements.length === 0) return newBodyContent;
            // Apply in reverse so earlier offsets stay valid
            var result = newBodyContent;
            for (var r = replacements.length - 1; r >= 0; r--) {
                var rep = replacements[r];
                result = result.substring(0, rep.index) + rep.replacement +
                         result.substring(rep.index + rep.length);
            }
            return result;
        }

        // Collapse runs of spaces/&nbsp; into a single space ONLY in the text node
        // where the cursor is. Preserves the caret position so the user can keep typing.
        function collapseSpacesInDesignDOM(doc) {
            if (!doc || !doc.body) return;
            const win = doc.defaultView;
            const sel = win && win.getSelection();
            if (!sel || sel.rangeCount === 0 || !sel.isCollapsed) return;
            const cursorNode = sel.getRangeAt(0).startContainer;
            let cursorOffset = sel.getRangeAt(0).startOffset;
            // Only process the text node where the cursor is
            if (!cursorNode || cursorNode.nodeType !== Node.TEXT_NODE) return;
            // Skip <pre>, <code>, <script>, <style> contents
            const parent = cursorNode.parentNode;
            if (parent && /^(PRE|CODE|SCRIPT|STYLE|TEXTAREA)$/i.test(parent.tagName)) return;
            const orig = cursorNode.nodeValue;
            // Replace &nbsp; (\u00A0) with regular space, then collapse 2+ spaces into one
            const cleaned = orig.replace(/[\u00A0]/g, ' ').replace(/ {2,}/g, ' ');
            if (cleaned !== orig) {
                // Adjust cursor offset: count how many chars were removed before cursor
                if (cursorOffset > 0) {
                    let newOffset = 0, origIdx = 0;
                    for (let ci = 0; ci < cleaned.length && origIdx < cursorOffset; ci++) {
                        while (origIdx < orig.length && origIdx < cursorOffset &&
                               (orig[origIdx] === ' ' || orig[origIdx] === '\u00A0') &&
                               ci < cleaned.length && cleaned[ci] === ' ' &&
                               origIdx + 1 < orig.length &&
                               (orig[origIdx + 1] === ' ' || orig[origIdx + 1] === '\u00A0')) {
                            origIdx++;
                        }
                        origIdx++;
                        newOffset = ci + 1;
                    }
                    cursorOffset = Math.min(newOffset, cleaned.length);
                }
                cursorNode.nodeValue = cleaned;
                // Restore caret
                try {
                    const safeOffset = Math.min(cursorOffset, cursorNode.nodeValue.length);
                    const range = doc.createRange();
                    range.setStart(cursorNode, safeOffset);
                    range.setEnd(cursorNode, safeOffset);
                    sel.removeAllRanges();
                    sel.addRange(range);
                } catch (e) {}
            }
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
            // Remove CROP highlight from DOM before reading so it never persists to code
            clearSasaDesignHighlight(doc);
            // Replace &nbsp; with regular space in SASA region and h1.den_articol
            var rawInner = cleanNbspInHtml(doc.body.innerHTML);
            // Remove data-orig-class helper attribute so it never persists to saved code
            rawInner = rawInner.replace(/\s*data-orig-class="[^"]*"/gi, '');
            // Note: space collapsing is handled per-node in collapseSpacesInDesignDOM()
            // to avoid destroying indentation in the code editor.
            // Ensure line breaks between block-level elements (Design outputs them on one line)
            rawInner = rawInner.replace(/(<\/(?:p|div|h[1-6]|ul|ol|li|table|tr|td|th|thead|tbody|blockquote|section|article|header|footer|nav|aside|figure|figcaption|hr|br|pre|dl|dt|dd)>)(<)/gi, '$1\n$2');
            // Preserve original tag names (i vs em, b vs strong) from the code editor
            // so that browser normalisation does not silently rename them.
            var oldBodyInner = bodyMatch[0].replace(/^<body[^>]*>/i, '').replace(/<\/body>$/i, '');
            rawInner = preserveEquivTags(oldBodyInner, rawInner);
            const newBody = '<body' + bodyMatch[1] + '>' + rawInner + '</body>';
            // Only update code if the body actually changed (avoids false dirty on select/copy)
            if (newBody === bodyMatch[0]) {
                // Re-apply CROP highlight (we cleared it above for clean reading)
                if (cropHighlightActive) highlightSasaInDesign('crop');
                return;
            }
            isSyncFromDesign = true;
            const from = editor.posFromIndex(bodyMatch.index);
            const to = editor.posFromIndex(bodyMatch.index + bodyMatch[0].length);
            editor.replaceRange(newBody, from, to);
            isSyncFromDesign = false;
            // Recalculate dirty state by comparing to originalContent
            // (so that undoing all changes correctly clears the dirty star)
            if (activeTabId) {
                var _t = null;
                for (var _i = 0; _i < tabs.length; _i++) { if (tabs[_i].id === activeTabId) { _t = tabs[_i]; break; } }
                if (_t) {
                    var nowDirty = (normalizeHtmlForCompare(editor.getValue()) !== _t.originalContentNorm);
                    _t.isDirty = nowDirty;
                    isDirty = nowDirty;
                    var _el = document.querySelector('.editor-tab[data-tab-id="' + activeTabId + '"]');
                    if (_el) { if (nowDirty) _el.classList.add('dirty'); else _el.classList.remove('dirty'); }
                } else {
                    isDirty = true;
                }
            } else {
                isDirty = true;
            }
            syncSelectionToCodeFromDesign();
            // Re-apply CROP highlight visually (it was just stripped for clean sync)
            if (cropHighlightActive) highlightSasaInDesign('crop');
        }

        function makeDesignEditable() {
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body || !isCurrentFileHtml()) return;
            doc.body.contentEditable = 'true';
            // Track that design panel was last focused (for Find scope in split mode)
            doc.addEventListener('mousedown', () => { _frDesignWasLastFocused = true; });
            // Block text drag-and-drop in design panel (prevents accidental moves)
            doc.addEventListener('dragstart', function (e) { e.preventDefault(); });
            doc.addEventListener('drop', function (e) { e.preventDefault(); });

            // If the code editor has unsaved changes that differ from the physical file we just
            // loaded into the iframe (e.g., restoring a dirty tab on refresh), apply them now!
            // This prevents losing edits when reopening a dirty tab or rebuilding the preview.
            if (isDirty) {
                applyCodeToDesignPanel();
            } else {
                // Initialize the custom undo/redo stack for this freshly-loaded clean page.
                designResetUndoStack();
            }

            // Chromium on Windows can fire a spurious 'input' event immediately after
            // a clipboard copy (Ctrl+C) on contentEditable elements, even though no
            // content was actually modified. Suppress any 'input' that arrives within
            // 150ms of a 'copy' event so it never triggers a false dirty state.
            let _suppressInputAfterCopy = false;
            // Track when a Ctrl/Meta combo keydown happens. On keyup, the user may
            // have already released Ctrl, making e.ctrlKey false. Without this
            // tracking, the keyup handler would erroneously trigger syncFromDesign
            // for non-modifying shortcuts (Ctrl+C, Ctrl+A, etc.), which then detects
            // browser HTML normalization differences as real content changes.
            let _lastCtrlComboTime = 0;
            doc.addEventListener('keydown', (e) => {
                if (e.ctrlKey || e.metaKey) _lastCtrlComboTime = Date.now();
                // Intercept Ctrl+F / Ctrl+H in Design panel to open app's Find & Replace
                if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'F') && !e.shiftKey) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.parent.toggleFindReplace ? window.parent.toggleFindReplace() : toggleFindReplace();
                }
                if ((e.ctrlKey || e.metaKey) && (e.key === 'h' || e.key === 'H')) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.parent.toggleFindReplace ? window.parent.toggleFindReplace() : toggleFindReplace();
                }
                // F3 / Shift+F3 — Find Next / Find Prev from design panel
                if (e.key === 'F3') {
                    const dlg = document.getElementById('findReplaceDialog');
                    if (dlg && dlg.classList.contains('visible')) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (e.shiftKey) findPrev(); else findNext();
                        return;
                    }
                }
                // Escape — close Find & Replace from design panel
                if (e.key === 'Escape') {
                    const dlg = document.getElementById('findReplaceDialog');
                    if (dlg && dlg.classList.contains('visible')) {
                        closeFindReplace();
                    }
                }
            });
            doc.addEventListener('copy', () => {
                _suppressInputAfterCopy = true;
                setTimeout(() => { _suppressInputAfterCopy = false; }, 150);
            });
            doc.addEventListener('paste', (e) => {
                // Force copy-paste to insert as plain text so we don't bring in
                // inline styles, spans, or classes from external sources (e.g. Google Translate).
                // We use direct text-node manipulation instead of execCommand('insertText')
                // because execCommand can destroy parent DOM structure (tags, classes,
                // links) when replacing the entire content of an element.
                e.preventDefault();
                let text = (e.clipboardData || window.clipboardData).getData('text/plain');
                if (!text) return;
                // Collapse multiple consecutive spaces into one
                text = text.replace(/ {2,}/g, ' ');
                designSaveSnapshot();
                const win = iframe.contentWindow;
                const sel = win && win.getSelection();
                if (!sel || sel.rangeCount === 0) return;
                const range = sel.getRangeAt(0);

                // Case 1: Selection within a single text node — directly modify nodeValue.
                // This is the most common case (e.g., selecting "Servus Litterae" inside
                // <a class="linkMare">Servus Litterae</a>) and guarantees all parent
                // elements (tags, classes, href) are fully preserved.
                if (range.startContainer === range.endContainer &&
                    range.startContainer.nodeType === Node.TEXT_NODE) {
                    const node = range.startContainer;
                    const before = node.nodeValue.substring(0, range.startOffset);
                    const after = node.nodeValue.substring(range.endOffset);
                    node.nodeValue = before + text + after;
                    // Position caret after inserted text
                    const newOffset = before.length + text.length;
                    const newRange = doc.createRange();
                    newRange.setStart(node, newOffset);
                    newRange.setEnd(node, newOffset);
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                }
                // Case 2: Element-level or cross-node selection — use deleteContents
                // + insertNode. deleteContents preserves parent structure (just empties
                // selected content), unlike execCommand which restructures the DOM.
                else {
                    range.deleteContents();
                    const textNode = doc.createTextNode(text);
                    range.insertNode(textNode);
                    // Merge adjacent text nodes so we don't leave fragments
                    if (textNode.parentNode) textNode.parentNode.normalize();
                    const newRange = doc.createRange();
                    newRange.setStartAfter(textNode);
                    newRange.setEndAfter(textNode);
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                }
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = setTimeout(syncFromDesign, 80);
            });
            doc.body.addEventListener('input', () => {
                if (isApplyingUndoRedo) return;
                if (_suppressInputAfterCopy) return;
                // Clear design-click highlight when user edits in design
                if (_imgClickMark) { _imgClickMark.clear(); _imgClickMark = null; }
                // Collapse multiple spaces in design DOM text nodes (real-time cleanup)
                collapseSpacesInDesignDOM(doc);
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = setTimeout(syncFromDesign, 80);
            });
            doc.body.addEventListener('keyup', (e) => {
                if (isApplyingUndoRedo) return;
                // Suppress keyup events from Ctrl/Meta combos (Ctrl+C, Ctrl+A, etc.).
                // The user may release Ctrl before the letter key, so e.ctrlKey can be
                // false on keyup. We use a 300ms window from the last Ctrl keydown.
                // Content-modifying combos (Ctrl+V, Ctrl+X, Ctrl+B) already fire the
                // 'input' event above, so the keyup safety-net is not needed for them.
                if (e.ctrlKey || e.metaKey) return;
                if (Date.now() - _lastCtrlComboTime < 300) return;
                if (e.key === 'PrintScreen' || e.key === 'Escape' ||
                    e.key === 'Tab' || e.key === 'CapsLock' ||
                    e.key === 'NumLock' || e.key === 'ScrollLock' || e.key === 'Pause' ||
                    e.key === 'Insert' || /^F\d+$/.test(e.key) ||
                    e.key === 'ArrowLeft' || e.key === 'ArrowRight' ||
                    e.key === 'ArrowUp' || e.key === 'ArrowDown' ||
                    e.key === 'Home' || e.key === 'End' ||
                    e.key === 'PageUp' || e.key === 'PageDown' ||
                    e.key === 'Shift' || e.key === 'Control' ||
                    e.key === 'Alt' || e.key === 'Meta') return;
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
            // ── Intercept mousedown on images inside anchors so we can
            //    select the <img> before the browser's default behaviour. ──
            doc.addEventListener('mousedown', e => {
                const target = e.target;
                if (!target || target.tagName !== 'IMG') return;
                const anchor = target.closest ? target.closest('a') : null;
                if (!anchor) return;
                // Prevent the browser's default mousedown on the anchor (which
                // would place the caret in the surrounding text, not on the image).
                e.preventDefault();
                e.stopPropagation();
                // Manually select the <img> element
                const iframeWin = document.getElementById('preview').contentWindow;
                try {
                    const r = doc.createRange();
                    r.selectNode(target);
                    const s = iframeWin.getSelection();
                    s.removeAllRanges();
                    s.addRange(r);
                } catch (_) {}
                // Sync to code editor immediately
                if (typeof syncClickedElementToCode === 'function') syncClickedElementToCode(target);
                // Delay properties update slightly so the selection settles
                setTimeout(updatePropertiesPanelFromSelection, 10);
            }, true);
            // Prevent link navigation on click; handle non-anchor clicks
            doc.addEventListener('click', e => {
                const target = e.target;
                if (!target || target === doc.body) return;
                const anchor = target.closest ? target.closest('a') : (target.tagName === 'A' ? target : null);
                if (anchor) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Images inside anchors are fully handled by the mousedown handler;
                    // for text links, sync the clicked element to code here.
                    if (target.tagName !== 'IMG') {
                        // If the user has drag-selected text inside the link, let the
                        // selection-based sync handle it — it highlights only the text
                        // portion in the code, not the surrounding <a> tags and URL.
                        const _iframeWin = document.getElementById('preview').contentWindow;
                        const _curSel = _iframeWin && _iframeWin.getSelection();
                        if (_curSel && !_curSel.isCollapsed) return;
                        if (typeof syncClickedElementToCode === 'function') syncClickedElementToCode(target);
                    }
                    return;
                }
                // If the user has drag-selected text, don't override the selection
                // with a cursor-only sync. The selectionchange/mouseup handlers already
                // highlighted the selected text in the code editor.
                const iframeWin = document.getElementById('preview').contentWindow;
                const curSel = iframeWin && iframeWin.getSelection();
                if (curSel && !curSel.isCollapsed) return;
                // Sync any clicked element to its position in the source code
                if (typeof syncClickedElementToCode === 'function') syncClickedElementToCode(target);
            }, true);
            doc.addEventListener('keydown', e => {
                // ── SASA selection intercept for DESIGN panel ──
                // When SASA is active and user types/deletes in design, the visual
                // highlight is NOT a real browser selection so contentEditable can't
                // handle it. We intercept the key, perform the edit in the CODE
                // editor, then sync back to design.
                if (sasaSelectionActive) {
                    const isPrintable = e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey;
                    const isDelete = (e.key === 'Delete' || e.key === 'Backspace');
                    const isPaste = (e.ctrlKey || e.metaKey) && (e.key === 'v' || e.key === 'V');
                    if (isPrintable || isDelete || isPaste) {
                        e.preventDefault();
                        e.stopPropagation();
                        const doSasaEdit = (rawText) => {
                            const range = getSasaRange();
                            if (!range) { deactivateSasa(); return; }
                            // Strip SASA visual highlights from DOM BEFORE saving snapshot
                            clearSasaDesignHighlight(doc);
                            // Save the current design state to undo stack BEFORE the SASA edit
                            designSaveSnapshot();
                            // Wrap typed/pasted text in <p class="text_obisnuit"><em>…</em></p>
                            const insertText = rawText.length > 0 ? wrapTextForSasa(rawText) : '';
                            deactivateSasa();
                            isSyncFromDesign = true;
                            editor.replaceRange(insertText, range.from, range.to);
                            isSyncFromDesign = false;
                            applyCodeToDesignPanel();
                            // Position the CODE cursor between SASA markers
                            // (for when user switches to code/split later).
                            positionCursorBetweenSasa();
                            if (viewMode === 'design') {
                                // Position the DESIGN caret inside the last <em>
                                // between SASA markers so subsequent typing stays
                                // inside the formatted paragraph.
                                positionDesignCaretInSasaEm();
                                doc.body.focus();
                            } else {
                                editor.focus();
                            }
                            // Block any syncFromDesign triggered by the keyup debounce
                            isSyncFromCode = true;
                            setTimeout(() => { isSyncFromCode = false; }, 200);
                        };
                        if (isPaste) {
                            (navigator.clipboard && navigator.clipboard.readText
                                ? navigator.clipboard.readText()
                                : Promise.resolve('')
                            ).then(clipText => doSasaEdit(clipText || ''))
                                .catch(() => deactivateSasa());
                        } else {
                            doSasaEdit(isPrintable ? e.key : '');
                        }
                        return;
                    }
                }
                // ── Enter key → insert <p class="text_obisnuit"> instead of <br> ──
                if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                    e.preventDefault();
                    e.stopPropagation();
                    designSaveSnapshot();
                    const win = iframe.contentWindow;
                    const sel = win && win.getSelection();
                    if (sel && sel.rangeCount > 0) {
                        const range = sel.getRangeAt(0);
                        range.deleteContents();
                        // Create new <p class="text_obisnuit"> with a zero-width space
                        // so the caret can be placed inside it
                        const newP = doc.createElement('p');
                        newP.className = 'text_obisnuit';
                        newP.appendChild(doc.createElement('br'));
                        // Insert the new paragraph after the current block
                        // Find the closest block-level parent
                        let block = range.startContainer;
                        if (block.nodeType === Node.TEXT_NODE) block = block.parentNode;
                        while (block && block !== doc.body &&
                            !/^(P|DIV|H[1-6]|BLOCKQUOTE|LI|SECTION|ARTICLE|HEADER|FOOTER|MAIN|TABLE|UL|OL|PRE|FIGURE|FIGCAPTION|NAV|ASIDE|DETAILS|SUMMARY|FORM|FIELDSET|ADDRESS|HGROUP|SEARCH|DL|DT|DD)$/i.test(block.tagName)) {
                            block = block.parentNode;
                        }
                        if (block && block !== doc.body) {
                            // Split: move content after cursor into the new paragraph
                            const afterRange = doc.createRange();
                            afterRange.setStart(range.startContainer, range.startOffset);
                            afterRange.setEnd(block, block.childNodes.length);
                            const afterContents = afterRange.extractContents();
                            // Check if afterContents has real content
                            const hasContent = afterContents.textContent.trim().length > 0 ||
                                afterContents.querySelector('img, br, hr, input, svg, video, audio, iframe, canvas, embed, object');
                            if (hasContent) {
                                // Move trailing content into the new <p>
                                newP.innerHTML = '';
                                newP.appendChild(afterContents);
                                // If the new p ends up empty after extraction, add <br>
                                if (!newP.textContent.trim() && !newP.querySelector('img, br, hr')) {
                                    newP.appendChild(doc.createElement('br'));
                                }
                            }
                            // If original block is now empty, add <br> placeholder
                            if (!block.textContent.trim() && !block.querySelector('img, br, hr')) {
                                block.appendChild(doc.createElement('br'));
                            }
                            block.parentNode.insertBefore(newP, block.nextSibling);
                        } else {
                            // Fallback: just insert at cursor position
                            range.insertNode(newP);
                        }
                        // Place cursor at start of the new paragraph
                        const newRange = doc.createRange();
                        newRange.setStart(newP, 0);
                        newRange.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(newRange);
                        // Scroll the new paragraph into view
                        newP.scrollIntoView({ block: 'nearest' });
                    }
                    clearTimeout(designInputDebounceTimer);
                    designInputDebounceTimer = setTimeout(syncFromDesign, 80);
                    return;
                }
                // Diacritice românești
                let _diac = null;
                if (e.ctrlKey && !e.altKey && !e.metaKey) {
                    if (!e.shiftKey && e.key === 'a') _diac = 'ă';
                    else if (!e.shiftKey && e.key === 'i') _diac = 'î';
                    else if (e.shiftKey && e.key === 'S') _diac = 'ș';
                } else if (e.altKey && !e.ctrlKey && !e.metaKey) {
                    if (!e.shiftKey && e.key === 't') _diac = 'ț';
                    else if (e.shiftKey && e.key === 'T') _diac = 'Ț';
                    else if (!e.shiftKey && e.key === 's') _diac = 'Ș';
                    else if (!e.shiftKey && e.key === 'i') _diac = 'Î';
                    else if (!e.shiftKey && e.key === 'a') _diac = 'â';
                }
                if (_diac) {
                    e.preventDefault();
                    e.stopPropagation();
                    doc.execCommand('insertText', false, _diac);
                    return;
                }
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
            // Re-apply CROP highlight after iframe reload (if active)
            if (cropHighlightActive) highlightSasaInDesign('crop');
            // Populate class list and apply CSS styling to combo options
            // (safe here because iframe is stable and fully loaded)
            refreshClassListFromCode();
            styleClassOptions();
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
            // Re-apply CROP highlight if active (innerHTML rebuild destroys styles)
            if (cropHighlightActive) highlightSasaInDesign('crop');
        }

        function doUndoFromDesign() {
            if (!editor) return;
            // In Code view (sau pentru fisiere non-HTML), folosim undo-ul nativ al editorului de cod.
            if (!isCurrentFileHtml() || viewMode === 'code') {
                try { editor.undo(); } catch (e) { }
                return;
            }
            const iframe = document.getElementById('preview');
            if (!iframe || !iframe.contentDocument || !iframe.contentDocument.body) return;
            // Flush any pending design→code sync so CodeMirror is in sync
            flushPendingDesignSync();
            // Capture the very latest state (in case typing hasn't been snapshot-ted yet)
            designSaveSnapshot();
            if (designUndoStack.length === 0) return;
            cancelPreviewUpdateForUndoRedo();
            // Push current state to redo (with current scroll position)
            const current = getDesignBodyHtml();
            if (current !== null) designRedoStack.push({ html: current, scrollY: getDesignScrollY() });
            // Pop previous state (object with html + scrollY)
            const prevEntry = designUndoStack.pop();
            const prev = prevEntry.html;
            const restoreScrollY = prevEntry.scrollY;
            // Apply to iframe
            const win = iframe.contentWindow;
            isApplyingUndoRedo = true;
            isSyncFromCode = true;
            iframe.contentDocument.body.innerHTML = prev;
            // Scroll to the position where this state was originally saved
            if (win) {
                win.scrollTo(0, restoreScrollY);
                // Retry after layout settles (images/CSS may shift content)
                setTimeout(() => { try { win.scrollTo(0, restoreScrollY); } catch(e){} }, 50);
            }
            isSyncFromCode = false;
            isApplyingUndoRedo = false;
            // Snapshot the EXACT normalized browser state so the 500ms timer doesn't falsely detect changes
            lastDesignSnapshot = getDesignBodyHtml();
            // Sync the reverted body back to the code editor.
            // If the undo stack is now empty, we're back at the initial state.
            // Restore the exact original code (designCleanCode) instead of using
            // syncFromDesign, because the browser serializes innerHTML differently
            // from the original source, which would make the dirty check fail.
            if (designUndoStack.length === 0 && designCleanCode !== null) {
                // We've undone ALL design changes — restore exact original code.
                // Use setValue for guaranteed exact restoration (replaceRange can have
                // subtle trailing-newline issues that prevent content from matching).
                isSyncFromDesign = true;
                _isRestoringTab = true;
                editor.setValue(designCleanCode);
                _isRestoringTab = false;
                isSyncFromDesign = false;
                // Force dirty=false: designCleanCode was captured at file load time,
                // so restoring it means the file is at its original saved state.
                isDirty = false;
                if (activeTabId) {
                    for (var _i = 0; _i < tabs.length; _i++) {
                        if (tabs[_i].id === activeTabId) {
                            tabs[_i].isDirty = false;
                            break;
                        }
                    }
                }
                renderTabs();
            } else {
                syncFromDesign();
                // Check if body matches the initial browser-serialized state
                // (handles cases where the stack didn't fully empty but content is back to original)
                if (designCleanBodyHtml !== null && designCleanCode !== null) {
                    const currentBody = getDesignBodyHtml();
                    if (currentBody === designCleanBodyHtml) {
                        isSyncFromDesign = true;
                        _isRestoringTab = true;
                        editor.setValue(designCleanCode);
                        _isRestoringTab = false;
                        isSyncFromDesign = false;
                        isDirty = false;
                        if (activeTabId) {
                            for (var _i2 = 0; _i2 < tabs.length; _i2++) {
                                if (tabs[_i2].id === activeTabId) {
                                    tabs[_i2].isDirty = false;
                                    break;
                                }
                            }
                        }
                        renderTabs();
                    }
                }
            }
            // Re-apply CROP highlight after undo (innerHTML rebuild destroys styles)
            if (cropHighlightActive) highlightSasaInDesign('crop');
            // Sync code editor scroll to match the design panel position
            syncCodeScrollToDesignScroll(restoreScrollY);
            focusDesignPanel(iframe);
        }

        function doRedoFromDesign() {
            if (!editor) return;
            // In Code view (sau pentru fisiere non-HTML), folosim redo-ul nativ al editorului de cod.
            if (!isCurrentFileHtml() || viewMode === 'code') {
                try { editor.redo(); } catch (e) { }
                return;
            }
            const iframe = document.getElementById('preview');
            if (!iframe || !iframe.contentDocument || !iframe.contentDocument.body) return;
            flushPendingDesignSync();
            if (designRedoStack.length === 0) return;
            cancelPreviewUpdateForUndoRedo();
            // Push current state to undo (with current scroll position)
            const current = getDesignBodyHtml();
            if (current !== null) {
                designUndoStack.push({ html: current, scrollY: getDesignScrollY() });
                if (designUndoStack.length > 1000) designUndoStack.shift();
            }
            // Pop next state from redo (object with html + scrollY)
            const nextEntry = designRedoStack.pop();
            const next = nextEntry.html;
            const restoreScrollY = nextEntry.scrollY;
            // Apply to iframe
            const win = iframe.contentWindow;
            isApplyingUndoRedo = true;
            isSyncFromCode = true;
            iframe.contentDocument.body.innerHTML = next;
            // Scroll to the position where this state was originally saved
            if (win) {
                win.scrollTo(0, restoreScrollY);
                setTimeout(() => { try { win.scrollTo(0, restoreScrollY); } catch(e){} }, 50);
            }
            isSyncFromCode = false;
            isApplyingUndoRedo = false;
            // Snapshot the EXACT normalized browser state so the 500ms timer doesn't falsely detect changes
            lastDesignSnapshot = getDesignBodyHtml();
            // Sync the redo-applied body back to the code editor
            syncFromDesign();
            // Force dirty state recalculation and tab update after redo
            if (activeTabId) {
                var _t = null;
                for (var _i = 0; _i < tabs.length; _i++) { if (tabs[_i].id === activeTabId) { _t = tabs[_i]; break; } }
                if (_t) {
                    var nowDirty = (normalizeHtmlForCompare(editor.getValue()) !== _t.originalContentNorm);
                    // Also check against initial browser-serialized body
                    if (nowDirty && designCleanBodyHtml !== null && designCleanCode !== null) {
                        const currentBody = getDesignBodyHtml();
                        if (currentBody === designCleanBodyHtml) {
                            isSyncFromDesign = true;
                            _isRestoringTab = true;
                            editor.setValue(designCleanCode);
                            _isRestoringTab = false;
                            isSyncFromDesign = false;
                            nowDirty = false;
                        }
                    }
                    _t.isDirty = nowDirty;
                    isDirty = nowDirty;
                    renderTabs();
                }
            }
            // Re-apply CROP highlight after redo (innerHTML rebuild destroys styles)
            if (cropHighlightActive) highlightSasaInDesign('crop');
            // Sync code editor scroll to match the design panel position
            syncCodeScrollToDesignScroll(restoreScrollY);
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

        // When set, updatePreview will restore this scroll position after reload.
        var _pendingPreviewScroll = null;

        function updatePreview() {
            const iframe = document.getElementById('preview');

            // Files with a known path on disk → use PHP preview.
            // PHP strips scripts/loaders, rebuilds clean HTML, and serves it
            // as a normal HTTP response so relative CSS/images resolve correctly
            // (blob: URLs have issues with "preferred" stylesheets and encoded paths).
            if (currentFile) {
                let done = false;
                const scrollToRestore = _pendingPreviewScroll;
                _pendingPreviewScroll = null;
                const doEdit = () => {
                    if (done) return;
                    done = true;
                    if (isCurrentFileHtml()) makeDesignEditable();
                    // Dacă un tab de browser este deschis, ne asigurăm că iframe-ul lui rămâne pe URL-ul curent din tab
                    const frame = document.getElementById('browserFrame');
                    if (frame) {
                        for (var i = 0; i < tabs.length; i++) {
                            if (tabs[i].id === activeTabId && tabs[i].type === 'browser' && tabs[i].browserUrl) {
                                frame.src = buildProxyUrl(tabs[i].browserUrl);
                                break;
                            }
                        }
                    }
                    if (scrollToRestore && iframe.contentWindow) {
                        iframe.contentWindow.scrollTo(scrollToRestore.x, scrollToRestore.y);
                        // Retry after a short delay in case layout shifts from late-loading CSS
                        setTimeout(() => {
                            if (iframe.contentWindow) iframe.contentWindow.scrollTo(scrollToRestore.x, scrollToRestore.y);
                        }, 50);
                    }
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
                + 'img{cursor:default!important}'
                + 'a img{cursor:pointer!important}'
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
            const scrollToRestore2 = _pendingPreviewScroll;
            _pendingPreviewScroll = null;
            iframe.onload = () => {
                makeDesignEditable();
                if (scrollToRestore2 && iframe.contentWindow) {
                    iframe.contentWindow.scrollTo(scrollToRestore2.x, scrollToRestore2.y);
                }
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
            // Check if exactly one element node is selected (e.g. an <img> via selectNode)
            const range = sel.getRangeAt(0);
            if (range && range.startContainer === range.endContainer
                && range.startContainer.nodeType === 1
                && range.endOffset - range.startOffset === 1) {
                const child = range.startContainer.childNodes[range.startOffset];
                if (child && child.nodeType === 1 && child.tagName === 'IMG') return child;
            }
            let node = sel.anchorNode;
            if (!node) return null;
            if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
            if (!node || !node.closest) return node;
            return node.closest('img, p, span, div, td, th, li, h1, h2, h3, h4, h5, h6, a, strong, em');
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

            // ── Build a tag-stripped plain-text of bodyHTML with position map ──
            // posMap[i] = { start, end } — source indices of the i-th plain char.
            // This lets us search for the needle in pure text and then map back
            // to the source code, correctly handling selections that span across
            // HTML tags (e.g. <span class="A">word</span> other text).
            const posMap = [];
            let plainBuf = '';
            let _inTag = false;
            for (let i = 0; i < bodyHTML.length; i++) {
                if (bodyHTML[i] === '<') { _inTag = true; continue; }
                if (bodyHTML[i] === '>') { _inTag = false; continue; }
                if (!_inTag) {
                    let srcEnd = i;
                    if (bodyHTML[i] === '&') {
                        const entityEnd = bodyHTML.indexOf(';', i);
                        if (entityEnd !== -1 && entityEnd - i < 10) {
                            srcEnd = entityEnd;
                            const tmp = document.createElement('span');
                            tmp.innerHTML = bodyHTML.substring(i, entityEnd + 1);
                            plainBuf += (tmp.textContent || tmp.innerText || '?');
                            posMap.push({ start: i, end: entityEnd });
                            i = entityEnd;
                            continue;
                        }
                    }
                    plainBuf += bodyHTML[i];
                    posMap.push({ start: i, end: srcEnd });
                }
            }
            const plainLower = plainBuf.toLowerCase();

            // ── Find all occurrences of needle in the plain text ──
            const matches = [];
            let sFrom = 0;
            while (true) {
                const idx = plainLower.indexOf(needle, sFrom);
                if (idx === -1) break;
                matches.push(idx);
                sFrom = idx + 1;
            }
            if (matches.length === 0) return;

            // ── Pick the best match using designTextOffset for disambiguation ──
            const designTextOffset = getDesignSelectionTextOffset();
            let bestIdx = matches[0];
            if (matches.length > 1 && designTextOffset >= 0) {
                let bestDist = Math.abs(matches[0] - designTextOffset);
                for (let m = 1; m < matches.length; m++) {
                    const dist = Math.abs(matches[m] - designTextOffset);
                    if (dist < bestDist) { bestDist = dist; bestIdx = matches[m]; }
                }
            }

            // ── Map back to source positions and highlight ──
            if (bestIdx + needle.length - 1 < posMap.length) {
                const fStart = posMap[bestIdx].start;
                const fEnd = posMap[bestIdx + needle.length - 1].end;
                highlightSelectionInCode(bodyStartIdx + fStart, fEnd - fStart + 1);
            }
        }

        // Move the CodeMirror selection to [globalIdx, globalIdx+len] and apply
        // a yellow markText so the selected design text is clearly visible in the code.
        function highlightSelectionInCode(globalIdx, len) {
            const from = editor.posFromIndex(globalIdx);
            const to = editor.posFromIndex(globalIdx + len);
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
            const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
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
                const BLOCK = new Set(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'td', 'th', 'tr', 'section', 'article', 'blockquote', 'pre', 'figure', 'header', 'footer', 'nav', 'main']);
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
                let highlightStartIdx = bodyStartIdx + idx;
                let highlightEndIdx = highlightStartIdx;
                const srcAfter = src.substring(highlightStartIdx);
                // Determine highlight range: just the specific tag, not the whole line.
                if (tagName === 'img') {
                    // Check if <img> is wrapped in <a> — highlight the <a>…</a> block
                    const parentA = el.parentElement;
                    if (parentA && parentA.tagName === 'A') {
                        const hrefAttr = parentA.getAttribute('href') || '';
                        if (hrefAttr) {
                            const esc = hrefAttr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                            const aMatch = new RegExp('<a\\b[^>]*\\bhref=["\']?' + esc + '[^>]*>[\\s\\S]*?</a>', 'i').exec(src.substring(bodyStartIdx));
                            if (aMatch) {
                                highlightStartIdx = bodyStartIdx + aMatch.index;
                                highlightEndIdx = highlightStartIdx + aMatch[0].length;
                            } else {
                                const imgClose = srcAfter.indexOf('>');
                                highlightEndIdx = highlightStartIdx + (imgClose !== -1 ? imgClose + 1 : 50);
                            }
                        } else {
                            const imgClose = srcAfter.indexOf('>');
                            highlightEndIdx = highlightStartIdx + (imgClose !== -1 ? imgClose + 1 : 50);
                        }
                    } else {
                        const imgClose = srcAfter.indexOf('>');
                        highlightEndIdx = highlightStartIdx + (imgClose !== -1 ? imgClose + 1 : 50);
                    }
                } else {
                    const closeTag = srcAfter.indexOf('>');
                    highlightEndIdx = highlightStartIdx + (closeTag !== -1 ? closeTag + 1 : 50);
                }
                const from = editor.posFromIndex(highlightStartIdx);
                const to = editor.posFromIndex(highlightEndIdx);
                // Clear any previous highlights before setting cursor
                if (syncSelectionMark) { syncSelectionMark.clear(); syncSelectionMark = null; }
                if (_imgClickMark) { _imgClickMark.clear(); _imgClickMark = null; }
                isSelectionFromDesign = true;
                editor.setCursor(from);
                editor.scrollIntoView(from, 100);
                isSelectionFromDesign = false;
                // Highlight the element — stays until user clicks in code or edits in design
                _imgClickMark = editor.markText(from, to, { className: 'cm-sync-highlight' });
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

            // Find the text node at this offset in the design panel (case-sensitive match)
            const target = selText;
            const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, null);
            let currentOffset = 0;
            let tNode;
            let bestNode = null, bestStart = 0;
            while ((tNode = walker.nextNode())) {
                const nodeLen = tNode.nodeValue.length;
                // Check if our target offset falls within or near this text node
                if (currentOffset + nodeLen > textOffset - selText.length || currentOffset >= textOffset - selText.length) {
                    // Search for the needle in this text node (case-sensitive)
                    let searchFrom = 0;
                    // If the target offset is within this node, start searching near that position
                    if (textOffset >= currentOffset && textOffset < currentOffset + nodeLen) {
                        searchFrom = Math.max(0, textOffset - currentOffset - selText.length);
                    }
                    const idx = tNode.nodeValue.indexOf(target, searchFrom);
                    if (idx !== -1) {
                        bestNode = tNode;
                        bestStart = idx;
                        break;
                    }
                }
                currentOffset += nodeLen;
            }

            if (!bestNode) {
                // Fallback: search all text nodes for first case-sensitive match
                const walker2 = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, {
                    acceptNode(node) {
                        if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                        return node.nodeValue.includes(target) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
                    }
                });
                bestNode = walker2.nextNode();
                if (!bestNode) return;
                bestStart = bestNode.nodeValue.indexOf(target);
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
            // Remove any previously-inserted dynamic font option
            var _dynFont = fontSel.querySelector('option[data-dynamic]');
            if (_dynFont) _dynFont.remove();
            if (opt) {
                fontSel.value = opt.value;
            } else if (fontVal) {
                // Computed font doesn't match any preset — add a temporary option
                var _df = document.createElement('option');
                _df.value = fontVal;
                _df.textContent = fontVal;
                _df.setAttribute('data-dynamic', '1');
                fontSel.insertBefore(_df, fontSel.firstChild.nextSibling);
                fontSel.value = fontVal;
            } else {
                fontSel.value = '';
            }
            const px = computed.fontSize ? parseFloat(computed.fontSize) : 0;
            const pxRound = px ? String(Math.round(px)) : '';
            // Remove any previously-inserted dynamic size option
            var _dynSize = sizeSel.querySelector('option[data-dynamic]');
            if (_dynSize) _dynSize.remove();
            if (pxRound && [].some.call(sizeSel.options, o => o.value === pxRound)) {
                sizeSel.value = pxRound;
            } else if (pxRound) {
                // Computed size doesn't match any preset — add a temporary option
                var _ds = document.createElement('option');
                _ds.value = pxRound;
                _ds.textContent = pxRound + 'px';
                _ds.setAttribute('data-dynamic', '1');
                // Insert in correct sorted position
                var _inserted = false;
                for (var _si = 1; _si < sizeSel.options.length; _si++) {
                    if (sizeSel.options[_si].value && parseInt(sizeSel.options[_si].value) > parseInt(pxRound)) {
                        sizeSel.insertBefore(_ds, sizeSel.options[_si]);
                        _inserted = true;
                        break;
                    }
                }
                if (!_inserted) sizeSel.appendChild(_ds);
                sizeSel.value = pxRound;
            } else {
                sizeSel.value = '';
            }
            boldBtn.style.background = (computed.fontWeight === '700' || computed.fontWeight === 'bold') ? 'rgba(59,130,246,0.3)' : '';
            italicBtn.style.background = computed.fontStyle === 'italic' ? 'rgba(59,130,246,0.3)' : '';
            colorInp.value = rgbToHex(computed.color) || '#000000';
            bgInp.value = rgbToHex(computed.backgroundColor) || '#ffffff';
            if (classSel) {
                var cls = '';
                var _ce = el;
                while (_ce && _ce.nodeType === 1 && _ce.tagName !== 'BODY') {
                    var _cn = (_ce.className || '').trim().split(/\s+/)[0] || '';
                    if (_cn && cssClasses.includes(_cn)) { cls = _cn; break; }
                    _ce = _ce.parentElement;
                }
                classSel.value = cls || '';
            }
            // Actualizează și butonul de clasă rapidă din topbar (B lângă Redo)
            const quickClassBtn = document.getElementById('btnClassObisnuit2');
            if (quickClassBtn) {
                quickClassBtn.dataset.className = 'text_obisnuit2';
                const hasClass = (' ' + (el.className || '') + ' ').indexOf(' text_obisnuit2 ') !== -1;
                quickClassBtn.classList.toggle('active', hasClass);
            }
            // Link / Target
            var linkInp = document.getElementById('propLink');
            var linkTargetSel = document.getElementById('propLinkTarget');
            if (linkInp && linkTargetSel) {
                var anchorEl = null;
                var _ae = el;
                while (_ae && _ae.nodeType === 1 && _ae.tagName !== 'BODY') {
                    if (_ae.tagName === 'A') { anchorEl = _ae; break; }
                    _ae = _ae.parentElement;
                }
                if (anchorEl) {
                    linkInp.value = anchorEl.getAttribute('href') || '';
                    linkTargetSel.value = anchorEl.getAttribute('target') || '';
                } else {
                    linkInp.value = '';
                    linkTargetSel.value = '';
                }
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

        function applyLinkProperty() {
            designPushCurrentState();
            var linkVal = document.getElementById('propLink').value.trim();
            var targetVal = document.getElementById('propLinkTarget').value;
            var el = getDesignSelection();
            if (!el) return;
            var iframe = document.getElementById('preview');
            var iDoc = iframe.contentDocument;
            var iWin = iframe.contentWindow;
            // Find existing <a> ancestor
            var anchorEl = null;
            var _ae = el;
            while (_ae && _ae.nodeType === 1 && _ae.tagName !== 'BODY') {
                if (_ae.tagName === 'A') { anchorEl = _ae; break; }
                _ae = _ae.parentElement;
            }
            // Capture ALL computed font styles BEFORE any DOM change
            var _origStyles = {};
            try {
                var _cs = iWin.getComputedStyle(el);
                _origStyles.fontSize   = _cs.fontSize;
                _origStyles.fontStyle  = _cs.fontStyle;
                _origStyles.fontWeight = _cs.fontWeight;
                _origStyles.fontFamily = _cs.fontFamily;
            } catch(e) {}
            if (linkVal) {
                if (anchorEl) {
                    // Update existing anchor
                    anchorEl.setAttribute('href', linkVal);
                    if (targetVal) anchorEl.setAttribute('target', targetVal);
                    else anchorEl.removeAttribute('target');
                } else {
                    var sel = iWin.getSelection();
                    if (sel && sel.rangeCount > 0 && !sel.getRangeAt(0).collapsed) {
                        // Use execCommand('createLink') — browser creates <a> inside
                        // existing DOM structure without breaking parent elements
                        iDoc.execCommand('createLink', false, linkVal);
                        // Find the newly created <a> and apply preserved styles + target
                        var newSel = iWin.getSelection();
                        if (newSel && newSel.rangeCount > 0) {
                            var node = newSel.anchorNode;
                            if (node && node.nodeType === 3) node = node.parentElement;
                            var newAnchor = node && node.closest ? node.closest('a') : null;
                            if (newAnchor) {
                                if (targetVal) newAnchor.setAttribute('target', targetVal);
                                // Force-preserve original font styles on <a> so page
                                // CSS cannot override italic, bold, size, etc.
                                if (_origStyles.fontSize)   newAnchor.style.fontSize   = _origStyles.fontSize;
                                if (_origStyles.fontStyle)  newAnchor.style.fontStyle  = _origStyles.fontStyle;
                                if (_origStyles.fontWeight) newAnchor.style.fontWeight = _origStyles.fontWeight;
                                if (_origStyles.fontFamily) newAnchor.style.fontFamily = _origStyles.fontFamily;
                            }
                        }
                    } else if (el.tagName !== 'BODY') {
                        // No text selected — wrap whole element content
                        var doc = el.ownerDocument;
                        var a = doc.createElement('a');
                        a.setAttribute('href', linkVal);
                        if (targetVal) a.setAttribute('target', targetVal);
                        if (_origStyles.fontSize)   a.style.fontSize   = _origStyles.fontSize;
                        if (_origStyles.fontStyle)  a.style.fontStyle  = _origStyles.fontStyle;
                        if (_origStyles.fontWeight) a.style.fontWeight = _origStyles.fontWeight;
                        if (_origStyles.fontFamily) a.style.fontFamily = _origStyles.fontFamily;
                        while (el.firstChild) a.appendChild(el.firstChild);
                        el.appendChild(a);
                    }
                }
            } else {
                // Link cleared — remove <a> wrapper, keep children
                if (anchorEl) {
                    iDoc.execCommand('unlink', false, null);
                }
            }
            syncFromDesign();
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
                let ancestor = range.commonAncestorContainer;
                if (ancestor.nodeType === 3) ancestor = ancestor.parentElement;

                // Case 1: selection covers the entire content of a block element
                // → change the block's class directly instead of wrapping in a span
                const BLOCK_TAGS = new Set(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'td', 'th', 'blockquote', 'pre', 'article', 'section', 'figure', 'header', 'footer', 'main', 'nav']);
                if (BLOCK_TAGS.has((ancestor.tagName || '').toLowerCase()) &&
                    selectedText.trim() === ancestor.textContent.trim()) {
                    if (value && ancestor.classList.contains(value)) {
                        // Toggle off: restaurează clasa originală salvată
                        const origClass = ancestor.getAttribute('data-orig-class');
                        ancestor.removeAttribute('data-orig-class');
                        if (origClass) {
                            ancestor.className = origClass;
                        } else {
                            ancestor.removeAttribute('class');
                        }
                    } else if (value) {
                        // Salvează clasa curentă înainte de a aplica text_obisnuit2
                        if (!ancestor.hasAttribute('data-orig-class')) {
                            ancestor.setAttribute('data-orig-class', ancestor.className || '');
                        }
                        ancestor.className = value;
                    } else {
                        ancestor.removeAttribute('class');
                        ancestor.removeAttribute('data-orig-class');
                    }
                    syncFromDesign();
                    lastDesignSnapshot = getDesignBodyHtml();
                    return;
                }

                // Case 2: selection is inside (or is) an existing span → change/remove its class
                let spanEl = ancestor && ancestor.closest ? ancestor.closest('span') : null;
                if (spanEl && spanEl !== doc.body) {
                    if (value && spanEl.classList.contains(value)) {
                        // Toggle off: unwrap span using DOM (no execCommand → no style="" side-effect)
                        const parent = spanEl.parentNode;
                        while (spanEl.firstChild) parent.insertBefore(spanEl.firstChild, spanEl);
                        parent.removeChild(spanEl);
                        parent.normalize();
                        if (selectedText) {
                            const newRange = findTextRangeInNode(doc, parent, selectedText);
                            if (newRange) { sel.removeAllRanges(); sel.addRange(newRange); }
                        }
                    } else if (value) {
                        spanEl.className = value;
                    } else {
                        // Remove class: unwrap span
                        const parent = spanEl.parentNode;
                        while (spanEl.firstChild) parent.insertBefore(spanEl.firstChild, spanEl);
                        parent.removeChild(spanEl);
                        parent.normalize();
                        if (selectedText) {
                            const newRange = findTextRangeInNode(doc, parent, selectedText);
                            if (newRange) { sel.removeAllRanges(); sel.addRange(newRange); }
                        }
                    }
                } else if (value) {
                    // Case 3: partial selection with no existing span → wrap only selected words
                    // Use Range API (not execCommand) to avoid style="" side-effects
                    try {
                        const newSpan = doc.createElement('span');
                        newSpan.className = value;
                        range.surroundContents(newSpan);
                        sel.removeAllRanges();
                        const r = doc.createRange();
                        r.selectNodeContents(newSpan);
                        sel.addRange(r);
                    } catch (e) {
                        // surroundContents fails when selection partially crosses element boundaries;
                        // fall back to extract + insert
                        const newSpan = doc.createElement('span');
                        newSpan.className = value;
                        const frag = range.extractContents();
                        newSpan.appendChild(frag);
                        range.insertNode(newSpan);
                        sel.removeAllRanges();
                        const r = doc.createRange();
                        r.selectNodeContents(newSpan);
                        sel.addRange(r);
                    }
                }
            } else {
                // Case 4: no text selection (cursor only) → change the clicked element's class directly
                const el = getDesignSelection();
                if (!el) return;
                if (value && el.classList.contains(value)) {
                    // Toggle off: restaurează clasa originală salvată
                    const origClass = el.getAttribute('data-orig-class');
                    el.removeAttribute('data-orig-class');
                    if (origClass) {
                        el.className = origClass;
                    } else {
                        el.removeAttribute('class');
                    }
                } else if (value) {
                    // Salvează clasa curentă înainte de a aplica text_obisnuit2
                    if (!el.hasAttribute('data-orig-class')) {
                        el.setAttribute('data-orig-class', el.className || '');
                    }
                    el.className = value;
                } else {
                    el.removeAttribute('class');
                    el.removeAttribute('data-orig-class');
                }
            }
            syncFromDesign();
            // Record the post-change state so the next snapshot won't double-push
            lastDesignSnapshot = getDesignBodyHtml();
        }

        async function openFromPath() {
            const inp = document.getElementById('pathInput');
            let p = (inp.value || '').trim();
            p = p.replace(/^[“']+|[“']+$/g, '').trim();
            if (!p) { dropStatus('Scrie calea catre fisier', '#ef4444'); return; }
            dropStatus('Se deschide...', '#60a5fa');
            try {
                const res = await fetch('?action=load&file=' + encodeURIComponent(p));
                if (!res.ok) { dropStatus('Eroare HTTP ' + res.status, '#ef4444'); return; }
                const txt = await res.text();
                let data;
                try { data = JSON.parse(txt); } catch (e) { dropStatus('Raspuns invalid (nu e JSON)', '#ef4444'); return; }
                if (!data.ok) { dropStatus('Eroare: ' + (data.error || 'necunoscuta'), '#ef4444'); return; }

                // Check if already open
                var existing = findTabByPath(data.file);
                if (existing) {
                    switchToTab(existing.id);
                    hideOverlay();
                    dropStatus('');
                    return;
                }

                saveCurrentTabState();

                var cnt = data.content || '';
                var label = getTabLabel(cnt, data.file);
                var fullT = getTabFullTitle(cnt, data.file);
                var tab = createTabState({
                    filePath: data.file,
                    fileName: shortName(data.file),
                    tabLabel: label,
                    fullTitle: fullT,
                    editorContent: cnt,
                    originalContent: cnt,
                    lastPreviewHadBody: /<body\b/i.test(cnt)
                });
                tabs.push(tab);
                activeTabId = tab.id;

                currentFile = data.file;
                addToRecentFiles(currentFile);
                _isRestoringTab = true;
                isSyncFromDesign = true;
                editor.setValue(data.content || '');
                isSyncFromDesign = false;
                _isRestoringTab = false;
                editor.getDoc().clearHistory();
                lastPreviewHadBody = tab.lastPreviewHadBody;
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = null;
                document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
                updatePreview();
                isDirty = false;
                designUndoStack = [];
                designRedoStack = [];
                lastDesignSnapshot = null;

                renderTabs();
                hideOverlay();
                dropStatus('');
            } catch (e) {
                dropStatus('Eroare: ' + e.message, '#ef4444');
            }
        }

        function closeEditor() {
            closeActiveTab();
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
            dropStatus('Se citeste fisierul “' + file.name + '”...', '#60a5fa');
            const reader = new FileReader();
            reader.onload = function () {
                var content = reader.result || '';

                // Save current tab before creating new one
                saveCurrentTabState();

                var label = getTabLabel(content, file.name);
                var fullT = getTabFullTitle(content, file.name);
                var tab = createTabState({
                    filePath: null,
                    fileName: file.name,
                    tabLabel: label,
                    fullTitle: fullT,
                    editorContent: content,
                    originalContent: content,
                    lastPreviewHadBody: /<body\b/i.test(content)
                });
                tabs.push(tab);
                activeTabId = tab.id;
                var newTabId = tab.id;

                currentFile = null;
                _isRestoringTab = true;
                isSyncFromDesign = true;
                editor.setValue(content);
                isSyncFromDesign = false;
                _isRestoringTab = false;
                editor.getDoc().clearHistory();
                lastPreviewHadBody = tab.lastPreviewHadBody;
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = null;
                updatePreview();
                document.querySelectorAll('.file').forEach(f => f.classList.remove('active'));
                isDirty = false;
                designUndoStack = [];
                designRedoStack = [];
                lastDesignSnapshot = null;

                renderTabs();
                hideOverlay();
                dropStatus('');

                // Cauta automat calea fisierului pe disc dupa nume
                const extraDirs = getRecentDirs();
                const searchUrl = '?action=search&name=' + encodeURIComponent(file.name)
                    + (extraDirs.length ? '&dirs=' + encodeURIComponent(JSON.stringify(extraDirs)) : '');
                fetch(searchUrl)
                    .then(r => r.json())
                    .then(data => {
                        // Helper to update tab when path resolved
                        function resolveTabPath(resolvedPath) {
                            // Check if another tab already has this path
                            var existingTab = findTabByPath(resolvedPath);
                            if (existingTab && existingTab.id !== newTabId) {
                                // Duplicate — close the new tab and switch to existing
                                closeTab(newTabId);
                                switchToTab(existingTab.id);
                                return;
                            }
                            currentFile = resolvedPath;
                            // Update the tab object
                            for (var ti = 0; ti < tabs.length; ti++) {
                                if (tabs[ti].id === newTabId) {
                                    tabs[ti].filePath = resolvedPath;
                                    tabs[ti].tabLabel = getTabLabel(editor.getValue(), resolvedPath);
                                    break;
                                }
                            }
                            isDirty = false;
                            addToRecentFiles(resolvedPath);
                            renderTabs();
                            toast('Fisier gasit: ' + resolvedPath);
                            updatePreview();
                        }

                        if (data.ok && data.results && data.results.length === 1) {
                            resolveTabPath(data.results[0]);
                        } else if (data.ok && data.results && data.results.length > 1) {
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
                                    resolveTabPath(matched);
                                }
                            });
                        }
                    })
                    .catch(() => { });
            };
            reader.onerror = function () { dropStatus('Eroare la citirea fisierului', '#ef4444'); };
            reader.readAsText(file, 'UTF-8');
        }

        window.addEventListener('beforeunload', function (e) {
            if (typeof flushPendingDesignSync === 'function') flushPendingDesignSync();
            saveCurrentTabState();
            var hasUnsaved = tabs.some(function (t) { return t.isDirty; }) || isDirty;
            if (hasUnsaved) {
                // Force immediate backup (bypass debounce)
                try {
                    var dirtyTabs = [];
                    for (var i = 0; i < tabs.length; i++) {
                        if (tabs[i].isDirty) {
                            dirtyTabs.push({
                                id: tabs[i].id,
                                filePath: tabs[i].filePath,
                                fileName: tabs[i].fileName,
                                tabLabel: tabs[i].tabLabel,
                                fullTitle: tabs[i].fullTitle,
                                editorContent: tabs[i].editorContent,
                                originalContent: tabs[i].originalContent,
                                viewMode: tabs[i].viewMode
                            });
                        }
                    }
                    if (dirtyTabs.length > 0) {
                        localStorage.setItem('htmlEditorBackupTabs', JSON.stringify(dirtyTabs));
                    }
                } catch (ex) { }
                e.preventDefault();
                e.returnValue = '';
            } else {
                // No dirty tabs — remove any stale backup from localStorage
                try { localStorage.removeItem('htmlEditorBackupTabs'); } catch (ex) { }
            }
        });

        window.addEventListener('load', () => {
            initEditor();
            refreshList('');
            renderRecentFiles();
            renderSidebarRecent();
            // Restore unsaved tabs from backup (e.g. after power loss)
            restoreBackupTabs();
            // Restore sidebar visibility from last session
            try {
                const sv = localStorage.getItem('htmlEditorSidebarVisible');
                if (sv === '0') {
                    sidebarVisible = false;
                    const sb = document.querySelector('.sidebar');
                    if (sb) sb.classList.add('collapsed');
                }
            } catch (e) { }
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

            // Make popups draggable/resizable (especially translation panel)
            function makeDialogDraggable(el) {
                if (!el) return;
                el.classList.add('resizable');
                const header = el.querySelector('.drag-handle') || el.querySelector('h4');
                if (!header) return;
                let dragging = false;
                let startX = 0, startY = 0, startLeft = 0, startTop = 0;

                header.addEventListener('mousedown', function (e) {
                    dragging = true;
                    const rect = el.getBoundingClientRect();
                    startX = e.clientX;
                    startY = e.clientY;
                    // convert current transform (top/left) to explicit left/top
                    el.style.left = rect.left + 'px';
                    el.style.top = rect.top + 'px';
                    el.style.right = 'auto';
                    el.style.bottom = 'auto';
                    startLeft = rect.left;
                    startTop = rect.top;
                    document.body.style.userSelect = 'none';
                });

                window.addEventListener('mousemove', function (e) {
                    if (!dragging) return;
                    const dx = e.clientX - startX;
                    const dy = e.clientY - startY;
                    let newLeft = startLeft + dx;
                    let newTop = startTop + dy;
                    const maxX = window.innerWidth - 50;
                    const maxY = window.innerHeight - 40;
                    if (newLeft < 0) newLeft = 0;
                    if (newTop < 40) newTop = 40;
                    if (newLeft > maxX) newLeft = maxX;
                    if (newTop > maxY) newTop = maxY;
                    el.style.left = newLeft + 'px';
                    el.style.top = newTop + 'px';
                });

                window.addEventListener('mouseup', function () {
                    if (dragging) {
                        dragging = false;
                        document.body.style.userSelect = '';
                    }
                });
            }

            makeDialogDraggable(document.getElementById('translatePopup'));
            makeDialogDraggable(document.getElementById('diacPopup'));

            // Drag-and-drop files onto tab bar area (+ button and empty spacer)
            (function() {
                var tabBar = document.getElementById('tabBar');
                var spacer = document.getElementById('tabBarSpacer');
                if (!tabBar || !spacer) return;

                var targets = [tabBar, spacer];
                var dragCounter = {};  // per-element counter to handle child enter/leave

                function hasFiles(e) {
                    if (e.dataTransfer && e.dataTransfer.types) {
                        for (var i = 0; i < e.dataTransfer.types.length; i++) {
                            if (e.dataTransfer.types[i] === 'Files') return true;
                        }
                    }
                    return false;
                }

                targets.forEach(function(el) {
                    var id = el.id;
                    dragCounter[id] = 0;

                    el.addEventListener('dragenter', function(e) {
                        if (!hasFiles(e)) return;  // ignore tab reorder drags
                        e.preventDefault();
                        e.stopPropagation();
                        dragCounter[id]++;
                        el.classList.add('file-drag-over');
                    });

                    el.addEventListener('dragover', function(e) {
                        if (!hasFiles(e)) return;
                        e.preventDefault();
                        e.stopPropagation();
                        e.dataTransfer.dropEffect = 'copy';
                    });

                    el.addEventListener('dragleave', function(e) {
                        if (!hasFiles(e)) return;
                        e.preventDefault();
                        e.stopPropagation();
                        dragCounter[id]--;
                        if (dragCounter[id] <= 0) {
                            dragCounter[id] = 0;
                            el.classList.remove('file-drag-over');
                        }
                    });

                    el.addEventListener('drop', function(e) {
                        if (!hasFiles(e)) return;
                        e.preventDefault();
                        e.stopPropagation();
                        dragCounter[id] = 0;
                        el.classList.remove('file-drag-over');
                        // Also clear sibling highlight
                        targets.forEach(function(t) { t.classList.remove('file-drag-over'); });
                        var f = e.dataTransfer.files && e.dataTransfer.files[0];
                        if (f) handleDropFile(f);
                    });
                });
            })();

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
            // Shortcut în topbar: B lângă Redo → aplică clasa text_obisnuit2 pe selecția din Design
            const quickClassBtn = document.getElementById('btnClassObisnuit2');
            if (quickClassBtn) {
                quickClassBtn.addEventListener('click', () => {
                    const targetClass = quickClassBtn.dataset.className || 'text_obisnuit2';
                    applyClassProperty(targetClass);
                    const el = getDesignSelection();
                    if (el) {
                        const hasClass = (' ' + (el.className || '') + ' ').indexOf(' ' + targetClass + ' ') !== -1;
                        quickClassBtn.classList.toggle('active', hasClass);
                    }
                });
            }
            document.getElementById('propColor').addEventListener('input', () => applyFontProperty('color', document.getElementById('propColor').value));
            document.getElementById('propBg').addEventListener('input', () => applyFontProperty('backgroundColor', document.getElementById('propBg').value));
            document.getElementById('propLink').addEventListener('change', applyLinkProperty);
            document.getElementById('propLinkTarget').addEventListener('change', applyLinkProperty);
        });
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(function () { });
        }
    </script>
</body>

</html>