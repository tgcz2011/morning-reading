<?php
require_once 'config.php';

session_start();

// 检查登录状态
function checkAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: index.php?action=login');
        exit;
    }
}

// 登录验证
function login($password) {
    if ($password === LOGIN_PASSWORD) {
        $_SESSION['logged_in'] = true;
        return true;
    }
    return false;
}

// 检查是否在可记录时间段内 - 修复问题3：恢复时间限制
function canRecord() {
    // 调试阶段：暂时取消时间限制（注释掉下面的return true来恢复限制）
    // return true;
    
    $current_time = date('H:i');
    $current_hour = (int)date('H');
    $current_minute = (int)date('i');
    
    // 早读时间: 6:25-7:00
    $morning_start = 6 * 60 + 20; // 6:25 in minutes
    $morning_end = 7 * 60 + 10;        // 6:59 in minutes
    
    // 晚读时间: 17:35-18:30
    $evening_start = 17 * 60 + 45; // 17:35 in minutes
    $evening_end = 18 * 60 + 20;   // 18:30 in minutes
    
    $current_minutes = $current_hour * 60 + $current_minute;
    
    return ($current_minutes >= $morning_start && $current_minutes <= $morning_end) ||
           ($current_minutes >= $evening_start && $current_minutes <= $evening_end);
}

// 获取当前记录类型 (早读/晚读)
function getCurrentRecordType() {
    $current_hour = (int)date('H');
    return ($current_hour < 12) ? 'morning' : 'evening';
}

// 获取周数 (用于统计)
function getWeekNumber($date = null) {
    $date = $date ?: date('Y-m-d');
    return (int)date('W', strtotime($date));
}

// 获取月数
function getMonthNumber($date = null) {
    $date = $date ?: date('Y-m-d');
    return (int)date('n', strtotime($date));
}

// 获取学期周数 (假设学期从2月第1周开始)
function getSemesterWeek($date = null) {
    $date = $date ?: date('Y-m-d');
    $semester_start = strtotime('first monday of february ' . date('Y'));
    $current_week = strtotime($date);
    return (int)floor(($current_week - $semester_start) / (7 * 24 * 60 * 60)) + 1;
}

// 检查当前时间段是否有扣分
function hasPenaltyInCurrentSession($student_id) {
    $pdo = getDB();
    $current_week = getWeekNumber();
    $current_type = getCurrentRecordType();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM penalty_records 
                          WHERE student_id = ? AND week_number = ? AND record_type = ?");
    $stmt->execute([$student_id, $current_week, $current_type]);
    $result = $stmt->fetch();
    
    return $result['count'] > 0;
}

// 添加记录
function addRecord($student_id, $type = null) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }
    
    $pdo = getDB();
    $type = $type ?: getCurrentRecordType();
    $today = date('Y-m-d');
    
    // 检查当前时间段是否有扣分
    $has_penalty = hasPenaltyInCurrentSession($student_id);
    
    if ($has_penalty) {
        // 先清除当前时间段的扣分记录
        $current_week = getWeekNumber();
        $stmt = $pdo->prepare("DELETE FROM penalty_records 
                              WHERE student_id = ? AND week_number = ? AND record_type = ? 
                              LIMIT 1");
        $stmt->execute([$student_id, $current_week, $type]);
        
        // 更新周统计中的扣分计数
        $stmt = $pdo->prepare("SELECT penalty_count FROM weekly_stats 
                              WHERE student_id = ? AND week_number = ?");
        $stmt->execute([$student_id, $current_week]);
        $weekly_data = $stmt->fetch();
        
        if ($weekly_data && $weekly_data['penalty_count'] > 0) {
            $stmt = $pdo->prepare("UPDATE weekly_stats SET penalty_count = penalty_count - 1 
                                  WHERE student_id = ? AND week_number = ?");
            $stmt->execute([$student_id, $current_week]);
        }
        
        return ['success' => true, 'message' => '已清除一次扣分记录'];
    }
    
    // 检查今天是否已经记录过
    $stmt = $pdo->prepare("SELECT id FROM reading_records 
                          WHERE student_id = ? AND record_date = ? AND record_type = ? AND is_canceled = FALSE");
    $stmt->execute([$student_id, $today, $type]);
    
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => '今天已经记录过' . ($type === 'morning' ? '早读' : '晚读')];
    }
    
    // 插入新记录
    $stmt = $pdo->prepare("INSERT INTO reading_records 
                          (student_id, record_type, record_date, week_number, month_number, semester_week) 
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $student_id, 
        $type, 
        $today, 
        getWeekNumber(), 
        getMonthNumber(), 
        getSemesterWeek()
    ]);
    
    // 更新周统计
    updateWeeklyStats($student_id, getWeekNumber(), $type, 1);
    
    return ['success' => true, 'message' => '记录成功'];
}

// 取消记录
function cancelRecord($student_id, $type = null) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }
    
    $pdo = getDB();
    $type = $type ?: getCurrentRecordType();
    $today = date('Y-m-d');
    
    // 查找最新的未取消记录
    $stmt = $pdo->prepare("SELECT id FROM reading_records 
                          WHERE student_id = ? AND record_date = ? AND record_type = ? AND is_canceled = FALSE 
                          ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$student_id, $today, $type]);
    
    if ($record = $stmt->fetch()) {
        // 标记为取消
        $stmt = $pdo->prepare("UPDATE reading_records SET is_canceled = TRUE WHERE id = ?");
        $stmt->execute([$record['id']]);
        
        // 更新周统计
        updateWeeklyStats($student_id, getWeekNumber(), $type, -1);
        
        return ['success' => true, 'message' => '取消成功'];
    }
    
    return ['success' => false, 'message' => '未找到可取消的记录'];
}

// 扣分处理
function penalizeStudent($student_id) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }
    
    $pdo = getDB();
    $current_week = getWeekNumber();
    $current_type = getCurrentRecordType();
    
    // 记录扣分时间
    $stmt = $pdo->prepare("INSERT INTO penalty_records (student_id, week_number, record_type) VALUES (?, ?, ?)");
    $stmt->execute([$student_id, $current_week, $current_type]);
    
    // 获取本周的记录统计
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reading_records 
                          WHERE student_id = ? AND week_number = ? AND is_canceled = FALSE");
    $stmt->execute([$student_id, $current_week]);
    $record_count = $stmt->fetch()['count'];
    
    // 获取本周已扣分次数
    $stmt = $pdo->prepare("SELECT penalty_count FROM weekly_stats 
                          WHERE student_id = ? AND week_number = ?");
    $stmt->execute([$student_id, $current_week]);
    $penalty_data = $stmt->fetch();
    $penalty_count = $penalty_data ? $penalty_data['penalty_count'] : 0;
    
    // 增加扣分计数
    $new_penalty_count = $penalty_count + 1;
    if ($penalty_data) {
        $stmt = $pdo->prepare("UPDATE weekly_stats SET penalty_count = ?, last_penalty_time = NOW() 
                              WHERE student_id = ? AND week_number = ?");
        $stmt->execute([$new_penalty_count, $student_id, $current_week]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO weekly_stats (student_id, week_number, penalty_count, last_penalty_time) 
                              VALUES (?, ?, ?, NOW())");
        $stmt->execute([$student_id, $current_week, $new_penalty_count]);
    }
    
    // 计算净记录数
    $net_records = $record_count - $new_penalty_count;
    
    return ['success' => true, 'penalty' => $net_records];
}

// 更新周统计
function updateWeeklyStats($student_id, $week_number, $type, $change) {
    $pdo = getDB();
    
    // 检查是否已存在统计记录
    $stmt = $pdo->prepare("SELECT * FROM weekly_stats WHERE student_id = ? AND week_number = ?");
    $stmt->execute([$student_id, $week_number]);
    
    if ($stmt->fetch()) {
        // 更新现有记录
        $field = $type . '_count';
        $stmt = $pdo->prepare("UPDATE weekly_stats SET {$field} = {$field} + ? 
                              WHERE student_id = ? AND week_number = ?");
        $stmt->execute([$change, $student_id, $week_number]);
    } else {
        // 插入新记录
        $morning_count = ($type === 'morning') ? max(0, $change) : 0;
        $evening_count = ($type === 'evening') ? max(0, $change) : 0;
        
        $stmt = $pdo->prepare("INSERT INTO weekly_stats (student_id, week_number, morning_count, evening_count) 
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$student_id, $week_number, $morning_count, $evening_count]);
    }
}

// 获取学生本周状态
function getStudentStatus($student_id) {
    $pdo = getDB();
    $current_week = getWeekNumber();
    $today = date('Y-m-d');
    
    // 获取今日记录
    $stmt = $pdo->prepare("SELECT record_type, is_canceled FROM reading_records 
                          WHERE student_id = ? AND record_date = ? AND is_canceled = FALSE");
    $stmt->execute([$student_id, $today]);
    $today_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $today_morning = false;
    $today_evening = false;
    
    foreach ($today_records as $record) {
        if ($record['record_type'] === 'morning') $today_morning = true;
        if ($record['record_type'] === 'evening') $today_evening = true;
    }
    
    // 获取本周统计
    $stmt = $pdo->prepare("SELECT morning_count, evening_count, penalty_count FROM weekly_stats 
                          WHERE student_id = ? AND week_number = ?");
    $stmt->execute([$student_id, $current_week]);
    $weekly_data = $stmt->fetch();
    
    $morning_count = $weekly_data ? $weekly_data['morning_count'] : 0;
    $evening_count = $weekly_data ? $weekly_data['evening_count'] : 0;
    $penalty_count = $weekly_data ? $weekly_data['penalty_count'] : 0;
    
    $total_records = $morning_count + $evening_count;
    $net_score = $total_records - $penalty_count;
    
    // 检查当前时间段是否有扣分
    $has_penalty_in_session = hasPenaltyInCurrentSession($student_id);
    
    return [
        'today_morning' => $today_morning,
        'today_evening' => $today_evening,
        'weekly_score' => $net_score,
        'penalty_count' => $penalty_count,
        'has_penalty_in_session' => $has_penalty_in_session
    ];
}

// 获取统计数据（用于表格）
function getStatistics($period = 'week') {
    $pdo = getDB();
    $current_week = getWeekNumber();
    $current_month = getMonthNumber();
    $current_semester_week = getSemesterWeek();
    
    switch ($period) {
        case 'day':
            $date = date('Y-m-d');
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE record_date = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$date]);
            break;
            
        case 'week':
            // 周统计包括负分
            $sql = "SELECT ws.student_id, 
                           (COALESCE(ws.morning_count, 0) + COALESCE(ws.evening_count, 0) - COALESCE(ws.penalty_count, 0)) as score 
                    FROM weekly_stats ws 
                    WHERE ws.week_number = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$current_week]);
            break;
            
        case 'month':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE month_number = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$current_month]);
            break;
            
        case 'semester':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE semester_week <= ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$current_semester_week]);
            break;
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取正分统计数据（用于饼状图）- 修复问题1：确保负分不计入饼状图
function getPositiveStatistics($period = 'week') {
    $pdo = getDB();
    $current_week = getWeekNumber();
    $current_month = getMonthNumber();
    $current_semester_week = getSemesterWeek();
    
    switch ($period) {
        case 'day':
            $date = date('Y-m-d');
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE record_date = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$date]);
            break;
            
        case 'week':
            // 只统计正分，不包含负分 - 修复问题1：确保只统计有正分的学生
            $sql = "SELECT ws.student_id, 
                           (COALESCE(ws.morning_count, 0) + COALESCE(ws.evening_count, 0)) as score 
                    FROM weekly_stats ws 
                    WHERE ws.week_number = ? 
                    AND (COALESCE(ws.morning_count, 0) + COALESCE(ws.evening_count, 0)) > 0";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$current_week]);
            break;
            
        case 'month':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE month_number = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$current_month]);
            break;
            
        case 'semester':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE semester_week <= ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$current_semester_week]);
            break;
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>