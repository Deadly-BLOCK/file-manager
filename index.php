<?php
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$is_https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_CF_VISITOR']) && stripos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');
if ($is_https) ini_set('session.cookie_secure', '1');
session_name('AUTHSID');
session_start();
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
$current_relative_dir = isset($_GET['dir']) ? $_GET['dir'] : '';
$current_dir = rtrim(BASE_DIR . DIRECTORY_SEPARATOR . $current_relative_dir, DIRECTORY_SEPARATOR);

$isAjax = isset($_POST['ajax']) && $_POST['ajax'] == '1';

function respond($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function recursiveDelete($dir) {
    if (!file_exists($dir) && !is_link($dir)) return true;
    if (is_link($dir) || !is_dir($dir)) return @unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!recursiveDelete($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return @rmdir($dir);
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getDirSize($path) {
    $size = 0;
    if (!is_dir($path)) return 0;
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iter as $file) {
            try { if ($file->isFile()) $size += $file->getSize(); }
            catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}
    return $size;
}

function countFolderItems($path) {
    if (!is_dir($path)) return 0;
    try {
        $c = @scandir($path);
        return $c ? max(0, count($c) - 2) : 0;
    } catch (\Throwable $e) { return 0; }
}

function fileIconMeta($ext, $is_dir) {
    if ($is_dir) return ['fas fa-folder', '#fbbf24'];
    $m = [
        'js'=>['fab fa-js','#f7df1e'],'mjs'=>['fab fa-js','#f7df1e'],'cjs'=>['fab fa-js','#f7df1e'],
        'ts'=>['fas fa-code','#3178c6'],'tsx'=>['fas fa-code','#3178c6'],'jsx'=>['fab fa-react','#61dafb'],
        'html'=>['fab fa-html5','#e34c26'],'htm'=>['fab fa-html5','#e34c26'],
        'css'=>['fab fa-css3-alt','#264de4'],'scss'=>['fab fa-sass','#cc6699'],'sass'=>['fab fa-sass','#cc6699'],'less'=>['fab fa-less','#1d365d'],
        'php'=>['fab fa-php','#777bb4'],'phtml'=>['fab fa-php','#777bb4'],
        'py'=>['fab fa-python','#3776ab'],
        'rb'=>['fas fa-gem','#cc342d'],
        'go'=>['fas fa-code','#00add8'],
        'rs'=>['fas fa-code','#dea584'],
        'java'=>['fab fa-java','#f89820'],'class'=>['fab fa-java','#f89820'],
        'c'=>['fas fa-code','#a8b9cc'],'h'=>['fas fa-code','#a8b9cc'],
        'cpp'=>['fas fa-code','#00599c'],'hpp'=>['fas fa-code','#00599c'],'cc'=>['fas fa-code','#00599c'],
        'cs'=>['fas fa-code','#239120'],
        'swift'=>['fab fa-swift','#fa7343'],
        'kt'=>['fas fa-code','#7f52ff'],
        'json'=>['fas fa-code','#34d399'],
        'sql'=>['fas fa-database','#60a5fa'],'db'=>['fas fa-database','#60a5fa'],'sqlite'=>['fas fa-database','#60a5fa'],
        'sh'=>['fas fa-terminal','#4ade80'],'bash'=>['fas fa-terminal','#4ade80'],'zsh'=>['fas fa-terminal','#4ade80'],
        'md'=>['fab fa-markdown','#94a3b8'],'mdx'=>['fab fa-markdown','#94a3b8'],
        'txt'=>['fas fa-file-lines','#94a3b8'],'log'=>['fas fa-file-lines','#94a3b8'],
        'yaml'=>['fas fa-file-code','#cb171e'],'yml'=>['fas fa-file-code','#cb171e'],
        'toml'=>['fas fa-file-code','#9c4221'],
        'xml'=>['fas fa-code','#94a3b8'],
        'env'=>['fas fa-key','#facc15'],'lock'=>['fas fa-lock','#94a3b8'],
        'png'=>['fas fa-image','#a78bfa'],'jpg'=>['fas fa-image','#a78bfa'],'jpeg'=>['fas fa-image','#a78bfa'],
        'gif'=>['fas fa-image','#a78bfa'],'webp'=>['fas fa-image','#a78bfa'],'svg'=>['fas fa-image','#a78bfa'],
        'ico'=>['fas fa-image','#a78bfa'],'bmp'=>['fas fa-image','#a78bfa'],
        'mp4'=>['fas fa-film','#f472b6'],'webm'=>['fas fa-film','#f472b6'],'mov'=>['fas fa-film','#f472b6'],
        'avi'=>['fas fa-film','#f472b6'],'mkv'=>['fas fa-film','#f472b6'],
        'mp3'=>['fas fa-music','#fb923c'],'wav'=>['fas fa-music','#fb923c'],
        'ogg'=>['fas fa-music','#fb923c'],'flac'=>['fas fa-music','#fb923c'],'m4a'=>['fas fa-music','#fb923c'],
        'zip'=>['fas fa-file-zipper','#fbbf24'],'rar'=>['fas fa-file-zipper','#fbbf24'],
        'tar'=>['fas fa-file-zipper','#fbbf24'],'gz'=>['fas fa-file-zipper','#fbbf24'],
        '7z'=>['fas fa-file-zipper','#fbbf24'],'bz2'=>['fas fa-file-zipper','#fbbf24'],
        'pdf'=>['fas fa-file-pdf','#f87171'],
        'doc'=>['fas fa-file-word','#60a5fa'],'docx'=>['fas fa-file-word','#60a5fa'],
        'xls'=>['fas fa-file-excel','#34d399'],'xlsx'=>['fas fa-file-excel','#34d399'],
        'ppt'=>['fas fa-file-powerpoint','#fb923c'],'pptx'=>['fas fa-file-powerpoint','#fb923c'],
        'csv'=>['fas fa-file-csv','#34d399'],
        'ttf'=>['fas fa-font','#94a3b8'],'otf'=>['fas fa-font','#94a3b8'],'woff'=>['fas fa-font','#94a3b8'],'woff2'=>['fas fa-font','#94a3b8'],
    ];
    return $m[$ext] ?? ['fas fa-file','#a5b4fc'];
}

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
}

if (isset($_GET['download']) || isset($_GET['view'])) {
    $name = $_GET['download'] ?? $_GET['view'];
    $file_to_serve = $current_dir . DIRECTORY_SEPARATOR . $name;
    $disposition = isset($_GET['view']) ? 'inline' : 'attachment';
    if (file_exists($file_to_serve) && is_file($file_to_serve)) {
        $mime = function_exists('mime_content_type') ? @mime_content_type($file_to_serve) : null;
        if (!$mime) $mime = 'application/octet-stream';
        header('Content-Type: ' . ($disposition === 'inline' ? $mime : 'application/octet-stream'));
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($file_to_serve) . '"');
        header('Cache-Control: private, max-age=0');
        header('Content-Length: ' . filesize($file_to_serve));
        readfile($file_to_serve);
        exit;
    }
    http_response_code(404);
    exit('File not found.');
}

if (isset($_GET['raw'])) {
    $file = $current_dir . DIRECTORY_SEPARATOR . $_GET['raw'];
    if (file_exists($file) && is_file($file)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit('File not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'add_file':
            if (!empty($_POST['new_file_name'])) {
                $new_file = $current_dir . DIRECTORY_SEPARATOR . $_POST['new_file_name'];
                if (file_exists($new_file)) respond(false, "File already exists.");
                touch($new_file);
                respond(true, "File created.");
            }
            respond(false, "Name required.");
            break;

        case 'upload_file':
            if (!empty($_FILES['uploaded_file']['name'][0])) {
                $files     = $_FILES['uploaded_file'];
                $rel_paths = $_POST['relative_paths'] ?? [];
                $total     = count($files['name']);
                $ok = $fail = $skipped = 0;

                for ($i = 0; $i < $total; $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) { $fail++; continue; }
                    $rel_path = $rel_paths[$i] ?? '';
                    $rel_path = ltrim($rel_path, '/\\');
                    if ($rel_path !== '') {
                        $segments = preg_split('/[\\\\\/]/', $rel_path);
                        $safe = array_filter($segments, fn($s) => $s !== '..' && $s !== '.' && $s !== '');
                        $rel_path = implode(DIRECTORY_SEPARATOR, $safe);
                        $dest_dir = $current_dir . DIRECTORY_SEPARATOR . dirname($rel_path);
                    } else {
                        $dest_dir = $current_dir;
                        $rel_path = basename($files['name'][$i]);
                    }
                    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true)) { $fail++; continue; }
                    $dest = $current_dir . DIRECTORY_SEPARATOR . $rel_path;
                    if (file_exists($dest)) { $skipped++; continue; }
                    if (@move_uploaded_file($files['tmp_name'][$i], $dest)) $ok++;
                    else $fail++;
                }
                $msg = "$ok uploaded.";
                if ($skipped) $msg .= " $skipped skipped.";
                if ($fail)    $msg .= " $fail failed.";
                respond($ok > 0 || $skipped > 0, $msg, compact('ok','skipped','fail'));
            }
            respond(false, "No files received.");
            break;

        case 'delete':
            if (!empty($_POST['item_to_delete'])) {
                $item = $current_dir . DIRECTORY_SEPARATOR . $_POST['item_to_delete'];
                if (file_exists($item) || is_link($item)) {
                    recursiveDelete($item);
                    respond(true, "Deleted.");
                }
                respond(false, "Item not found.");
            }
            break;

        case 'bulk_delete':
            if (!empty($_POST['items'])) {
                $list = json_decode($_POST['items'], true) ?: [];
                $done = $err = 0;
                foreach ($list as $name) {
                    $p = $current_dir . DIRECTORY_SEPARATOR . $name;
                    if (file_exists($p) || is_link($p)) {
                        recursiveDelete($p) ? $done++ : $err++;
                    } else $err++;
                }
                respond(true, "$done deleted" . ($err ? ", $err failed" : "") . ".");
            }
            respond(false, "Nothing selected.");
            break;

        case 'rename':
            if (!empty($_POST['item_to_rename']) && !empty($_POST['new_item_name'])) {
                $old = $current_dir . DIRECTORY_SEPARATOR . $_POST['item_to_rename'];
                $new = $current_dir . DIRECTORY_SEPARATOR . $_POST['new_item_name'];
                if (file_exists($new)) respond(false, "Target name already exists.");
                if (file_exists($old) || is_link($old)) {
                    @rename($old, $new);
                    respond(true, "Renamed.");
                }
                respond(false, "Item not found.");
            }
            break;

        case 'add_folder':
            if (!empty($_POST['new_folder_name'])) {
                $f = $current_dir . DIRECTORY_SEPARATOR . $_POST['new_folder_name'];
                if (file_exists($f)) respond(false, "Folder already exists.");
                @mkdir($f, 0755, true);
                respond(true, "Folder created.");
            }
            respond(false, "Name required.");
            break;

        case 'save_file':
            if (!empty($_POST['file_name']) && isset($_POST['file_content'])) {
                $fn = $current_dir . DIRECTORY_SEPARATOR . $_POST['file_name'];
                file_put_contents($fn, $_POST['file_content']);
                respond(true, "Saved.");
            }
            break;

        case 'clone':
            if (!empty($_POST['item_to_clone'])) {
                $src = $current_dir . DIRECTORY_SEPARATOR . $_POST['item_to_clone'];
                if (!file_exists($src)) respond(false, "Source not found.");
                $ext  = pathinfo($src, PATHINFO_EXTENSION);
                $name = pathinfo($src, PATHINFO_FILENAME);
                $i = 1;
                do {
                    $suffix = $i === 1 ? '_copy' : "_copy{$i}";
                    $dest = $current_dir . DIRECTORY_SEPARATOR . $name . $suffix . ($ext ? ".$ext" : "");
                    $i++;
                } while (file_exists($dest));
                if (is_dir($src)) {
                    @mkdir($dest, 0755, true);
                    $iter = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($iter as $item) {
                        $target = $dest . DIRECTORY_SEPARATOR . $iter->getSubPathName();
                        if ($item->isDir()) @mkdir($target, 0755, true);
                        else @copy($item->getPathname(), $target);
                    }
                } else {
                    @copy($src, $dest);
                }
                respond(true, "Cloned as " . basename($dest));
            }
            break;

        case 'zip':
            if (!empty($_POST['item_to_zip'])) {
                $zip = new ZipArchive();
                $source = $current_dir . DIRECTORY_SEPARATOR . $_POST['item_to_zip'];
                $zipName = $source . '.zip';
                if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                    if (is_dir($source)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::LEAVES_ONLY);
                        foreach ($files as $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = substr($filePath, strlen($source) + 1);
                                $zip->addFile($filePath, $relativePath);
                            }
                        }
                    } else {
                        $zip->addFile($source, basename($source));
                    }
                    $zip->close();
                    respond(true, "Compressed.");
                }
                respond(false, "Zip failed.");
            }
            break;

        case 'extract':
            if (!empty($_POST['item_to_extract'])) {
                $zipFile = $current_dir . DIRECTORY_SEPARATOR . $_POST['item_to_extract'];
                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    $zip->extractTo($current_dir);
                    $zip->close();
                    respond(true, "Extracted.");
                }
                respond(false, "Extraction failed.");
            }
            break;
    }
    if ($isAjax) exit;
}


$items = [];
$scan  = @scandir($current_dir) ?: [];
foreach ($scan as $n) {
    if ($n === '.' || $n === '..') continue;
    $items[] = $n;
}
usort($items, function($a, $b) use ($current_dir) {
    $ad = is_dir($current_dir . DIRECTORY_SEPARATOR . $a);
    $bd = is_dir($current_dir . DIRECTORY_SEPARATOR . $b);
    if ($ad && !$bd) return -1;
    if (!$ad && $bd) return 1;
    return strcasecmp($a, $b);
});

$parent_path = '';
if ($current_relative_dir !== '') {
    $parts = explode(DIRECTORY_SEPARATOR, trim($current_relative_dir, DIRECTORY_SEPARATOR));
    array_pop($parts);
    $parent_path = implode(DIRECTORY_SEPARATOR, $parts);
}

$total_used   = getDirSize(BASE_DIR);
$disk_limit   = 1024 * 1024 * 1024 * 100;
$disk_perc    = min(($total_used / $disk_limit) * 100, 100);
$current_size = getDirSize($current_dir);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Editor | <?= htmlspecialchars($current_relative_dir ?: 'Root') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/theme/ayu-mirage.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/theme/idea.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/dialog/dialog.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/python/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/markdown/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/sql/sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/yaml/yaml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/shell/shell.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/ruby/ruby.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/go/go.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/rust/rust.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/dialog/dialog.min.js"></script>

    <style>
        
        html {
            font-size: clamp(10.5px, calc(0.2vw + 0.7vh + 5px), 14.5px);
        }

        :root {
            --bg:           #0a0b0f;
            --bg-tint:      #10121a;
            --bar:          #0d0e15;
            --card:         #14161f;
            --card-hover:   #191c27;
            --border:       rgba(255, 255, 255, 0.055);
            --border-hi:    rgba(255, 255, 255, 0.13);
            --text:         #eef0f5;
            --text-dim:     #a0a7b8;
            --text-faint:   #5d6477;

            --accent:       #8b8fff;
            --accent-2:     #6d72f7;
            --accent-glow:  rgba(139, 143, 255, 0.3);
            --accent-soft:  rgba(139, 143, 255, 0.09);

            --success:      #4ade80;
            --warning:      #fbbf24;
            --danger:       #f87171;

            --r-sm: 0.3rem;
            --r-md: 0.45rem;
            --r-lg: 0.65rem;
            --r-xl: 0.9rem;

            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace;

            --tb-h:  3.4rem;   
            --sub-h: 2.8rem;   
            --sb-h:  2.05rem;  
        }

        body.light-mode {
            --bg:           #f6f7fa;
            --bg-tint:      #ffffff;
            --bar:          #ffffff;
            --card:         #ffffff;
            --card-hover:   #f1f5f9;
            --border:       #e8eaf0;
            --border-hi:    #d0d4dc;
            --text:         #0f172a;
            --text-dim:     #495162;
            --text-faint:   #94a3b8;
            --accent:       #5b66e8;
            --accent-2:     #4f46e5;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        ::-webkit-scrollbar { width: 0.55rem; height: 0.55rem; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 0.55rem; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }

        html, body { height: 100vh; overflow: hidden; }
        body {
            font-family: var(--font-sans);
            background-color: var(--bg);
            color: var(--text);
            font-size: 1rem;
            line-height: 1.5;
            display: flex; flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        #nprogress {
            position: fixed; top: 0; left: 0; height: 2px; background: var(--accent);
            width: 0%; transition: width 0.3s; z-index: 9999;
            box-shadow: 0 0 12px var(--accent);
        }

        .kbd {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 1.15rem; height: 1.2rem; padding: 0 0.35rem;
            font-family: var(--font-mono); font-size: 0.77rem; font-weight: 600;
            background: rgba(255,255,255,0.04); color: var(--text-dim);
            border: 1px solid var(--border); border-radius: 0.3rem;
            line-height: 1; box-shadow: inset 0 -1px 0 var(--border);
        }
        body.light-mode .kbd { background: #fff; box-shadow: 0 1px 0 var(--border); }

        #topbar {
            display: grid;
            grid-template-columns: 1fr minmax(17rem, 34rem) 1fr;
            align-items: center;
            height: var(--tb-h); flex-shrink: 0;
            padding: 0 1rem; gap: 1rem;
            background: var(--bar);
            border-bottom: 1px solid var(--border);
            position: relative; z-index: 50;
        }
        .tb-section { display: flex; align-items: center; gap: 0.35rem; min-width: 0; }
        .tb-section.right { justify-content: flex-end; }
        .tb-divider { width: 1px; height: 1.3rem; background: var(--border-hi); margin: 0 0.3rem; flex-shrink: 0; }

        .brand {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.3rem 0.6rem 0.3rem 0.3rem; text-decoration: none; color: var(--text);
            border-radius: var(--r-md);
        }
        .brand:hover { background: var(--card-hover); }
        .brand-mark {
            width: 1.9rem; height: 1.9rem; border-radius: 0.5rem;
            background: var(--accent);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 0.82rem; font-weight: 700;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08) inset;
        }
        .brand-text { font-weight: 700; font-size: 1.04rem; letter-spacing: -0.2px; }

        .tb-btn {
            display: inline-flex; align-items: center; gap: 0.45rem;
            height: 2.2rem; padding: 0 0.75rem;
            background: transparent; color: var(--text);
            border: 1px solid transparent; border-radius: var(--r-md);
            cursor: pointer; font-family: inherit; font-size: 0.93rem; font-weight: 500;
            transition: .12s;
        }
        .tb-btn:hover { background: var(--card-hover); }
        .tb-btn i { font-size: 0.82rem; opacity: 0.85; }
        .tb-btn .caret { font-size: 0.65rem; margin-left: 0.05rem; opacity: 0.55; }

        .tb-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 2.2rem; height: 2.2rem;
            background: transparent; color: var(--text-dim);
            border: 1px solid transparent; border-radius: var(--r-md);
            cursor: pointer; text-decoration: none;
            transition: .12s; font-size: 0.89rem;
        }
        .tb-icon:hover { background: var(--card-hover); color: var(--text); }

        .storage-chip {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.3rem 0.75rem 0.3rem 0.3rem;
            background: var(--card); border: 1px solid var(--border);
            border-radius: 100px;
            font-size: 0.85rem; color: var(--text-dim);
        }
        .ring {
            --p: 0%;
            width: 1.65rem; height: 1.65rem; border-radius: 50%;
            background: conic-gradient(var(--accent) var(--p), var(--border-hi) 0);
            position: relative; flex-shrink: 0;
        }
        .ring::after {
            content: ''; position: absolute; inset: 0.22rem;
            background: var(--card); border-radius: 50%;
        }
        .storage-chip .amount { font-family: var(--font-mono); font-weight: 600; color: var(--text); }

        .cmd-pill {
            display: flex; align-items: center; gap: 0.75rem;
            width: 100%; height: 2.2rem;
            padding: 0 0.75rem;
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--r-md); cursor: pointer;
            font-size: 0.93rem; color: var(--text-faint);
            font-family: inherit; transition: .12s;
        }
        .cmd-pill:hover { border-color: var(--border-hi); background: var(--card-hover); }
        .cmd-pill > i { color: var(--text-faint); font-size: 0.82rem; }
        .cmd-pill > span:first-of-type { flex: 1; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cmd-pill .kbd-group { display: inline-flex; gap: 0.15rem; flex-shrink: 0; }

        #subbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.75rem; height: var(--sub-h); flex-shrink: 0;
            padding: 0 1.15rem;
            background: var(--bg); border-bottom: 1px solid var(--border);
            position: relative; z-index: 40;
        }
        .breadcrumb {
            display: flex; align-items: center; flex-wrap: wrap; gap: 0.07rem;
            font-size: 0.96rem; font-weight: 500; color: var(--text-dim); min-width: 0;
        }
        .breadcrumb .crumb {
            padding: 0.22rem 0.5rem; border-radius: var(--r-sm);
            cursor: pointer; transition: .12s; color: var(--text-dim);
        }
        .breadcrumb .crumb:hover { background: var(--accent-soft); color: var(--accent); }
        .breadcrumb .crumb.root { color: var(--text); display: inline-flex; align-items: center; gap: 0.35rem; }
        .breadcrumb .crumb.root i { font-size: 0.82rem; }
        .breadcrumb .crumb.current { color: var(--text); font-weight: 600; }
        .breadcrumb .sep { color: var(--text-faint); user-select: none; padding: 0 0.05rem; font-size: 0.82rem; }

        .sub-actions { display: flex; gap: 0.3rem; align-items: center; flex-shrink: 0; }
        .sub-actions .btn-icon { width: 1.93rem; height: 1.93rem; font-size: 0.82rem; }

        #main {
            flex: 1; display: flex; flex-direction: column;
            overflow: hidden; position: relative;
        }
        .scroll-area { flex: 1; overflow-y: auto; }
        .container { padding: 1rem 1.15rem 1.45rem; max-width: 118rem; width: 100%; margin: 0 auto; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 0.45rem; font-family: inherit; font-weight: 600; font-size: 0.93rem;
            border: 1px solid transparent; border-radius: var(--r-md);
            padding: 0.45rem 0.75rem; cursor: pointer; transition: .12s;
            background: transparent; color: var(--text); white-space: nowrap;
            user-select: none; text-decoration: none;
        }
        .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .btn[disabled] { opacity: 0.4; cursor: not-allowed; }

        .btn-primary { background: var(--accent); color: #fff; border-color: var(--accent); }
        .btn-primary:hover:not([disabled]) { background: var(--accent-2); border-color: var(--accent-2); }
        .btn-ghost { background: var(--bg-tint); border-color: var(--border); color: var(--text); }
        .btn-ghost:hover:not([disabled]) { background: var(--card-hover); border-color: var(--border-hi); }
        .btn-danger { color: var(--danger); }
        .btn-danger:hover:not([disabled]) { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn-block { width: 100%; }

        .btn-icon {
            width: 2.07rem; height: 2.07rem; padding: 0;
            background: var(--bg-tint); border: 1px solid var(--border); color: var(--text);
            font-size: 0.85rem;
        }
        .btn-icon:hover:not([disabled]) { border-color: var(--accent); color: var(--accent); }
        .btn-icon.sm { width: 1.78rem; height: 1.78rem; font-size: 0.78rem; }

        .input {
            width: 100%; padding: 0.5rem 0.75rem;
            background: var(--bg-tint); color: var(--text);
            border: 1px solid var(--border); border-radius: var(--r-md);
            outline: none; font-family: inherit; font-size: 0.96rem;
            transition: border-color .12s;
        }
        .input::placeholder { color: var(--text-faint); }
        .input:focus { border-color: var(--accent); }
        .input.mono { font-family: var(--font-mono); font-size: 0.92rem; }
        .input-group { display: flex; gap: 0.3rem; }
        .input-group .input { flex: 1; }

        .seg-tabs { display: flex; gap: 0.15rem; padding: 0.15rem; background: var(--bg-tint); border-radius: var(--r-md); border: 1px solid var(--border); }
        .seg-tab {
            flex: 1; padding: 0.37rem; border-radius: var(--r-sm);
            font-size: 0.85rem; font-weight: 600; cursor: pointer;
            border: none; background: transparent; color: var(--text-dim);
            transition: .12s; font-family: inherit;
        }
        .seg-tab:hover { color: var(--text); }
        .seg-tab.active { background: var(--card); color: var(--text); }

        .panel {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--r-lg); overflow: hidden;
            position: relative;
        }
        .toolbar {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--border);
            background: var(--bg-tint);
        }
        .search-box {
            flex: 1; max-width: 26rem;
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0 0.65rem; background: var(--card);
            border: 1px solid var(--border); border-radius: var(--r-md);
            transition: border-color .12s;
        }
        .search-box:focus-within { border-color: var(--accent); }
        .search-box i { color: var(--text-faint); font-size: 0.82rem; }
        .search-box input {
            flex: 1; background: transparent; border: none; outline: none;
            color: var(--text); padding: 0.45rem 0; font-family: inherit; font-size: 0.93rem;
        }
        .search-box input::placeholder { color: var(--text-faint); }
        .toolbar-meta { font-family: var(--font-mono); font-size: 0.77rem; color: var(--text-faint); margin-left: auto; }

        table.files {
            width: 100%; border-collapse: separate; border-spacing: 0;
            font-size: 0.96rem;
        }
        table.files thead th {
            text-align: left; padding: 0.5rem 0.9rem;
            font-size: 0.74rem; font-weight: 600; letter-spacing: 0.7px;
            text-transform: uppercase; color: var(--text-faint);
            border-bottom: 1px solid var(--border);
            background: var(--bg-tint); position: sticky; top: 0; z-index: 2;
            user-select: none;
        }
        table.files thead th.sortable { cursor: pointer; transition: color .12s; }
        table.files thead th.sortable:hover { color: var(--accent); }
        table.files thead th .sort-icon { margin-left: 0.3rem; opacity: .35; font-size: 0.6rem; }
        table.files thead th.sort-active { color: var(--accent); }
        table.files thead th.sort-active .sort-icon { opacity: 1; }
        table.files tbody td {
            padding: 0.45rem 0.9rem; border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        table.files tbody tr:last-child td { border-bottom: none; }
        tr.file-row { cursor: pointer; transition: background .1s; }
        tr.file-row:hover { background: var(--card-hover); }
        tr.file-row.selected { background: var(--accent-soft); }
        tr.file-row.selected:hover { background: rgba(139,143,255,0.14); }
        tr.file-row.focused { box-shadow: inset 2px 0 0 var(--accent); }

        .check-cell { width: 2.2rem; padding-right: 0 !important; }
        .check-cell input { width: 0.96rem; height: 0.96rem; cursor: pointer; accent-color: var(--accent); }

        .file-name { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
        .file-icon {
            width: 2.07rem; height: 2.07rem; border-radius: 0.44rem;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.92rem; flex-shrink: 0;
        }
        .file-meta { min-width: 0; }
        .file-meta .name { font-weight: 500; font-size: 0.96rem; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 38rem; line-height: 1.25; }
        .file-meta .sub { font-family: var(--font-mono); font-size: 0.74rem; color: var(--text-faint); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.07rem; }

        .col-size, .col-time { font-family: var(--font-mono); font-size: 0.85rem; color: var(--text-dim); white-space: nowrap; }
        .col-actions { text-align: right; }
        .col-actions .row-buttons { display: inline-flex; gap: 0.22rem; opacity: 0; transition: opacity .12s; }
        tr.file-row:hover .row-buttons,
        tr.file-row.selected .row-buttons { opacity: 1; }

        .empty-state { padding: 3.5rem 1.75rem; text-align: center; color: var(--text-dim); }
        .empty-state .empty-icon { font-size: 2.2rem; margin-bottom: 0.9rem; color: var(--text-faint); opacity: 0.55; }
        .empty-state h3 { color: var(--text); margin-bottom: 0.3rem; font-weight: 600; font-size: 1.11rem; }
        .empty-state p { font-size: 0.93rem; color: var(--text-dim); margin-bottom: 1.2rem; }
        .empty-state .hint {
            display: inline-flex; align-items: center; gap: 0.45rem;
            font-size: 0.82rem; color: var(--text-faint); font-family: var(--font-mono);
        }

        .status-bar {
            display: flex; align-items: center; gap: 0.9rem;
            padding: 0.37rem 1rem; height: var(--sb-h); flex-shrink: 0;
            background: var(--bar); border-top: 1px solid var(--border);
            font-family: var(--font-mono); font-size: 0.77rem;
            color: var(--text-faint);
            position: relative; z-index: 10;
        }
        .status-bar .seg { display: inline-flex; align-items: center; gap: 0.37rem; }
        .status-bar .seg.path { color: var(--text-dim); }
        .status-bar .seg.right { margin-left: auto; }
        .status-bar .seg i { font-size: 0.7rem; opacity: 0.7; }
        .status-bar .dot {
            display: inline-block; width: 0.37rem; height: 0.37rem; border-radius: 50%;
            background: var(--success); box-shadow: 0 0 0.37rem var(--success);
        }

        .popover {
            position: absolute;
            background: var(--card); border: 1px solid var(--border-hi);
            border-radius: var(--r-lg); padding: 0.6rem;
            box-shadow: 0 18px 50px rgba(0,0,0,0.45), 0 0 0 1px rgba(0,0,0,0.2);
            z-index: 4000; width: 19rem;
            opacity: 0; pointer-events: none;
            transform: translateY(-0.3rem) scale(0.98);
            transition: opacity .14s, transform .14s;
        }
        .popover.show { opacity: 1; pointer-events: auto; transform: translateY(0) scale(1); }
        .popover .seg-tabs { margin-bottom: 0.45rem; }

        .upload-panel {
            position: fixed; bottom: 1rem; right: 1rem;
            width: 22rem;
            background: var(--card); border: 1px solid var(--border-hi);
            border-radius: var(--r-lg);
            box-shadow: 0 20px 50px rgba(0,0,0,0.45);
            z-index: 600;
            transform: translateY(calc(100% + 1.5rem)) scale(0.98);
            transition: transform .3s cubic-bezier(0.16,1,0.3,1);
            display: flex; flex-direction: column;
            overflow: hidden;
        }
        .upload-panel.show { transform: translateY(0) scale(1); }
        .up-head {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.6rem 0.75rem; background: var(--bg-tint);
            border-bottom: 1px solid var(--border);
        }
        .up-head .up-title { display: flex; align-items: center; gap: 0.5rem; font-size: 0.93rem; font-weight: 600; flex: 1; }
        .up-head .up-title i { font-size: 0.9rem; color: var(--accent); }
        .up-close {
            background: transparent; border: none; cursor: pointer;
            color: var(--text-faint); width: 1.63rem; height: 1.63rem;
            display: flex; align-items: center; justify-content: center;
            border-radius: var(--r-sm); font-size: 0.9rem;
        }
        .up-close:hover { background: var(--card-hover); color: var(--text); }
        .up-body { padding: 0.75rem; display: flex; flex-direction: column; gap: 0.6rem; }

        .drop-zone {
            border: 1.5px dashed var(--border-hi);
            border-radius: var(--r-md);
            padding: 1rem 0.75rem; text-align: center; cursor: pointer;
            transition: .12s;
        }
        .drop-zone:hover, .drop-zone.drag-over { border-color: var(--accent); background: var(--accent-soft); }
        .drop-zone .dz-icon { font-size: 1.33rem; color: var(--text-faint); margin-bottom: 0.37rem; }
        .drop-zone .dz-label { font-size: 0.85rem; color: var(--text-dim); line-height: 1.35; }
        .drop-zone .dz-label strong { color: var(--text); font-weight: 600; }
        .drop-zone input[type="file"] { display: none; }

        #upload-queue { max-height: 8rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.15rem; }
        .queue-item {
            display: flex; align-items: center; gap: 0.45rem;
            font-size: 0.77rem; padding: 0.3rem 0.5rem; border-radius: var(--r-sm);
            background: var(--bg-tint);
        }
        .qi-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-dim); font-family: var(--font-mono); font-size: 0.74rem; }
        .qi-size { color: var(--text-faint); font-size: 0.7rem; white-space: nowrap; font-family: var(--font-mono); }
        .qi-status { font-size: 0.82rem; width: 0.89rem; text-align: center; }
        .qi-status.ok { color: var(--success); }
        .qi-status.fail { color: var(--danger); }

        #upload-overall-bar { height: 0.22rem; border-radius: 0.22rem; background: var(--bg-tint); overflow: hidden; display: none; }
        #upload-overall-fill { height: 100%; width: 0%; background: var(--accent); transition: width .2s; }
        #upload-status-text { font-size: 0.74rem; color: var(--text-faint); text-align: center; min-height: 0.89rem; font-family: var(--font-mono); }
        .queue-clear { font-size: 0.74rem; color: var(--danger); cursor: pointer; background: none; border: none; font-family: inherit; padding: 0; display: none; align-self: flex-start; }

        #bulk-bar {
            position: fixed; bottom: 2.8rem; left: 50%;
            transform: translate(-50%, 140%);
            background: var(--card);
            border: 1px solid var(--border-hi);
            border-radius: 100px; padding: 0.3rem 0.3rem 0.3rem 1rem;
            display: flex; align-items: center; gap: 0.6rem;
            box-shadow: 0 18px 40px rgba(0,0,0,0.45);
            transition: transform .3s cubic-bezier(0.16,1,0.3,1);
            z-index: 600; backdrop-filter: blur(10px);
        }
        #bulk-bar.show { transform: translate(-50%, 0); }
        #bulk-bar .count { font-weight: 600; font-size: 0.93rem; color: var(--text); }
        #bulk-bar .count em { color: var(--accent); font-style: normal; font-family: var(--font-mono); margin-right: 0.3rem; font-weight: 700; }
        #bulk-bar .btn { border-radius: 100px; height: 2.07rem; padding: 0 0.9rem; font-size: 0.89rem; }
        #bulk-bar .btn-icon { border-radius: 50%; width: 2.07rem; height: 2.07rem; }

        .ctx-menu {
            position: fixed; min-width: 14rem;
            background: var(--card); border: 1px solid var(--border-hi);
            border-radius: var(--r-md); padding: 0.22rem;
            box-shadow: 0 18px 40px rgba(0,0,0,0.45);
            z-index: 5000; display: none;
        }
        .ctx-menu.show { display: block; animation: fadeScale .12s ease; }
        @keyframes fadeScale { from { opacity: 0; transform: scale(0.97) translateY(-3px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .ctx-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.45rem 0.65rem; cursor: pointer; border-radius: var(--r-sm);
            font-size: 0.93rem; color: var(--text); transition: background .1s;
            user-select: none;
        }
        .ctx-item:hover { background: var(--accent-soft); color: var(--accent); }
        .ctx-item.danger:hover { background: rgba(248,113,113,0.1); color: var(--danger); }
        .ctx-item i { width: 0.9rem; text-align: center; font-size: 0.82rem; opacity: 0.85; }
        .ctx-item .ctx-kbd { margin-left: auto; display: flex; gap: 0.15rem; }
        .ctx-divider { height: 1px; background: var(--border); margin: 0.22rem 0; }

        #editor-container, #preview-container {
            position: fixed; top: 0; right: -100%; width: 60%; height: 100vh;
            background: var(--bar);
            border-left: 1px solid var(--border-hi);
            z-index: 800;
            transition: right .35s cubic-bezier(0.16,1,0.3,1);
            display: flex; flex-direction: column;
            box-shadow: -20px 0 50px rgba(0,0,0,0.45);
        }
        #editor-container.active, #preview-container.active { right: 0; }
        .panel-head {
            padding: 0.5rem 0.9rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            gap: 0.75rem; background: var(--bg-tint);
            min-height: 3.1rem;
        }
        .panel-head .title {
            display: flex; align-items: center; gap: 0.65rem; min-width: 0; flex: 1;
        }
        .panel-head .title h3 {
            font-size: 0.96rem; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            font-family: var(--font-mono);
        }
        .lang-badge {
            font-family: var(--font-mono); font-size: 0.67rem; font-weight: 700;
            background: var(--accent); color: #fff; padding: 0.15rem 0.37rem;
            border-radius: 0.22rem; text-transform: uppercase; letter-spacing: 0.6px;
        }
        .dirty-dot {
            width: 0.45rem; height: 0.45rem; border-radius: 50%;
            background: var(--warning); box-shadow: 0 0 0.45rem var(--warning);
            visibility: hidden; flex-shrink: 0;
        }
        .dirty-dot.show { visibility: visible; }
        .CodeMirror {
            flex: 1; font-size: 0.96rem;
            font-family: var(--font-mono); background: transparent !important;
        }
        .CodeMirror-gutters { background: transparent !important; border-right: 1px solid var(--border) !important; }
        .editor-status {
            padding: 0.3rem 1rem; border-top: 1px solid var(--border);
            font-family: var(--font-mono); font-size: 0.74rem;
            color: var(--text-faint); display: flex; justify-content: space-between;
            background: var(--bg-tint);
        }

        #preview-content { flex: 1; display: flex; align-items: center; justify-content: center; padding: 1.5rem; overflow: auto; background: var(--bg); }
        #preview-content img { max-width: 100%; max-height: 100%; border-radius: var(--r-md); box-shadow: 0 16px 40px rgba(0,0,0,0.45); }
        #preview-content video, #preview-content audio { max-width: 100%; max-height: 100%; }
        #preview-content audio { width: 80%; }

        .modal-backdrop {
            position: fixed; inset: 0; z-index: 7000;
            background: rgba(8, 9, 14, 0.7);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            padding: 1.3rem;
            opacity: 0; pointer-events: none;
            transition: opacity .14s;
        }
        .modal-backdrop.show { opacity: 1; pointer-events: auto; }
        .modal {
            background: var(--card); border: 1px solid var(--border-hi);
            border-radius: var(--r-xl);
            padding: 1.3rem; min-width: 22rem; max-width: 31rem; width: 100%;
            box-shadow: 0 20px 70px rgba(0,0,0,0.55);
            transform: scale(0.96) translateY(8px); opacity: 0;
            transition: transform .22s cubic-bezier(0.16,1,0.3,1), opacity .18s;
        }
        .modal-backdrop.show .modal { transform: scale(1) translateY(0); opacity: 1; }

        .modal .m-icon {
            width: 2.37rem; height: 2.37rem; border-radius: 0.6rem;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.04rem; margin-bottom: 0.75rem;
            background: var(--accent-soft); color: var(--accent);
        }
        .modal.danger .m-icon { background: rgba(248,113,113,0.12); color: var(--danger); }
        .modal.warning .m-icon { background: rgba(251,191,36,0.12); color: var(--warning); }
        .modal h2 { font-size: 1.11rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text); }
        .modal p { font-size: 0.96rem; color: var(--text-dim); line-height: 1.5; margin-bottom: 0.9rem; word-wrap: break-word; }
        .modal p code, .modal p .code { font-family: var(--font-mono); font-size: 0.89rem; background: var(--bg-tint); padding: 0.07rem 0.37rem; border-radius: 0.22rem; border: 1px solid var(--border); color: var(--text); word-break: break-all; }
        .modal .m-input { margin-bottom: 0.9rem; padding: 0.5rem 0.75rem; font-size: 0.96rem; }
        .modal .m-actions { display: flex; gap: 0.45rem; justify-content: flex-end; }
        .modal .m-actions .btn { min-width: 5.6rem; padding: 0.5rem 0.9rem; }

        .palette-backdrop {
            position: fixed; inset: 0; z-index: 7500;
            background: rgba(8, 9, 14, 0.7);
            backdrop-filter: blur(8px);
            display: flex; justify-content: center; padding-top: 10vh;
            opacity: 0; pointer-events: none; transition: opacity .12s;
        }
        .palette-backdrop.show { opacity: 1; pointer-events: auto; }
        .palette {
            width: 100%; max-width: 40rem; height: fit-content;
            background: var(--card); border: 1px solid var(--border-hi);
            border-radius: var(--r-xl); overflow: hidden;
            box-shadow: 0 25px 70px rgba(0,0,0,0.6);
            transform: scale(0.97) translateY(-8px); opacity: 0;
            transition: transform .18s cubic-bezier(0.16,1,0.3,1), opacity .12s;
        }
        .palette-backdrop.show .palette { transform: scale(1) translateY(0); opacity: 1; }
        .palette-input {
            display: flex; align-items: center; gap: 0.75rem; padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border);
        }
        .palette-input i { color: var(--text-faint); font-size: 0.96rem; }
        .palette-input input {
            flex: 1; background: transparent; border: none; outline: none;
            color: var(--text); font-size: 1.04rem; font-family: inherit;
        }
        .palette-input input::placeholder { color: var(--text-faint); }
        .palette-input .kbd { margin-left: auto; }

        .palette-list { max-height: 50vh; overflow-y: auto; padding: 0.37rem 0; }
        .palette-group {
            font-size: 0.7rem; font-weight: 600;
            letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-faint);
            padding: 0.5rem 1rem 0.22rem;
        }
        .palette-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.5rem 1rem; cursor: pointer; transition: background .1s;
        }
        .palette-item:hover, .palette-item.active { background: var(--accent-soft); }
        .palette-item.active { box-shadow: inset 2px 0 0 var(--accent); }
        .palette-item .p-icon {
            width: 1.78rem; height: 1.78rem; border-radius: 0.37rem; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--bg-tint); color: var(--text-dim); font-size: 0.82rem;
        }
        .palette-item.active .p-icon { background: var(--accent-soft); color: var(--accent); }
        .palette-item .p-text { flex: 1; min-width: 0; }
        .palette-item .p-title { font-size: 0.96rem; font-weight: 500; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .palette-item .p-desc { font-size: 0.77rem; color: var(--text-faint); font-family: var(--font-mono); }
        .palette-empty { padding: 1.75rem; text-align: center; color: var(--text-faint); font-size: 0.93rem; }

        .sc-backdrop {
            position: fixed; inset: 0; z-index: 7200;
            background: rgba(8,9,14,0.6); backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; opacity: 0; pointer-events: none;
            transition: opacity .12s;
        }
        .sc-backdrop.show { opacity: 1; pointer-events: auto; }
        .sc-panel {
            background: var(--card); border: 1px solid var(--border-hi);
            border-radius: var(--r-xl); padding: 1.3rem;
            width: 100%; max-width: 43rem; max-height: 80vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            transform: scale(0.97); opacity: 0; transition: .18s;
        }
        .sc-backdrop.show .sc-panel { transform: scale(1); opacity: 1; }
        .sc-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border); }
        .sc-head h2 { font-size: 1.07rem; font-weight: 700; display: flex; align-items: center; gap: 0.6rem; }
        .sc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.3rem 1.5rem; }
        .sc-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.3rem 0; font-size: 0.93rem; }
        .sc-row .label { color: var(--text-dim); }
        .sc-row .keys { display: flex; gap: 0.22rem; flex-shrink: 0; }
        .sc-section-title {
            grid-column: 1 / -1; font-size: 0.7rem; font-weight: 600; letter-spacing: 1.2px;
            text-transform: uppercase; color: var(--text-faint);
            margin-top: 0.75rem; padding-bottom: 0.22rem;
        }
        .sc-section-title:first-child { margin-top: 0; }

        .toast-host {
            position: fixed; bottom: 1.15rem; right: 1.15rem; z-index: 9000;
            display: flex; flex-direction: column; gap: 0.45rem;
            pointer-events: none;
        }
        .upload-panel.show ~ .toast-host { bottom: 16rem; }
        .toast {
            background: var(--card); border: 1px solid var(--border-hi);
            border-left: 3px solid var(--accent);
            padding: 0.65rem 0.9rem; border-radius: var(--r-md);
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            font-size: 0.93rem; font-weight: 500; color: var(--text);
            min-width: 16rem; max-width: 25rem;
            display: flex; align-items: center; gap: 0.6rem;
            transform: translateX(120%); transition: transform .28s cubic-bezier(0.16,1,0.3,1);
            pointer-events: auto;
        }
        .toast.show { transform: translateX(0); }
        .toast.success { border-left-color: var(--success); }
        .toast.success .t-icon { color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        .toast.error .t-icon { color: var(--danger); }
        .toast .t-icon { font-size: 0.96rem; color: var(--accent); }

        #table-drop-overlay {
            position: absolute; inset: 0; z-index: 50;
            background: rgba(139,143,255,0.08);
            border: 2px dashed var(--accent); border-radius: var(--r-lg);
            display: none; align-items: center; justify-content: center;
            font-size: 1.04rem; font-weight: 700; color: var(--accent);
            pointer-events: none;
            font-family: var(--font-mono);
            letter-spacing: 1px;
        }
        #table-drop-overlay.show { display: flex; }

        @media (max-width: 920px) {
            #topbar { grid-template-columns: auto 1fr auto; padding: 0 0.6rem; gap: 0.5rem; }
            .tb-section.tb-center { min-width: 0; }
            .cmd-pill > span:first-of-type { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .cmd-pill .kbd-group { display: none; }
            .brand-text { display: none; }
            .tb-btn span { display: none; }
            .tb-btn { padding: 0 0.5rem; }
            .tb-btn .caret { display: none; }
            .storage-chip .amount { display: none; }
            #editor-container, #preview-container { width: 100%; }
            table.files thead th.col-time, table.files tbody td.col-time { display: none; }
        }
        @media (max-width: 600px) {
            .tb-divider { display: none; }
            .storage-chip { padding: 0.3rem; }
            #subbar { padding: 0 0.75rem; }
            table.files thead th.col-size, table.files tbody td.col-size { display: none; }
            table.files tbody td, table.files thead th { padding-left: 0.65rem; padding-right: 0.65rem; }
            .upload-panel { width: calc(100% - 1.5rem); right: 0.75rem; bottom: 2.7rem; }
        }

        .hide { display: none !important; }
    </style>
</head>
<body class="dark-mode">

<div id="nprogress"></div>

<header id="topbar">
    <div class="tb-section">
        <a class="brand" href="?dir=" onclick="event.preventDefault(); navigatePath('');">
            <div class="brand-mark"><i class="fas fa-terminal"></i></div>
            <span class="brand-text">Editor</span>
        </a>
        <div class="tb-divider"></div>
        <button class="tb-btn" id="btn-new" onclick="toggleNewPopover(event)">
            <i class="fas fa-plus"></i> <span>New</span> <i class="fas fa-chevron-down caret"></i>
        </button>
        <button class="tb-btn" onclick="openUploadPanel()">
            <i class="fas fa-cloud-arrow-up"></i> <span>Upload</span>
        </button>
    </div>

    <div class="tb-section tb-center">
        <button class="cmd-pill" onclick="openPalette()">
            <i class="fas fa-magnifying-glass"></i>
            <span>Search files and commands…</span>
            <span class="kbd-group"><span class="kbd">⌘</span><span class="kbd">K</span></span>
        </button>
    </div>

    <div class="tb-section right">
        <div class="storage-chip" title="<?= formatBytes($total_used) ?> of <?= formatBytes($disk_limit) ?>">
            <div class="ring" style="--p: <?= $disk_perc ?>%"></div>
            <span class="amount"><?= formatBytes($total_used) ?></span>
        </div>
        <div class="tb-divider"></div>
        <a class="tb-icon" href="YOUR-LINK-CUSTOM" title="YOUR-CUSTOM-LINK">
            <i class="fas fa-cube"></i>
        </a>
        <button class="tb-icon" onclick="openShortcuts()" title="Shortcuts (?)"><i class="fas fa-keyboard"></i></button>
        <button class="tb-icon" onclick="toggleTheme()" title="Theme (t)"><i class="fas fa-circle-half-stroke"></i></button>
        <a class="tb-icon" href="logout.php" title="Logout"><i class="fas fa-arrow-right-from-bracket"></i></a>
    </div>
</header>

<div id="subbar">
    <nav class="breadcrumb" id="breadcrumb">
        <span class="crumb root" onclick="navigatePath('')"><i class="fas fa-house"></i>root</span>
        <?php
            $path_acc = '';
            $parts = array_filter(explode(DIRECTORY_SEPARATOR, $current_relative_dir));
            $partsArr = array_values($parts);
            $lastIdx = count($partsArr) - 1;
            foreach ($partsArr as $idx => $part):
                $path_acc .= ($path_acc ? DIRECTORY_SEPARATOR : '') . $part;
                $isCurrent = $idx === $lastIdx;
        ?>
            <span class="sep">/</span>
            <span class="crumb <?= $isCurrent ? 'current' : '' ?>" onclick="navigatePath('<?= urlencode($path_acc) ?>')"><?= htmlspecialchars($part) ?></span>
        <?php endforeach; ?>
    </nav>
    <div class="sub-actions">
        <?php if ($current_relative_dir): ?>
            <button class="btn btn-ghost btn-icon" onclick="navigatePath('<?= urlencode($parent_path) ?>')" title="Up (u)">
                <i class="fas fa-arrow-up"></i>
            </button>
        <?php endif; ?>
        <button class="btn btn-ghost btn-icon" onclick="navigatePath(encodeURIComponent(currentDir))" title="Refresh (r)">
            <i class="fas fa-rotate"></i>
        </button>
    </div>
</div>

<main id="main">
    <div class="scroll-area">
        <div class="container">
            <div class="panel" id="files-panel">
                <div id="table-drop-overlay"><i class="fas fa-cloud-arrow-up" style="margin-right: 0.65rem;"></i> Drop to upload</div>

                <div class="toolbar">
                    <div class="search-box">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="search" id="fileSearch" placeholder="Filter…" autocomplete="off">
                        <span class="kbd">/</span>
                    </div>
                    <span class="toolbar-meta" id="filter-count"></span>
                </div>

                <?php if (empty($items)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
                        <h3>Empty directory</h3>
                        <p>Drag files here, or press <span class="kbd">n</span> to create one.</p>
                        <span class="hint">
                            <span class="kbd">⌘</span><span class="kbd">K</span> for the command palette
                        </span>
                    </div>
                <?php else: ?>
                <table class="files" id="file-table">
                    <thead>
                        <tr>
                            <th class="check-cell"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this.checked)"></th>
                            <th class="sortable sort-active" data-sort="name">Name <i class="fas fa-arrow-down sort-icon"></i></th>
                            <th class="sortable col-size" data-sort="size">Size <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable col-time" data-sort="modified">Modified <i class="fas fa-sort sort-icon"></i></th>
                            <th class="col-actions">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody id="file-list-body">
                        <?php foreach($items as $item):
                            $item_path = $current_dir . DIRECTORY_SEPARATOR . $item;
                            $is_dir = is_dir($item_path);
                            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                            $is_img = in_array($ext, ['png','jpg','jpeg','svg','gif','webp','bmp','ico']);
                            $is_media = in_array($ext, ['mp4','webm','mov','mp3','wav','ogg','flac','m4a']);
                            list($icon_cls, $icon_color) = fileIconMeta($ext, $is_dir);
                            $rgb = hexToRgb($icon_color);
                            $size = $is_dir ? 0 : (file_exists($item_path) ? filesize($item_path) : 0);
                            $mtime = file_exists($item_path) ? filemtime($item_path) : 0;
                            $child_path = ($current_relative_dir ? $current_relative_dir . DIRECTORY_SEPARATOR : '') . $item;
                        ?>
                        <tr class="file-row"
                            data-name="<?= htmlspecialchars($item, ENT_QUOTES) ?>"
                            data-is-dir="<?= $is_dir ? '1' : '0' ?>"
                            data-size="<?= $size ?>"
                            data-mtime="<?= $mtime ?>"
                            data-ext="<?= htmlspecialchars($ext, ENT_QUOTES) ?>"
                            data-img="<?= $is_img ? '1' : '0' ?>"
                            data-media="<?= $is_media ? '1' : '0' ?>"
                            data-child="<?= htmlspecialchars($child_path, ENT_QUOTES) ?>"
                            oncontextmenu="showContextMenu(event, this)">
                            <td class="check-cell" onclick="event.stopPropagation()">
                                <input type="checkbox" class="row-check" onclick="toggleSelect(this)">
                            </td>
                            <td onclick="onRowClick(this)">
                                <div class="file-name">
                                    <div class="file-icon" style="background: rgba(<?= $rgb ?>, 0.13); color: <?= $icon_color ?>;">
                                        <i class="<?= $icon_cls ?>"></i>
                                    </div>
                                    <div class="file-meta">
                                        <div class="name"><?= htmlspecialchars($item) ?></div>
                                        <div class="sub">
                                            <?php if ($is_dir): ?>
                                                <?= countFolderItems($item_path) ?> items
                                            <?php else: ?>
                                                <?= $ext ?: 'file' ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="col-size" onclick="onRowClick(this.closest('tr').children[1])">
                                <?= $is_dir ? '—' : formatBytes($size) ?>
                            </td>
                            <td class="col-time" onclick="onRowClick(this.closest('tr').children[1])" title="<?= date('Y-m-d H:i:s', $mtime) ?>">
                                <?= date('M j, Y', $mtime) ?>
                            </td>
                            <td class="col-actions" onclick="event.stopPropagation()">
                                <div class="row-buttons">
                                    <?php if(!$is_dir): ?>
                                        <a href="?dir=<?=urlencode($current_relative_dir)?>&download=<?=urlencode($item)?>" class="btn btn-icon sm" title="Download"><i class="fas fa-download"></i></a>
                                    <?php endif; ?>
                                    <button class="btn btn-icon sm" onclick="renameObject('<?= htmlspecialchars(addslashes($item), ENT_QUOTES) ?>')" title="Rename (F2)"><i class="fas fa-pen"></i></button>
                                    <button class="btn btn-icon sm btn-danger" onclick="deleteObject('<?= htmlspecialchars(addslashes($item), ENT_QUOTES) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div class="status-bar" id="status-bar">
    <span class="seg"><span class="dot"></span> ready</span>
    <span class="seg path"><i class="fas fa-folder"></i> <span id="sb-path"><?= htmlspecialchars($current_relative_dir ?: '~') ?></span></span>
    <span class="seg"><i class="fas fa-cubes"></i> <span id="sb-count"><?= count($items) ?></span> items</span>
    <span class="seg"><i class="fas fa-database"></i> <?= formatBytes($current_size) ?></span>
    <span class="seg right"><span class="kbd">?</span> help</span>
    <span class="seg"><span class="kbd">⌘</span><span class="kbd">K</span></span>
</div>


<div class="popover" id="new-popover">
    <div class="seg-tabs">
        <button class="seg-tab active" data-mode="file" onclick="setCreateMode('file')">File</button>
        <button class="seg-tab" data-mode="folder" onclick="setCreateMode('folder')">Folder</button>
    </div>
    <form id="create-form" class="ajax-form" data-action="add_file" autocomplete="off">
        <div class="input-group">
            <input type="text" name="new_file_name" id="create-input" placeholder="filename.ext" class="input mono" required>
            <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i></button>
        </div>
    </form>
</div>

<div class="upload-panel" id="upload-panel">
    <div class="up-head">
        <div class="up-title"><i class="fas fa-cloud-arrow-up"></i> <span id="upload-title-text">Upload</span></div>
        <button class="up-close" onclick="closeUploadPanel()" title="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="up-body">
        <div class="seg-tabs">
            <button class="seg-tab active" id="tab-files" onclick="setUploadMode('files')">Files</button>
            <button class="seg-tab" id="tab-folder" onclick="setUploadMode('folder')">Folder</button>
        </div>
        <div class="drop-zone" id="drop-zone"
             ondragover="dzDragOver(event)" ondragleave="dzDragLeave(event)" ondrop="dzDrop(event)"
             onclick="dzClick()">
            <div class="dz-icon"><i class="fas fa-cloud-arrow-up"></i></div>
            <div class="dz-label" id="dz-label">
                <strong>Drop or click</strong>
            </div>
            <input type="file" id="input-files" multiple onchange="dzFilesSelected(this.files)">
            <input type="file" id="input-folder" webkitdirectory mozdirectory multiple onchange="dzFilesSelected(this.files, true)">
        </div>
        <button class="queue-clear" id="queue-clear" onclick="clearQueue()">✕ clear queue</button>
        <div id="upload-queue"></div>
        <div id="upload-overall-bar"><div id="upload-overall-fill"></div></div>
        <div id="upload-status-text"></div>
        <button class="btn btn-primary btn-block" id="btn-upload-go" disabled onclick="startUpload()">
            <i class="fas fa-upload" id="btn-upload-icon"></i> <span id="btn-upload-label">Upload</span>
        </button>
    </div>
</div>

<div id="bulk-bar">
    <span class="count"><em id="bulk-count">0</em>selected</span>
    <button class="btn btn-ghost" onclick="bulkZip()" title="Compress (z)"><i class="fas fa-file-zipper"></i> Zip</button>
    <button class="btn btn-ghost btn-danger" onclick="bulkDelete()" title="Delete"><i class="fas fa-trash"></i> Delete</button>
    <button class="btn btn-icon" onclick="clearSelection()" title="Clear (Esc)"><i class="fas fa-times"></i></button>
</div>

<div class="ctx-menu" id="ctx-menu">
    <div class="ctx-item" data-action="open"><i class="fas fa-arrow-up-right-from-square"></i> Open <span class="ctx-kbd"><span class="kbd">↵</span></span></div>
    <div class="ctx-item" data-action="download"><i class="fas fa-download"></i> Download</div>
    <div class="ctx-item" data-action="rename"><i class="fas fa-pen"></i> Rename <span class="ctx-kbd"><span class="kbd">F2</span></span></div>
    <div class="ctx-item" data-action="clone"><i class="fas fa-copy"></i> Duplicate <span class="ctx-kbd"><span class="kbd">c</span></span></div>
    <div class="ctx-item" data-action="zip"><i class="fas fa-file-zipper"></i> Compress <span class="ctx-kbd"><span class="kbd">z</span></span></div>
    <div class="ctx-item" data-action="extract"><i class="fas fa-box-open"></i> Extract</div>
    <div class="ctx-divider"></div>
    <div class="ctx-item danger" data-action="delete"><i class="fas fa-trash"></i> Delete <span class="ctx-kbd"><span class="kbd">Del</span></span></div>
</div>

<div id="preview-container">
    <div class="panel-head">
        <div class="title">
            <h3 id="preview-filename">Preview</h3>
        </div>
        <div style="display:flex; gap:0.37rem;">
            <a id="preview-download" class="btn btn-ghost btn-icon" href="#" title="Download"><i class="fas fa-download"></i></a>
            <button class="btn btn-ghost btn-icon" onclick="closePreview()"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div id="preview-content"></div>
</div>

<div id="editor-container">
    <div class="panel-head">
        <div class="title">
            <span class="lang-badge" id="lang-badge">TXT</span>
            <h3 id="editing-filename-text">filename.js</h3>
            <span class="dirty-dot" id="dirty-dot" title="Unsaved changes"></span>
        </div>
        <div style="display:flex; gap:0.37rem;">
            <button class="btn btn-primary" onclick="saveFile()"><i class="fas fa-save"></i> Save</button>
            <button class="btn btn-ghost btn-icon" onclick="closeEditor()"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <textarea id="fileEditor"></textarea>
    <div class="editor-status">
        <span id="editor-stats">Ln 1, Col 1</span>
        <span id="editor-info"></span>
    </div>
</div>

<div class="palette-backdrop" id="palette-bd">
    <div class="palette">
        <div class="palette-input">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="palette-input" placeholder="Type a command or search files…" autocomplete="off">
            <span class="kbd">Esc</span>
        </div>
        <div class="palette-list" id="palette-list"></div>
    </div>
</div>

<div class="sc-backdrop" id="sc-bd">
    <div class="sc-panel">
        <div class="sc-head">
            <h2><i class="fas fa-keyboard"></i> Keyboard Shortcuts</h2>
            <button class="btn btn-ghost btn-icon" onclick="closeShortcuts()"><i class="fas fa-times"></i></button>
        </div>
        <div class="sc-grid" id="sc-grid"></div>
    </div>
</div>

<div id="modal-host"></div>
<div class="toast-host" id="toast-host"></div>

<script>

let currentDir = '<?= addslashes($current_relative_dir) ?>';
let cmEditor   = null;
let editorDirty = false;
let currentFile = null;
let originalContent = '';

let uploadMode  = 'files';
let createMode  = 'file';
let uploadQueue = [];

let selectedItems = new Set();
let focusedRowIndex = -1;
let ctxTarget = null;

let currentSort = { key: 'name', dir: 'asc' };

const MODE_MAP = {
    js:'javascript', mjs:'javascript', cjs:'javascript', jsx:'jsx',
    ts:{name:'javascript',typescript:true}, tsx:{name:'jsx',typescript:true},
    json:{name:'javascript',json:true},
    html:'htmlmixed', htm:'htmlmixed', vue:'htmlmixed', svelte:'htmlmixed',
    xml:'xml', svg:'xml',
    css:'css', scss:'css', sass:'css', less:'css',
    php:'application/x-httpd-php', phtml:'application/x-httpd-php',
    py:'python', rb:'ruby', go:'go', rs:'rust',
    java:'text/x-java',
    c:'text/x-csrc', h:'text/x-csrc',
    cpp:'text/x-c++src', hpp:'text/x-c++src', cc:'text/x-c++src',
    cs:'text/x-csharp',
    sh:'shell', bash:'shell', zsh:'shell',
    sql:'sql', md:'markdown', mdx:'markdown',
    yaml:'yaml', yml:'yaml',
};
const modeFor = (filename) => {
    const ext = (filename.split('.').pop() || '').toLowerCase();
    return { mode: MODE_MAP[ext] || 'text/plain', badge: ext.toUpperCase() || 'TXT' };
};
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const formatBytesJS = (b) => {
    const u=['B','KB','MB','GB','TB']; let i=0;
    while (b >= 1024 && i < u.length-1) { b /= 1024; i++; }
    return b.toFixed(1) + ' ' + u[i];
};

const nx = (() => {
    function build({ title, message, kind = 'info', input = null, buttons = [] }) {
        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        const iconMap = { info: 'fa-circle-info', warning: 'fa-triangle-exclamation', danger: 'fa-triangle-exclamation' };
        const icon = iconMap[kind] || 'fa-circle-info';
        bd.innerHTML = `
            <div class="modal ${kind}">
                ${title ? `<div class="m-icon"><i class="fas ${icon}"></i></div>` : ''}
                ${title ? `<h2>${escapeHtml(title)}</h2>` : ''}
                ${message ? `<p>${message}</p>` : ''}
                ${input ? `<input class="input m-input mono" type="text" value="${escapeHtml(input.value || '')}" placeholder="${escapeHtml(input.placeholder || '')}">` : ''}
                <div class="m-actions">
                    ${buttons.map(b => `<button class="btn ${b.kind === 'primary' ? 'btn-primary' : b.kind === 'danger' ? 'btn-ghost btn-danger' : 'btn-ghost'}" data-val="${b.id}">${escapeHtml(b.label)}</button>`).join('')}
                </div>
            </div>`;
        document.getElementById('modal-host').appendChild(bd);
        return bd;
    }

    function open(opts) {
        return new Promise(resolve => {
            const bd = build(opts);
            const inp = bd.querySelector('input.m-input');
            let done = false;
            const close = (val) => {
                if (done) return; done = true;
                bd.classList.remove('show');
                document.removeEventListener('keydown', onKey, true);
                setTimeout(() => bd.remove(), 220);
                resolve({ value: val, input: inp ? inp.value : undefined });
            };
            bd.querySelectorAll('button[data-val]').forEach(b => {
                b.addEventListener('click', () => close(b.dataset.val));
            });
            bd.addEventListener('click', (e) => { if (e.target === bd) close(null); });
            const onKey = (e) => {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(null); }
                else if (e.key === 'Enter') {
                    if (inp && e.target !== inp) return;
                    e.preventDefault(); e.stopPropagation();
                    const primary = bd.querySelector('.btn-primary'); if (primary) primary.click();
                }
            };
            document.addEventListener('keydown', onKey, true);
            requestAnimationFrame(() => { bd.classList.add('show'); if (inp) { inp.focus(); inp.select(); } });
        });
    }

    return {
        confirm: async (message, { title = 'Confirm', okLabel = 'Continue', cancelLabel = 'Cancel', danger = false } = {}) => {
            const { value } = await open({
                title, message, kind: danger ? 'danger' : 'warning',
                buttons: [
                    { id: 'cancel', label: cancelLabel, kind: 'ghost' },
                    { id: 'ok',     label: okLabel,     kind: danger ? 'danger' : 'primary' }
                ]
            });
            return value === 'ok';
        },
        prompt: async (message, defaultValue = '', { title = 'Input', okLabel = 'OK', placeholder = '' } = {}) => {
            const { value, input } = await open({
                title, message, kind: 'info',
                input: { value: defaultValue, placeholder },
                buttons: [
                    { id: 'cancel', label: 'Cancel',   kind: 'ghost' },
                    { id: 'ok',     label: okLabel,    kind: 'primary' }
                ]
            });
            return value === 'ok' ? input : null;
        },
        alert: async (message, { title = 'Notice', okLabel = 'OK' } = {}) => {
            await open({
                title, message, kind: 'info',
                buttons: [{ id: 'ok', label: okLabel, kind: 'primary' }]
            });
        }
    };
})();

function toast(msg, success = true) {
    const host = document.getElementById('toast-host');
    const el = document.createElement('div');
    const icon = success ? 'fa-circle-check' : 'fa-circle-xmark';
    el.className = 'toast ' + (success ? 'success' : 'error');
    el.innerHTML = `<i class="fas ${icon} t-icon"></i><span>${escapeHtml(msg)}</span>`;
    host.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 350);
    }, 2800);
}

const startLoading = () => document.getElementById('nprogress').style.width = '70%';
const stopLoading = () => {
    document.getElementById('nprogress').style.width = '100%';
    setTimeout(() => document.getElementById('nprogress').style.width = '0%', 350);
};

window.addEventListener('load', () => {
    cmEditor = CodeMirror.fromTextArea(document.getElementById("fileEditor"), {
        mode: "text/plain",
        theme: document.body.classList.contains('light-mode') ? 'idea' : 'ayu-mirage',
        lineNumbers: true, lineWrapping: true,
        autoCloseBrackets: true, matchBrackets: true,
        viewportMargin: Infinity,
        extraKeys: {
            "Ctrl-S": () => saveFile(),
            "Cmd-S":  () => saveFile(),
            "Ctrl-F": "findPersistent",
            "Cmd-F":  "findPersistent",
        }
    });
    cmEditor.on('change', () => {
        const dirty = cmEditor.getValue() !== originalContent;
        editorDirty = dirty;
        document.getElementById('dirty-dot').classList.toggle('show', dirty);
    });
    cmEditor.on('cursorActivity', updateEditorStats);

    if (localStorage.getItem('nexus-theme') === 'light') {
        document.body.classList.add('light-mode');
        cmEditor.setOption('theme', 'idea');
    }

    bindAjaxForms();
    bindSortHeaders();
    bindTableDrop();
    bindPaletteEvents();
    bindSearchEvents();
    buildShortcutsList();
    applyClientSort();

    document.addEventListener('click', (e) => {
        const pop = document.getElementById('new-popover');
        const btn = document.getElementById('btn-new');
        if (pop.classList.contains('show') && !pop.contains(e.target) && !btn.contains(e.target)) {
            closeNewPopover();
        }
    });
});

function toggleNewPopover(e) {
    e && e.stopPropagation();
    const pop = document.getElementById('new-popover');
    if (pop.classList.contains('show')) return closeNewPopover();
    openNewPopover();
}
function openNewPopover() {
    const pop = document.getElementById('new-popover');
    const btn = document.getElementById('btn-new');
    const r = btn.getBoundingClientRect();
    const popWidth = pop.offsetWidth || 300;
    let left = r.left;
    if (left + popWidth > window.innerWidth - 8) left = window.innerWidth - popWidth - 8;
    pop.style.left = left + 'px';
    pop.style.top = (r.bottom + 6) + 'px';
    pop.classList.add('show');
    setTimeout(() => document.getElementById('create-input').focus(), 50);
}
function closeNewPopover() {
    document.getElementById('new-popover').classList.remove('show');
}

function setCreateMode(mode) {
    createMode = mode;
    document.querySelectorAll('#new-popover .seg-tab[data-mode]').forEach(s => s.classList.toggle('active', s.dataset.mode === mode));
    const input = document.getElementById('create-input');
    const form  = document.getElementById('create-form');
    if (mode === 'folder') {
        input.name = 'new_folder_name';
        input.placeholder = 'folder name';
        form.dataset.action = 'add_folder';
    } else {
        input.name = 'new_file_name';
        input.placeholder = 'filename.ext';
        form.dataset.action = 'add_file';
    }
}

function openUploadPanel() {
    document.getElementById('upload-panel').classList.add('show');
}
function closeUploadPanel() {
    document.getElementById('upload-panel').classList.remove('show');
}

function updateEditorStats() {
    if (!cmEditor) return;
    const cur = cmEditor.getCursor();
    const lines = cmEditor.lineCount();
    const chars = cmEditor.getValue().length;
    document.getElementById('editor-stats').textContent = `Ln ${cur.line + 1}, Col ${cur.ch + 1}`;
    document.getElementById('editor-info').textContent = `${lines} lines · ${formatBytesJS(chars)}`;
}

async function openEditor(filename) {
    startLoading();
    try {
        const res = await fetch(`?dir=${encodeURIComponent(currentDir)}&raw=${encodeURIComponent(filename)}`);
        if (!res.ok) throw new Error('load failed');
        const content = await res.text();
        currentFile = filename;
        originalContent = content;
        editorDirty = false;
        const { mode, badge } = modeFor(filename);
        cmEditor.setOption('mode', mode);
        cmEditor.setValue(content);
        cmEditor.setCursor(0, 0);
        document.getElementById('editing-filename-text').innerText = filename;
        document.getElementById('lang-badge').innerText = badge;
        document.getElementById('dirty-dot').classList.remove('show');
        document.getElementById('editor-container').classList.add('active');
        setTimeout(() => { cmEditor.refresh(); cmEditor.focus(); updateEditorStats(); }, 250);
    } catch (e) {
        toast('Failed to open file', false);
    }
    stopLoading();
}

async function closeEditor() {
    if (editorDirty) {
        const ok = await nx.confirm('You have unsaved changes. Close without saving?', { title: 'Discard changes?', okLabel: 'Discard', danger: true });
        if (!ok) return;
    }
    document.getElementById('editor-container').classList.remove('active');
    editorDirty = false;
    currentFile = null;
    document.getElementById('dirty-dot').classList.remove('show');
}

async function saveFile() {
    if (!currentFile) return;
    startLoading();
    const content = cmEditor.getValue();
    const fd = new FormData();
    fd.append('action', 'save_file');
    fd.append('file_name', currentFile);
    fd.append('file_content', content);
    fd.append('ajax', '1');
    try {
        const res = await fetch(`?dir=${encodeURIComponent(currentDir)}`, { method: 'POST', body: fd });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) {
            originalContent = content;
            editorDirty = false;
            document.getElementById('dirty-dot').classList.remove('show');
        }
    } catch (e) { toast('Save failed', false); }
    stopLoading();
}

function previewMedia(filename, type) {
    const container = document.getElementById('preview-container');
    const content = document.getElementById('preview-content');
    const src = `?dir=${encodeURIComponent(currentDir)}&view=${encodeURIComponent(filename)}`;
    if (type === 'image') content.innerHTML = `<img src="${src}" alt="${escapeHtml(filename)}">`;
    else if (type === 'video') content.innerHTML = `<video src="${src}" controls autoplay></video>`;
    else if (type === 'audio') content.innerHTML = `<audio src="${src}" controls autoplay></audio>`;
    document.getElementById('preview-filename').innerText = filename;
    document.getElementById('preview-download').href = `?dir=${encodeURIComponent(currentDir)}&download=${encodeURIComponent(filename)}`;
    container.classList.add('active');
}
function closePreview() {
    document.getElementById('preview-container').classList.remove('active');
    document.getElementById('preview-content').innerHTML = '';
}

function setUploadMode(mode) {
    uploadMode = mode;
    document.getElementById('tab-files').classList.toggle('active', mode === 'files');
    document.getElementById('tab-folder').classList.toggle('active', mode === 'folder');
    document.getElementById('dz-label').innerHTML = mode === 'folder'
        ? '<strong>Drop folder</strong>'
        : '<strong>Drop or click</strong>';
    clearQueue();
}
function dzClick() { document.getElementById(uploadMode === 'folder' ? 'input-folder' : 'input-files').click(); }
function dzDragOver(e) { e.preventDefault(); document.getElementById('drop-zone').classList.add('drag-over'); }
function dzDragLeave() { document.getElementById('drop-zone').classList.remove('drag-over'); }
function dzDrop(e) {
    e.preventDefault();
    document.getElementById('drop-zone').classList.remove('drag-over');
    handleDataTransfer(e.dataTransfer);
}
async function handleDataTransfer(dt) {
    const items = dt.items;
    if (items && items.length) {
        const entries = [...items].map(i => i.webkitGetAsEntry ? i.webkitGetAsEntry() : null).filter(Boolean);
        if (entries.length) {
            const files = await traverseEntries(entries);
            addToQueue(files);
            return;
        }
    }
    addToQueue([...dt.files].map(f => ({ file: f, relativePath: f.name })));
}
async function traverseEntries(entries, basePath = '') {
    const results = [];
    for (const entry of entries) {
        if (!entry) continue;
        if (entry.isFile) {
            const file = await new Promise(res => entry.file(res));
            results.push({ file, relativePath: (basePath ? basePath + '/' : '') + entry.name });
        } else if (entry.isDirectory) {
            const reader = entry.createReader();
            const readAll = () => new Promise(res => {
                let acc = [];
                const readBatch = () => reader.readEntries(b => { if (!b.length) return res(acc); acc = acc.concat([...b]); readBatch(); });
                readBatch();
            });
            const children = await readAll();
            const sub = await traverseEntries(children, (basePath ? basePath + '/' : '') + entry.name);
            results.push(...sub);
        }
    }
    return results;
}
function dzFilesSelected(fileList, isDir = false) {
    addToQueue([...fileList].map(f => ({ file: f, relativePath: isDir ? (f.webkitRelativePath || f.name) : f.name })));
}
function addToQueue(files) {
    uploadQueue.push(...files); renderQueue();
    document.getElementById('input-files').value = '';
    document.getElementById('input-folder').value = '';
    if (uploadQueue.length > 0) openUploadPanel();
}
function clearQueue() { uploadQueue = []; renderQueue(); }
function renderQueue() {
    const qEl = document.getElementById('upload-queue');
    const go  = document.getElementById('btn-upload-go');
    const clr = document.getElementById('queue-clear');
    const lbl = document.getElementById('btn-upload-label');
    if (uploadQueue.length === 0) {
        qEl.innerHTML = ''; go.disabled = true; clr.style.display = 'none';
        lbl.textContent = 'Upload'; return;
    }
    clr.style.display = 'block'; go.disabled = false;
    lbl.textContent = `Upload ${uploadQueue.length}`;
    qEl.innerHTML = uploadQueue.map((it, i) => `
        <div class="queue-item">
            <span class="qi-name" title="${escapeHtml(it.relativePath)}">${escapeHtml(it.relativePath)}</span>
            <span class="qi-size">${formatBytesJS(it.file.size)}</span>
            <span class="qi-status" id="qi-status-${i}"></span>
        </div>`).join('');
}

async function startUpload() {
    if (!uploadQueue.length) return;
    const go = document.getElementById('btn-upload-go');
    const icon = document.getElementById('btn-upload-icon');
    const barWrap = document.getElementById('upload-overall-bar');
    const barFill = document.getElementById('upload-overall-fill');
    const status  = document.getElementById('upload-status-text');

    go.disabled = true; icon.className = 'fas fa-spinner fa-spin';
    barWrap.style.display = 'block';

    const fd = new FormData();
    fd.append('action', 'upload_file'); fd.append('ajax', '1');
    uploadQueue.forEach(it => {
        fd.append('uploaded_file[]', it.file, it.file.name);
        fd.append('relative_paths[]', it.relativePath);
    });

    await new Promise(resolve => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `?dir=${encodeURIComponent(currentDir)}`);
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                barFill.style.width = pct + '%';
                status.textContent = `${pct}%`;
            }
        };
        xhr.onload = () => {
            try {
                const data = JSON.parse(xhr.responseText);
                barFill.style.width = '100%';
                status.textContent = data.message;
                uploadQueue.forEach((_, i) => {
                    const el = document.getElementById(`qi-status-${i}`);
                    if (el) { el.textContent = '✓'; el.className = 'qi-status ok'; }
                });
                toast(data.message, data.success);
                icon.className = 'fas fa-upload';
                if (data.success) {
                    setTimeout(() => {
                        clearQueue();
                        barWrap.style.display = 'none';
                        barFill.style.width = '0%';
                        status.textContent = '';
                        closeUploadPanel();
                        navigatePath(encodeURIComponent(currentDir));
                    }, 800);
                } else { go.disabled = false; }
            } catch (e) {
                toast('Upload response error', false);
                go.disabled = false; icon.className = 'fas fa-upload';
            }
            resolve();
        };
        xhr.onerror = () => {
            toast('Network error', false);
            status.textContent = 'Failed';
            go.disabled = false; icon.className = 'fas fa-upload';
            resolve();
        };
        xhr.send(fd);
    });
}

function bindTableDrop() {
    const panel = document.getElementById('files-panel');
    if (!panel) return;
    let depth = 0;
    const overlay = document.getElementById('table-drop-overlay');
    panel.addEventListener('dragenter', (e) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        depth++; overlay && overlay.classList.add('show');
    });
    panel.addEventListener('dragleave', () => { depth--; if (depth <= 0) { depth = 0; overlay && overlay.classList.remove('show'); } });
    panel.addEventListener('dragover', (e) => { e.preventDefault(); });
    panel.addEventListener('drop', async (e) => {
        e.preventDefault(); depth = 0; overlay && overlay.classList.remove('show');
        clearQueue(); await handleDataTransfer(e.dataTransfer);
        if (uploadQueue.length) startUpload();
    });
}

async function navigatePath(path) {
    if (editorDirty) {
        const ok = await nx.confirm('Unsaved changes will be lost. Continue?', { title: 'Discard changes?', okLabel: 'Discard', danger: true });
        if (!ok) return;
    }
    startLoading();
    currentDir = decodeURIComponent(path);
    const url = `?dir=${path}`;
    try {
        const res = await fetch(url);
        const html = await res.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newPanel = doc.getElementById('files-panel');
        const oldPanel = document.getElementById('files-panel');
        if (newPanel && oldPanel) {
            oldPanel.innerHTML = newPanel.innerHTML;
            bindTableDrop();
            bindSearchEvents();
        }
        const newBc = doc.getElementById('breadcrumb');
        if (newBc) document.getElementById('breadcrumb').innerHTML = newBc.innerHTML;
        const newSb = doc.getElementById('status-bar');
        if (newSb) document.getElementById('status-bar').innerHTML = newSb.innerHTML;
        const newActions = doc.querySelector('.sub-actions');
        if (newActions) document.querySelector('.sub-actions').innerHTML = newActions.innerHTML;
        const newTitle = doc.querySelector('title');
        if (newTitle) document.title = newTitle.innerText;
        window.history.pushState({path}, '', url);
        clearSelection();
        bindSortHeaders();
        applyClientSort();
        focusedRowIndex = -1;
    } catch (e) {
        toast('Navigation failed', false);
    }
    stopLoading();
}

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    navigatePath(params.get('dir') || '');
});

function onRowClick(cellEl) {
    const row = cellEl.closest('tr');
    openRow(row);
}
function openRow(row) {
    if (!row) return;
    const name  = row.dataset.name;
    const isDir = row.dataset.isDir === '1';
    const isImg = row.dataset.img === '1';
    const isMedia = row.dataset.media === '1';
    const ext = row.dataset.ext;
    if (isDir) navigatePath(encodeURIComponent(row.dataset.child));
    else if (isImg) previewMedia(name, 'image');
    else if (isMedia) previewMedia(name, ['mp3','wav','ogg','flac','m4a'].includes(ext) ? 'audio' : 'video');
    else openEditor(name);
}

function toggleSelect(cb) {
    const row = cb.closest('tr');
    const name = row.dataset.name;
    if (cb.checked) { selectedItems.add(name); row.classList.add('selected'); }
    else { selectedItems.delete(name); row.classList.remove('selected'); }
    updateBulkBar();
}
function toggleSelectAll(checked) {
    document.querySelectorAll('.row-check').forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') { cb.checked = checked; toggleSelect(cb); }
    });
}
function clearSelection() {
    selectedItems.clear();
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
    document.querySelectorAll('tr.selected').forEach(r => r.classList.remove('selected'));
    const sa = document.getElementById('select-all'); if (sa) sa.checked = false;
    updateBulkBar();
}
function updateBulkBar() {
    const c = document.getElementById('bulk-count');
    if (c) c.textContent = selectedItems.size;
    document.getElementById('bulk-bar').classList.toggle('show', selectedItems.size > 0);
}
function updateFocus(idx) {
    document.querySelectorAll('tr.file-row').forEach(r => r.classList.remove('focused'));
    const rows = visibleRows();
    if (rows.length === 0) { focusedRowIndex = -1; return; }
    focusedRowIndex = Math.max(0, Math.min(idx, rows.length - 1));
    rows[focusedRowIndex].classList.add('focused');
    rows[focusedRowIndex].scrollIntoView({ block: 'nearest' });
}
function visibleRows() {
    return [...document.querySelectorAll('tr.file-row')].filter(r => r.style.display !== 'none');
}

async function bulkDelete() {
    if (selectedItems.size === 0) return;
    const list = [...selectedItems];
    const ok = await nx.confirm(`Delete <span class="code">${list.length}</span> item${list.length===1?'':'s'}? This cannot be undone.`,
        { title: 'Delete selection?', okLabel: 'Delete', danger: true });
    if (!ok) return;
    const fd = new FormData();
    fd.append('action', 'bulk_delete');
    fd.append('items', JSON.stringify(list));
    fd.append('ajax', '1');
    await performAction(fd);
    clearSelection();
}
async function bulkZip() {
    if (selectedItems.size === 0) return;
    startLoading();
    const count = selectedItems.size;
    for (const name of [...selectedItems]) {
        const fd = new FormData();
        fd.append('action', 'zip'); fd.append('item_to_zip', name); fd.append('ajax', '1');
        try {
            const r = await fetch(`?dir=${encodeURIComponent(currentDir)}`, { method: 'POST', body: fd });
            await r.json();
        } catch(e) {}
    }
    toast(`Compressed ${count} item${count===1?'':'s'}.`);
    clearSelection();
    stopLoading();
    navigatePath(encodeURIComponent(currentDir));
}

function showContextMenu(e, row) {
    e.preventDefault();
    ctxTarget = row;
    const menu = document.getElementById('ctx-menu');
    const isDir = row.dataset.isDir === '1';
    const ext = row.dataset.ext;
    menu.querySelectorAll('[data-action]').forEach(el => el.style.display = '');
    if (isDir) {
        menu.querySelector('[data-action="download"]').style.display = 'none';
        menu.querySelector('[data-action="extract"]').style.display = 'none';
    } else if (ext !== 'zip') {
        menu.querySelector('[data-action="extract"]').style.display = 'none';
    }
    menu.style.left = e.clientX + 'px';
    menu.style.top  = e.clientY + 'px';
    menu.classList.add('show');
    requestAnimationFrame(() => {
        const r = menu.getBoundingClientRect();
        if (r.right > window.innerWidth)  menu.style.left = (window.innerWidth  - r.width  - 8) + 'px';
        if (r.bottom > window.innerHeight) menu.style.top = (window.innerHeight - r.height - 8) + 'px';
    });
}
function closeContextMenu() { document.getElementById('ctx-menu').classList.remove('show'); ctxTarget = null; }
document.addEventListener('click', closeContextMenu);
document.getElementById('ctx-menu').addEventListener('click', (e) => {
    const item = e.target.closest('.ctx-item');
    if (!item || !ctxTarget) return;
    const action = item.dataset.action;
    const name = ctxTarget.dataset.name;
    switch (action) {
        case 'open': openRow(ctxTarget); break;
        case 'download': window.location.href = `?dir=${encodeURIComponent(currentDir)}&download=${encodeURIComponent(name)}`; break;
        case 'rename': renameObject(name); break;
        case 'clone':  cloneObject(name); break;
        case 'zip':    zipObject(name); break;
        case 'extract': extractZip(name); break;
        case 'delete': deleteObject(name); break;
    }
    closeContextMenu();
});

async function cloneObject(name) {
    const fd = new FormData();
    fd.append('action', 'clone'); fd.append('item_to_clone', name); fd.append('ajax', '1');
    await performAction(fd);
}
async function zipObject(name) {
    const fd = new FormData();
    fd.append('action', 'zip'); fd.append('item_to_zip', name); fd.append('ajax', '1');
    await performAction(fd);
}
async function extractZip(name) {
    const fd = new FormData();
    fd.append('action', 'extract'); fd.append('item_to_extract', name); fd.append('ajax', '1');
    await performAction(fd);
}
async function deleteObject(name) {
    const ok = await nx.confirm(`Delete <span class="code">${escapeHtml(name)}</span> and all its contents?`,
        { title: 'Delete?', okLabel: 'Delete', danger: true });
    if (!ok) return;
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('item_to_delete', name); fd.append('ajax', '1');
    await performAction(fd);
}
async function renameObject(oldName) {
    const newName = await nx.prompt(`Rename <span class="code">${escapeHtml(oldName)}</span>`, oldName, { title: 'Rename', okLabel: 'Rename' });
    if (!newName || newName === oldName) return;
    const fd = new FormData();
    fd.append('action', 'rename');
    fd.append('item_to_rename', oldName);
    fd.append('new_item_name', newName);
    fd.append('ajax', '1');
    await performAction(fd);
}

async function performAction(formData) {
    startLoading();
    try {
        const res = await fetch(`?dir=${encodeURIComponent(currentDir)}`, { method: 'POST', body: formData });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) navigatePath(encodeURIComponent(currentDir));
    } catch (e) { toast('Action failed', false); }
    stopLoading();
}

function bindAjaxForms() {
    document.querySelectorAll('.ajax-form').forEach(form => {
        form.onsubmit = async (e) => {
            e.preventDefault();
            startLoading();
            const fd = new FormData(form);
            fd.append('action', form.dataset.action); fd.append('ajax', '1');
            try {
                const res  = await fetch(`?dir=${encodeURIComponent(currentDir)}`, { method: 'POST', body: fd });
                const data = await res.json();
                toast(data.message, data.success);
                if (data.success) {
                    form.reset();
                    closeNewPopover();
                    navigatePath(encodeURIComponent(currentDir));
                }
            } catch (err) { toast('Request failed', false); }
            stopLoading();
        };
    });
}

function bindSortHeaders() {
    document.querySelectorAll('th.sortable').forEach(th => {
        th.onclick = () => {
            const k = th.dataset.sort;
            if (currentSort.key === k) currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
            else { currentSort.key = k; currentSort.dir = 'asc'; }
            applyClientSort();
        };
    });
}
function applyClientSort() {
    const tbody = document.getElementById('file-list-body');
    if (!tbody) return;
    const rows = [...tbody.querySelectorAll('tr.file-row')];
    const k = currentSort.key, dir = currentSort.dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
        const da = a.dataset.isDir === '1', db = b.dataset.isDir === '1';
        if (da && !db) return -1; if (!da && db) return 1;
        let va, vb;
        if (k === 'name') { va = a.dataset.name.toLowerCase(); vb = b.dataset.name.toLowerCase(); }
        else if (k === 'size') { va = +a.dataset.size; vb = +b.dataset.size; }
        else if (k === 'modified') { va = +a.dataset.mtime; vb = +b.dataset.mtime; }
        if (va < vb) return -1 * dir; if (va > vb) return 1 * dir; return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
    document.querySelectorAll('th.sortable').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (!icon) return;
        if (th.dataset.sort === k) {
            th.classList.add('sort-active');
            icon.className = `fas fa-arrow-${dir === 1 ? 'down' : 'up'} sort-icon`;
        } else {
            th.classList.remove('sort-active');
            icon.className = 'fas fa-sort sort-icon';
        }
    });
}

function bindSearchEvents() {
    const el = document.getElementById('fileSearch');
    if (!el || el._bound) return; el._bound = true;
    el.addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase().trim();
        let shown = 0;
        document.querySelectorAll('.file-row').forEach(row => {
            const match = row.dataset.name.toLowerCase().includes(term);
            row.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        const fc = document.getElementById('filter-count');
        if (fc) fc.textContent = term ? `${shown} match${shown === 1 ? '' : 'es'}` : '';
        focusedRowIndex = -1;
    });
}

function toggleTheme() {
    document.body.classList.toggle('light-mode');
    const light = document.body.classList.contains('light-mode');
    localStorage.setItem('nexus-theme', light ? 'light' : 'dark');
    if (cmEditor) cmEditor.setOption('theme', light ? 'idea' : 'ayu-mirage');
}

const PALETTE_ACTIONS = [
    { id: 'new-file',   title: 'New File',    desc: 'Create a new empty file',  icon: 'fa-file-circle-plus', run: () => { openNewPopover(); setCreateMode('file'); } },
    { id: 'new-folder', title: 'New Folder',  desc: 'Create a new folder',      icon: 'fa-folder-plus', run: () => { openNewPopover(); setCreateMode('folder'); } },
    { id: 'upload',     title: 'Upload Files',desc: 'Open the upload panel',    icon: 'fa-cloud-arrow-up', run: () => { openUploadPanel(); setUploadMode('files'); } },
    { id: 'upload-dir', title: 'Upload Folder',desc:'Open the upload panel',    icon: 'fa-folder-open', run: () => { openUploadPanel(); setUploadMode('folder'); } },
    { id: 'refresh',    title: 'Refresh',     desc: 'Reload current directory', icon: 'fa-rotate', run: () => navigatePath(encodeURIComponent(currentDir)) },
    { id: 'up',         title: 'Go Up',       desc: 'Navigate to parent folder',icon: 'fa-arrow-up', run: goUp },
    { id: 'root',       title: 'Go to Root',  desc: 'Navigate to base directory',icon:'fa-house', run: () => navigatePath('') },
    { id: 'theme',      title: 'Toggle Theme',desc: 'Switch light/dark',        icon: 'fa-circle-half-stroke', run: toggleTheme },
    { id: 'shortcuts',  title: 'Keyboard Shortcuts',desc:'View all shortcuts',  icon: 'fa-keyboard', run: openShortcuts },
    { id: 'logout',     title: 'Logout',      desc: 'End session',              icon: 'fa-arrow-right-from-bracket', run: () => location.href = 'logout.php' },
];

let paletteActive = 0;
let paletteCurrent = { actions: [], files: [] };

function openPalette() {
    const bd = document.getElementById('palette-bd');
    bd.classList.add('show');
    const input = document.getElementById('palette-input');
    input.value = '';
    renderPalette('');
    setTimeout(() => input.focus(), 50);
}
function closePalette() { document.getElementById('palette-bd').classList.remove('show'); }

function renderPalette(q) {
    const list = document.getElementById('palette-list');
    const term = q.toLowerCase().trim();
    const actions = PALETTE_ACTIONS.filter(a => !term || a.title.toLowerCase().includes(term) || a.desc.toLowerCase().includes(term));
    const fileRows = [...document.querySelectorAll('tr.file-row')];
    const files = fileRows
        .filter(r => !term || r.dataset.name.toLowerCase().includes(term))
        .slice(0, 12)
        .map(r => ({ row: r, name: r.dataset.name, isDir: r.dataset.isDir === '1', ext: r.dataset.ext }));

    paletteCurrent = { actions, files };

    let html = '';
    if (actions.length) {
        html += `<div class="palette-group">Commands</div>`;
        html += actions.map((a, i) => `
            <div class="palette-item" data-kind="action" data-idx="${i}">
                <div class="p-icon"><i class="fas ${a.icon}"></i></div>
                <div class="p-text">
                    <div class="p-title">${escapeHtml(a.title)}</div>
                    <div class="p-desc">${escapeHtml(a.desc)}</div>
                </div>
            </div>`).join('');
    }
    if (files.length) {
        html += `<div class="palette-group">Files</div>`;
        html += files.map((f, i) => `
            <div class="palette-item" data-kind="file" data-idx="${i}">
                <div class="p-icon"><i class="fas ${f.isDir ? 'fa-folder' : 'fa-file'}"></i></div>
                <div class="p-text">
                    <div class="p-title">${escapeHtml(f.name)}</div>
                    <div class="p-desc">${f.isDir ? 'directory' : (f.ext || 'file')}</div>
                </div>
            </div>`).join('');
    }
    if (!actions.length && !files.length) html = `<div class="palette-empty">No results for "${escapeHtml(q)}"</div>`;
    list.innerHTML = html;
    paletteActive = 0;
    updatePaletteActive();
}
function updatePaletteActive() {
    const items = document.querySelectorAll('#palette-list .palette-item');
    items.forEach((el, i) => el.classList.toggle('active', i === paletteActive));
    const active = items[paletteActive];
    if (active) active.scrollIntoView({ block: 'nearest' });
}
function executePaletteItem(el) {
    closePalette();
    const kind = el.dataset.kind, idx = +el.dataset.idx;
    if (kind === 'action') paletteCurrent.actions[idx].run();
    else openRow(paletteCurrent.files[idx].row);
}

function bindPaletteEvents() {
    const bd = document.getElementById('palette-bd');
    const list = document.getElementById('palette-list');
    bd.addEventListener('click', (e) => { if (e.target === bd) closePalette(); });
    list.addEventListener('click', (e) => {
        const el = e.target.closest('.palette-item');
        if (el) executePaletteItem(el);
    });
    list.addEventListener('mousemove', (e) => {
        const el = e.target.closest('.palette-item');
        if (!el) return;
        const items = [...list.querySelectorAll('.palette-item')];
        paletteActive = items.indexOf(el);
        updatePaletteActive();
    });
    const inp = document.getElementById('palette-input');
    inp.addEventListener('input', e => renderPalette(e.target.value));
    inp.addEventListener('keydown', e => {
        const items = document.querySelectorAll('#palette-list .palette-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); paletteActive = (paletteActive + 1) % Math.max(items.length, 1); updatePaletteActive(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); paletteActive = (paletteActive - 1 + items.length) % Math.max(items.length, 1); updatePaletteActive(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            const active = items[paletteActive]; if (active) executePaletteItem(active);
        }
    });
}

function goUp() {
    const parts = currentDir.split(/[\\/]/).filter(Boolean);
    if (!parts.length) return;
    parts.pop();
    navigatePath(encodeURIComponent(parts.join('/')));
}

const SHORTCUTS = [
    { section: 'Navigation' },
    { label: 'Command palette',  keys: ['⌘', 'K'] },
    { label: 'Focus search',     keys: ['/'] },
    { label: 'Go up',            keys: ['u'] },
    { label: 'Go to root',       keys: ['h'] },
    { label: 'Refresh',          keys: ['r'] },
    { label: 'Move focus',       keys: ['j','k'] },
    { label: 'Move focus (alt)', keys: ['↑','↓'] },
    { label: 'Open focused',     keys: ['↵'] },
    { label: 'Toggle selection', keys: ['␣'] },

    { section: 'File Actions' },
    { label: 'New file',         keys: ['n'] },
    { label: 'New folder',       keys: ['m'] },
    { label: 'Open upload panel',keys: ['e'] },
    { label: 'Rename',           keys: ['F2'] },
    { label: 'Duplicate',        keys: ['c'] },
    { label: 'Compress (zip)',   keys: ['z'] },
    { label: 'Delete',           keys: ['Del'] },
    { label: 'Select all',       keys: ['a'] },

    { section: 'Editor' },
    { label: 'Save',             keys: ['⌘','S'] },
    { label: 'Find',             keys: ['⌘','F'] },
    { label: 'Close editor',     keys: ['Esc'] },

    { section: 'UI' },
    { label: 'Toggle theme',     keys: ['t'] },
    { label: 'Show shortcuts',   keys: ['?'] },
    { label: 'Close / clear',    keys: ['Esc'] },
];

function buildShortcutsList() {
    const grid = document.getElementById('sc-grid');
    grid.innerHTML = SHORTCUTS.map(s => {
        if (s.section) return `<div class="sc-section-title">${escapeHtml(s.section)}</div>`;
        return `<div class="sc-row">
            <span class="label">${escapeHtml(s.label)}</span>
            <span class="keys">${s.keys.map(k => `<span class="kbd">${escapeHtml(k)}</span>`).join('')}</span>
        </div>`;
    }).join('');
}
function openShortcuts() { document.getElementById('sc-bd').classList.add('show'); }
function closeShortcuts() { document.getElementById('sc-bd').classList.remove('show'); }
document.getElementById('sc-bd').addEventListener('click', (e) => { if (e.target.id === 'sc-bd') closeShortcuts(); });

document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    const isTyping = ['input','textarea'].includes(tag) || e.target.isContentEditable;
    const mod = e.ctrlKey || e.metaKey;
    const palOpen = document.getElementById('palette-bd').classList.contains('show');
    const scOpen  = document.getElementById('sc-bd').classList.contains('show');
    const popOpen = document.getElementById('new-popover').classList.contains('show');
    const modalOpen = document.querySelector('.modal-backdrop.show') !== null;
    const editorOpen = document.getElementById('editor-container').classList.contains('active');
    const previewOpen = document.getElementById('preview-container').classList.contains('active');
    const uploadOpen = document.getElementById('upload-panel').classList.contains('show');

    if (mod && e.key.toLowerCase() === 'k') {
        e.preventDefault(); openPalette(); return;
    }

    if (e.key === 'Escape') {
        if (palOpen) { closePalette(); return; }
        if (scOpen)  { closeShortcuts(); return; }
        if (modalOpen) return;
        if (popOpen) { closeNewPopover(); return; }
        if (editorOpen)  { closeEditor(); return; }
        if (previewOpen) { closePreview(); return; }
        if (uploadOpen) { closeUploadPanel(); return; }
        if (document.getElementById('ctx-menu').classList.contains('show')) { closeContextMenu(); return; }
        if (selectedItems.size) { clearSelection(); return; }
        if (isTyping) e.target.blur();
        return;
    }

    if (palOpen || scOpen || modalOpen) return;
    if (isTyping) return;
    if (editorOpen) return;
    if (mod) return;

    const k = e.key;

    if (k === '/') {
        e.preventDefault();
        const s = document.getElementById('fileSearch');
        if (s) { s.focus(); s.select(); }
        return;
    }
    if (k === '?') { e.preventDefault(); openShortcuts(); return; }

    if (k === 'u') { e.preventDefault(); goUp(); return; }
    if (k === 'h') { e.preventDefault(); navigatePath(''); return; }
    if (k === 'r') { e.preventDefault(); navigatePath(encodeURIComponent(currentDir)); return; }
    if (k === 'j' || k === 'ArrowDown') { e.preventDefault(); updateFocus(focusedRowIndex < 0 ? 0 : focusedRowIndex + 1); return; }
    if (k === 'k' || k === 'ArrowUp')   { e.preventDefault(); updateFocus(focusedRowIndex < 0 ? 0 : focusedRowIndex - 1); return; }
    if (k === 'Enter') {
        const rows = visibleRows();
        if (rows[focusedRowIndex]) { e.preventDefault(); openRow(rows[focusedRowIndex]); }
        return;
    }
    if (k === ' ') {
        const rows = visibleRows();
        const r = rows[focusedRowIndex];
        if (r) { e.preventDefault(); const cb = r.querySelector('.row-check'); cb.checked = !cb.checked; toggleSelect(cb); }
        return;
    }

    if (k === 'n') { e.preventDefault(); openNewPopover(); setCreateMode('file');   return; }
    if (k === 'm') { e.preventDefault(); openNewPopover(); setCreateMode('folder'); return; }
    if (k === 'e') { e.preventDefault(); openUploadPanel(); return; }

    if (k === 'a') { e.preventDefault(); toggleSelectAll(true); return; }
    if (k === 'c') {
        e.preventDefault();
        const target = selectedItems.size === 1 ? [...selectedItems][0] : focusedRowName();
        if (target) cloneObject(target);
        return;
    }
    if (k === 'z') {
        e.preventDefault();
        if (selectedItems.size > 0) bulkZip();
        else {
            const n = focusedRowName();
            if (n) zipObject(n);
        }
        return;
    }
    if (k === 'F2') {
        e.preventDefault();
        const target = selectedItems.size === 1 ? [...selectedItems][0] : focusedRowName();
        if (target) renameObject(target); return;
    }
    if (k === 'Delete' || k === 'Backspace') {
        e.preventDefault();
        if (selectedItems.size > 0) { bulkDelete(); return; }
        const n = focusedRowName(); if (n) deleteObject(n);
        return;
    }

    if (k === 't') { e.preventDefault(); toggleTheme(); return; }
});

function focusedRowName() {
    const rows = visibleRows();
    if (focusedRowIndex < 0 || !rows[focusedRowIndex]) return null;
    return rows[focusedRowIndex].dataset.name;
}

window.addEventListener('beforeunload', (e) => {
    if (editorDirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>
</body>
</html>
