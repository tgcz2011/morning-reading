<?php
// 通用心跳接口：前端定时调用，检查登录是否过期
// ?type=record  记录页/统计页（3小时过期，回 index.php）
// ?type=admin   教师管理页（7天过期，回 admin.php）
require_once 'functions.php';
header('Content-Type: application/json');

$type = $_GET['type'] ?? 'record';

if ($type === 'admin') {
    // 教师管理：7天过期
    if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true) {
        echo json_encode(['expired' => true, 'redirect' => 'admin.php']);
        exit;
    }
    if (!isset($_SESSION['teacher_login_time']) || time() - $_SESSION['teacher_login_time'] > 7 * 86400) {
        session_unset();
        session_destroy();
        echo json_encode(['expired' => true, 'redirect' => 'admin.php']);
        exit;
    }
} else {
    // 记录页/统计页：3小时过期
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['expired' => true, 'redirect' => 'index.php']);
        exit;
    }
    if (!isset($_SESSION['login_time']) || time() - $_SESSION['login_time'] > 3 * 3600) {
        session_unset();
        session_destroy();
        echo json_encode(['expired' => true, 'redirect' => 'index.php']);
        exit;
    }
}

echo json_encode(['alive' => true]);
