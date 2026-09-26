<?php
/**
 * TBN Portfolio - GitHub Auto Deployment Webhook
 * Tự động đồng bộ code mới nhất từ GitHub về public_html mỗi khi có commit mới
 */

// Secret Token
$secret = 'tbn_auto_deploy_2026';

// Cho phép gọi trực tiếp qua query param hoặc từ GitHub Webhook
$isAuthorized = false;

// 1. Cho phép xác thực qua Secret Query Param: ?secret=tbn_auto_deploy_2026
if (isset($_GET['secret']) && hash_equals($secret, (string)$_GET['secret'])) {
    $isAuthorized = true;
}

// 2. Cho phép xác thực qua GitHub Webhook HMAC-SHA256
$headers = function_exists('getallheaders') ? getallheaders() : [];
$hubSig = $headers['X-Hub-Signature-256'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!$isAuthorized && !empty($hubSig)) {
    $rawPayload = file_get_contents('php://input');
    $expectedSig = 'sha256=' . hash_hmac('sha256', $rawPayload, $secret);
    if (hash_equals($expectedSig, $hubSig)) {
        $isAuthorized = true;
    }
}

// Chặn tuyệt đối nếu không có xác thực hợp lệ
if (!$isAuthorized && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die(json_encode([
        'status' => 'error',
        'message' => 'Truy cập bị từ chối: Yêu cầu Secret Token hoặc GitHub Webhook Signature hợp lệ!'
    ]));
}

header('Content-Type: application/json; charset=utf-8');

$logs = [];
$success = false;

// 1. THỬ PHƯƠNG PHÁP 1: Dùng Git shell command
if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    $portfolioDir = '/home/cuacongn/portfolio';
    $publicHtmlDir = '/home/cuacongn/public_html';

    if (is_dir($portfolioDir)) {
        $gitRes = @shell_exec("cd {$portfolioDir} && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1");
        $logs[] = "Git: " . trim($gitRes);
        @shell_exec("rm -f {$publicHtmlDir}/data/blog.db-wal {$publicHtmlDir}/data/blog.db-shm {$portfolioDir}/data/blog.db-wal {$portfolioDir}/data/blog.db-shm");
        $cpRes = @shell_exec("cp -rf {$portfolioDir}/. {$publicHtmlDir}/ 2>&1 && cp -f {$portfolioDir}/.htaccess {$publicHtmlDir}/ 2>&1");
        $logs[] = "Sync to public_html: " . (empty($cpRes) ? "OK" : $cpRes);
        @chmod("{$publicHtmlDir}/data", 0777);
        @chmod("{$publicHtmlDir}/data/blog.db", 0666);
        @touch("{$portfolioDir}/tmp/restart.txt");
        $success = true;
    }
}

// 2. THỬ PHƯƠNG PHÁP 2: Tải trực tiếp GitHub repo Zip nếu Git không khả dụng
if (!$success) {
    $zipUrl = 'https://github.com/ngoctruongne/truong-bao-ngoc-portfolio/archive/refs/heads/main.zip';
    $tempZip = __DIR__ . '/github-update-temp.zip';

    $zipContent = @file_get_contents($zipUrl);
    if ($zipContent) {
        file_put_contents($tempZip, $zipContent);

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tempZip) === TRUE) {
                $extractTo = __DIR__ . '/_temp_extract';
                if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);
                $zip->extractTo($extractTo);
                $zip->close();

                $subDir = $extractTo . '/truong-bao-ngoc-portfolio-main';
                if (is_dir($subDir)) {
                    $rcopy = function($src, $dst) use (&$rcopy) {
                        if (is_dir($src)) {
                            if (!is_dir($dst)) @mkdir($dst, 0755, true);
                            $files = scandir($src);
                            foreach ($files as $file) {
                                if ($file != "." && $file != "..") {
                                    $rcopy("$src/$file", "$dst/$file");
                                }
                            }
                        } else if (file_exists($src)) {
                            copy($src, $dst);
                        }
                    };
                    $rcopy($subDir, __DIR__);
                    if (file_exists($subDir . '/.htaccess')) {
                        @copy($subDir . '/.htaccess', __DIR__ . '/.htaccess');
                    }
                    $logs[] = "ZipArchive recursive extract: OK";
                    $success = true;
                }

                @unlink($tempZip);
                $rrmdir = function($dir) use (&$rrmdir) {
                    if (is_dir($dir)) {
                        $objects = scandir($dir);
                        foreach ($objects as $object) {
                            if ($object != "." && $object != "..") {
                                if (is_dir($dir . "/" . $object)) $rrmdir($dir . "/" . $object);
                                else @unlink($dir . "/" . $object);
                            }
                        }
                        @rmdir($dir);
                    }
                };
                $rrmdir($extractTo);
            }
        }
    }
}

echo json_encode([
    'status' => $success ? 'success' : 'warning',
    'message' => $success ? 'Đã tự động kéo code mới nhất từ GitHub và đồng bộ sang public_html!' : 'Hoàn tất kiểm tra webhook',
    'logs' => $logs,
    'time' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
