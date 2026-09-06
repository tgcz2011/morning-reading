<?php
require_once 'config.php';

session_start();

// ============================================================
// 鉴权
// ============================================================

// 检查班级登录状态
function checkAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['class_id'])) {
        header('Location: index.php');
        exit;
    }
    // 记录页登录有效期 3 小时
    if (!isset($_SESSION['login_time']) || time() - $_SESSION['login_time'] > 3 * 3600) {
        session_unset();
        session_destroy();
        if (isset($_POST['ajax_action'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '登录已过期，请重新登录']);
            exit;
        }
        header('Location: index.php');
        exit;
    }
}

// 检查教师管理登录状态（教师只能管理自己所在班级；有效期 7 天）
function checkAdminAuth() {
    if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true || empty($_SESSION['teacher_class_id'])) {
        header('Location: admin.php');
        exit;
    }
    if (!isset($_SESSION['teacher_login_time']) || time() - $_SESSION['teacher_login_time'] > 7 * 86400) {
        session_unset();
        session_destroy();
        header('Location: admin.php');
        exit;
    }
}

// 检查总管理（superadmin）登录状态
function checkSuperAuth() {
    if (!isset($_SESSION['super_logged_in']) || $_SESSION['super_logged_in'] !== true) {
        header('Location: edit.php');
        exit;
    }
}

// 班级登录（班级号 + 班级密码）
function login($class_number, $password) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE class_number = ?");
    $stmt->execute([(int)$class_number]);
    $class = $stmt->fetch();
    if ($class && hash_equals($class['password'], $password)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['class_id'] = (int)$class['id'];
        $_SESSION['class_number'] = (int)$class['class_number'];
        $_SESSION['login_time'] = time(); // 记录页登录有效期 3 小时
        return true;
    }
    return false;
}

// 教师管理登录（班级号 + 教师管理密码）
function teacherLogin($class_number, $password) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE class_number = ?");
    $stmt->execute([(int)$class_number]);
    $class = $stmt->fetch();
    if ($class && !empty($class['teacher_password']) && hash_equals($class['teacher_password'], $password)) {
        $_SESSION['teacher_logged_in'] = true;
        $_SESSION['teacher_class_id'] = (int)$class['id'];
        $_SESSION['teacher_class_number'] = (int)$class['class_number'];
        $_SESSION['teacher_login_time'] = time(); // 教师页登录有效期 7 天
        return true;
    }
    return false;
}

// 总管理登录（edit.php，使用 config 中的 SUPERADMIN_PASSWORD）
function superLogin($password) {
    if (hash_equals(SUPERADMIN_PASSWORD, $password)) {
        $_SESSION['super_logged_in'] = true;
        return true;
    }
    return false;
}

// 当前班级 ID / 班号 / 班名
function getClassId() {
    return (int)$_SESSION['class_id'];
}

function getClassNumber() {
    return (int)$_SESSION['class_number'];
}

// 教师当前管理的班级 ID / 班号
function getTeacherClassId() {
    return (int)$_SESSION['teacher_class_id'];
}

function getTeacherClassNumber() {
    return (int)$_SESSION['teacher_class_number'];
}

function getClassName($number = null) {
    $number = $number !== null ? (int)$number : getClassNumber();
    return chineseNumber($number) . '班';
}

// ============================================================
// 学生名单
// ============================================================

// 获取班级学生列表（含解码姓名）
function getStudents($class_id = null) {
    $pdo = getDB();
    $class_id = $class_id !== null ? (int)$class_id : getClassId();
    $stmt = $pdo->prepare("SELECT id, class_id, student_no, name_encoded FROM students WHERE class_id = ? ORDER BY student_no ASC");
    $stmt->execute([$class_id]);
    $list = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['name'] = decodeName($row['name_encoded']);
        $list[] = $row;
    }
    return $list;
}

// 学生 id => 姓名 映射
function getStudentMap($class_id = null) {
    $map = [];
    foreach (getStudents($class_id) as $s) {
        $map[$s['id']] = $s['name'];
    }
    return $map;
}

// ============================================================
// 时间与时段
// ============================================================

// 读取时段配置（settings 表，带默认值兜底；可在总管理界面修改）
function getPeriodSettings() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = [
        'morning_start' => '06:20',
        'morning_end'   => '07:10',
        'evening_start' => '17:45',
        'evening_end'   => '18:20',
    ];
    try {
        $rows = getDB()->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($defaults as $k => $v) {
            if (isset($rows[$k]) && preg_match('/^\d{1,2}:\d{2}$/', $rows[$k])) {
                $defaults[$k] = $rows[$k];
            }
        }
    } catch (PDOException $e) {
        // settings 表不存在时用默认值（initDatabase 会创建）
    }
    $cache = $defaults;
    return $cache;
}

// 校验并保存时段配置（HH:MM，开始必须早于结束）
function updatePeriodSettings($morning_start, $morning_end, $evening_start, $evening_end) {
    $fields = [
        'morning_start' => trim($morning_start),
        'morning_end'   => trim($morning_end),
        'evening_start' => trim($evening_start),
        'evening_end'   => trim($evening_end),
    ];
    foreach ($fields as $k => $v) {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $v)) {
            return ['success' => false, 'message' => $k . ' 时间格式错误（应为 HH:MM，如 06:20）'];
        }
    }
    $toMin = function ($t) { list($h, $m) = explode(':', $t); return (int)$h * 60 + (int)$m; };
    if ($toMin($fields['morning_start']) >= $toMin($fields['morning_end'])) {
        return ['success' => false, 'message' => '早读开始时间必须早于结束时间'];
    }
    if ($toMin($fields['evening_start']) >= $toMin($fields['evening_end'])) {
        return ['success' => false, 'message' => '晚读开始时间必须早于结束时间'];
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($fields as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    return ['success' => true, 'message' => '时段设置已保存，首页与记录接口即时生效'];
}

// 时间段展示文案（供首页提示使用）
function getPeriodRangeText() {
    $s = getPeriodSettings();
    return '早读：' . $s['morning_start'] . '-' . $s['morning_end'] . '，晚读：' . $s['evening_start'] . '-' . $s['evening_end'];
}

// 检查是否在可记录时间段内（时间来自 settings 配置）
function canRecord() {
    $current_minutes = (int)date('H') * 60 + (int)date('i');
    $s = getPeriodSettings();
    list($h, $m) = explode(':', $s['morning_start']);
    $ms = (int)$h * 60 + (int)$m;
    list($h, $m) = explode(':', $s['morning_end']);
    $me = (int)$h * 60 + (int)$m;
    list($h, $m) = explode(':', $s['evening_start']);
    $es = (int)$h * 60 + (int)$m;
    list($h, $m) = explode(':', $s['evening_end']);
    $ee = (int)$h * 60 + (int)$m;
    return ($current_minutes >= $ms && $current_minutes <= $me) ||
           ($current_minutes >= $es && $current_minutes <= $ee);
}

// 获取当前记录类型 (早读/晚读)——根据 settings 配置的时段判断，非记录时段返回 null
function getCurrentRecordType() {
    $current_minutes = (int)date('H') * 60 + (int)date('i');
    $s = getPeriodSettings();
    list($h, $m) = explode(':', $s['morning_start']);
    $ms = (int)$h * 60 + (int)$m;
    list($h, $m) = explode(':', $s['morning_end']);
    $me = (int)$h * 60 + (int)$m;
    list($h, $m) = explode(':', $s['evening_start']);
    $es = (int)$h * 60 + (int)$m;
    list($h, $m) = explode(':', $s['evening_end']);
    $ee = (int)$h * 60 + (int)$m;
    if ($current_minutes >= $ms && $current_minutes <= $me) {
        return 'morning';
    }
    if ($current_minutes >= $es && $current_minutes <= $ee) {
        return 'evening';
    }
    return null;
}

// 获取周数
function getWeekNumber($date = null) {
    $date = $date ?: date('Y-m-d');
    return (int)date('W', strtotime($date));
}

// 获取月数
function getMonthNumber($date = null) {
    $date = $date ?: date('Y-m-d');
    return (int)date('n', strtotime($date));
}

// 获取学期周数（按寒暑假结束时间分割）
function getSemesterWeek($date = null) {
    $date = $date ?: date('Y-m-d');
    $start = getSemesterStart($date);
    $days = (int)floor((strtotime($date) - strtotime($start)) / 86400);
    return (int)floor($days / 7) + 1;
}

// 本次朗读时段是否已加过分
function hasAddedThisSession($student_id) {
    $key = getCurrentRecordType() . '-' . date('Y-m-d');
    return isset($_SESSION['session_added'][$student_id]) && $_SESSION['session_added'][$student_id] === $key;
}

// ============================================================
// 记录操作
// ============================================================

// 检查本周是否有扣分（不区分早读/晚读，正分优先抵消任意负分）
function hasPenaltyThisWeek($student_id) {
    $pdo = getDB();
    $current_week = getWeekNumber();
    $class_id = getClassId();

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM penalty_records 
                          WHERE class_id = ? AND student_id = ? AND week_number = ?");
    $stmt->execute([$class_id, $student_id, $current_week]);
    $result = $stmt->fetch();

    return $result['count'] > 0;
}

// 添加记录（加分）
function addRecord($student_id, $type = null) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }

    $pdo = getDB();
    $class_id = getClassId();
    $type = $type ?: getCurrentRecordType();
    $today = date('Y-m-d');
    $session_key = $type . '-' . $today;

    // 一次朗读时间最多加一分：加过分后本次锁定
    if (isset($_SESSION['session_added'][$student_id]) && $_SESSION['session_added'][$student_id] === $session_key) {
        return ['success' => false, 'message' => '本次朗读已加过分，最多加一分', 'already_added' => true];
    }

    // 检查当前时间段是否有扣分（正分优先补负分）
    $has_penalty = hasPenaltyThisWeek($student_id);

    if ($has_penalty) {
        // 先清除当前时间段的扣分记录
        $current_week = getWeekNumber();
        $stmt = $pdo->prepare("DELETE FROM penalty_records 
                              WHERE class_id = ? AND student_id = ? AND week_number = ? AND record_type = ? 
                              LIMIT 1");
        $stmt->execute([$class_id, $student_id, $current_week, $type]);

        // 更新周统计中的扣分计数
        $stmt = $pdo->prepare("SELECT penalty_count FROM weekly_stats 
                              WHERE class_id = ? AND student_id = ? AND week_number = ?");
        $stmt->execute([$class_id, $student_id, $current_week]);
        $weekly_data = $stmt->fetch();

        if ($weekly_data && $weekly_data['penalty_count'] > 0) {
            $stmt = $pdo->prepare("UPDATE weekly_stats SET penalty_count = penalty_count - 1 
                                  WHERE class_id = ? AND student_id = ? AND week_number = ?");
            $stmt->execute([$class_id, $student_id, $current_week]);
        }

        // 锁定本次朗读时段的加分
        $_SESSION['session_added'][$student_id] = $session_key;

        return ['success' => true, 'message' => '已补回一分'];
    }

    // 检查今天是否已经记录过
    $stmt = $pdo->prepare("SELECT id FROM reading_records 
                          WHERE class_id = ? AND student_id = ? AND record_date = ? AND record_type = ? AND is_canceled = FALSE");
    $stmt->execute([$class_id, $student_id, $today, $type]);

    if ($stmt->fetch()) {
        return ['success' => false, 'message' => '今天已经记录过' . ($type === 'morning' ? '早读' : '晚读'), 'already_added' => true];
    }

    // 插入新记录
    $stmt = $pdo->prepare("INSERT INTO reading_records 
                          (class_id, student_id, record_type, record_date, week_number, month_number, semester_week) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $class_id,
        $student_id,
        $type,
        $today,
        getWeekNumber(),
        getMonthNumber(),
        getSemesterWeek()
    ]);

    // 更新周统计
    updateWeeklyStats($student_id, getWeekNumber(), $type, 1);

    // 锁定本次朗读时段的加分
    $_SESSION['session_added'][$student_id] = $session_key;

    return ['success' => true, 'message' => '记录成功'];
}

// 取消记录
function cancelRecord($student_id, $type = null) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }

    $pdo = getDB();
    $class_id = getClassId();
    $type = $type ?: getCurrentRecordType();
    $today = date('Y-m-d');

    // 查找最新的未取消记录
    $stmt = $pdo->prepare("SELECT id FROM reading_records 
                          WHERE class_id = ? AND student_id = ? AND record_date = ? AND record_type = ? AND is_canceled = FALSE 
                          ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$class_id, $student_id, $today, $type]);

    if ($record = $stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE reading_records SET is_canceled = TRUE WHERE id = ?");
        $stmt->execute([$record['id']]);

        updateWeeklyStats($student_id, getWeekNumber(), $type, -1);

        return ['success' => true, 'message' => '取消成功'];
    }

    return ['success' => false, 'message' => '未找到可取消的记录'];
}

// 扣分处理（扣分不限次数；扣分优先抵消已有的正分记录，被抵消的正分不进入统计）
function penalizeStudent($student_id) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }

    $pdo = getDB();
    $class_id = getClassId();
    $current_week = getWeekNumber();
    $current_type = getCurrentRecordType();

    // 扣分优先抵消正分：查本周最新一条未取消的正分记录（不区分早读/晚读）
    $stmt = $pdo->prepare("SELECT id, record_type FROM reading_records 
                          WHERE class_id = ? AND student_id = ? AND week_number = ? AND is_canceled = FALSE 
                          ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$class_id, $student_id, $current_week]);
    $positive_record = $stmt->fetch();

    if ($positive_record) {
        // 抵消一条正分：标记为已取消，周统计对应计数 -1，不记负分
        $stmt = $pdo->prepare("UPDATE reading_records SET is_canceled = TRUE WHERE id = ?");
        $stmt->execute([$positive_record['id']]);
        updateWeeklyStats($student_id, $current_week, $positive_record['record_type'], -1);
        return ['success' => true, 'message' => '已抵消一分', 'offset' => true];
    }

    // 没有正分可抵消：正常扣分（扣分不限次数）
    // 记录扣分时间
    $stmt = $pdo->prepare("INSERT INTO penalty_records (class_id, student_id, week_number, record_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$class_id, $student_id, $current_week, $current_type]);

    // 获取本周的记录统计
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reading_records 
                          WHERE class_id = ? AND student_id = ? AND week_number = ? AND is_canceled = FALSE");
    $stmt->execute([$class_id, $student_id, $current_week]);
    $record_count = $stmt->fetch()['count'];

    // 获取本周已扣分次数
    $stmt = $pdo->prepare("SELECT penalty_count FROM weekly_stats 
                          WHERE class_id = ? AND student_id = ? AND week_number = ?");
    $stmt->execute([$class_id, $student_id, $current_week]);
    $penalty_data = $stmt->fetch();
    $penalty_count = $penalty_data ? $penalty_data['penalty_count'] : 0;

    // 增加扣分计数
    $new_penalty_count = $penalty_count + 1;
    if ($penalty_data) {
        $stmt = $pdo->prepare("UPDATE weekly_stats SET penalty_count = ?, last_penalty_time = NOW() 
                              WHERE class_id = ? AND student_id = ? AND week_number = ?");
        $stmt->execute([$new_penalty_count, $class_id, $student_id, $current_week]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO weekly_stats (class_id, student_id, week_number, penalty_count, last_penalty_time) 
                              VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$class_id, $student_id, $current_week, $new_penalty_count]);
    }

    // 计算净记录数
    $net_records = $record_count - $new_penalty_count;

    return ['success' => true, 'penalty' => $net_records];
}

// 更新周统计
function updateWeeklyStats($student_id, $week_number, $type, $change) {
    $pdo = getDB();
    $class_id = getClassId();

    // 检查是否已存在统计记录
    $stmt = $pdo->prepare("SELECT * FROM weekly_stats WHERE class_id = ? AND student_id = ? AND week_number = ?");
    $stmt->execute([$class_id, $student_id, $week_number]);

    if ($stmt->fetch()) {
        $field = $type . '_count';
        $stmt = $pdo->prepare("UPDATE weekly_stats SET {$field} = {$field} + ? 
                              WHERE class_id = ? AND student_id = ? AND week_number = ?");
        $stmt->execute([$change, $class_id, $student_id, $week_number]);
    } else {
        $morning_count = ($type === 'morning') ? max(0, $change) : 0;
        $evening_count = ($type === 'evening') ? max(0, $change) : 0;

        $stmt = $pdo->prepare("INSERT INTO weekly_stats (class_id, student_id, week_number, morning_count, evening_count) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$class_id, $student_id, $week_number, $morning_count, $evening_count]);
    }
}

// 获取学生本周状态
function getStudentStatus($student_id) {
    $pdo = getDB();
    $class_id = getClassId();
    $current_week = getWeekNumber();
    $today = date('Y-m-d');

    // 获取今日记录
    $stmt = $pdo->prepare("SELECT record_type, is_canceled FROM reading_records 
                          WHERE class_id = ? AND student_id = ? AND record_date = ? AND is_canceled = FALSE");
    $stmt->execute([$class_id, $student_id, $today]);
    $today_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today_morning = false;
    $today_evening = false;

    foreach ($today_records as $record) {
        if ($record['record_type'] === 'morning') $today_morning = true;
        if ($record['record_type'] === 'evening') $today_evening = true;
    }

    // 获取本周统计
    $stmt = $pdo->prepare("SELECT morning_count, evening_count, penalty_count FROM weekly_stats 
                          WHERE class_id = ? AND student_id = ? AND week_number = ?");
    $stmt->execute([$class_id, $student_id, $current_week]);
    $weekly_data = $stmt->fetch();

    $morning_count = $weekly_data ? $weekly_data['morning_count'] : 0;
    $evening_count = $weekly_data ? $weekly_data['evening_count'] : 0;
    $penalty_count = $weekly_data ? $weekly_data['penalty_count'] : 0;

    $total_records = $morning_count + $evening_count;
    $net_score = $total_records - $penalty_count;

    // 检查当前时间段是否有扣分
    $has_penalty_in_session = hasPenaltyThisWeek($student_id);

    return [
        'today_morning' => $today_morning,
        'today_evening' => $today_evening,
        'weekly_score' => $net_score,
        'penalty_count' => $penalty_count,
        'has_penalty_in_session' => $has_penalty_in_session,
        'session_added' => hasAddedThisSession($student_id)
    ];
}

// ============================================================
// 统计（含负分的表格数据）
// ============================================================

function getStatistics($period = 'week') {
    $pdo = getDB();
    $class_id = getClassId();
    $current_week = getWeekNumber();
    $current_month = getMonthNumber();

    switch ($period) {
        case 'day':
            $date = date('Y-m-d');
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND record_date = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $date]);
            break;

        case 'week':
            // 本周统计包含负分（净分）
            $sql = "SELECT ws.student_id, 
                           (COALESCE(ws.morning_count, 0) + COALESCE(ws.evening_count, 0) - COALESCE(ws.penalty_count, 0)) as score 
                    FROM weekly_stats ws 
                    WHERE ws.class_id = ? AND ws.week_number = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $current_week]);
            break;

        case 'month':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND month_number = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $current_month]);
            break;

        case 'semester':
            // 学期统计：从本学期开始日期起，不计负分
            $start = getSemesterStart();
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND record_date >= ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $start]);
            break;

        case 'total':
            // 总统计：全部记录，不计负分
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id]);
            break;
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 正分统计（用于饼状图，不含负分）
function getPositiveStatistics($period = 'week') {
    $pdo = getDB();
    $class_id = getClassId();
    $current_week = getWeekNumber();
    $current_month = getMonthNumber();

    switch ($period) {
        case 'day':
            $date = date('Y-m-d');
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND record_date = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $date]);
            break;

        case 'week':
            // 只统计正分，不包含负分
            $sql = "SELECT ws.student_id, 
                           (COALESCE(ws.morning_count, 0) + COALESCE(ws.evening_count, 0)) as score 
                    FROM weekly_stats ws 
                    WHERE ws.class_id = ? AND ws.week_number = ? 
                    AND (COALESCE(ws.morning_count, 0) + COALESCE(ws.evening_count, 0)) > 0";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $current_week]);
            break;

        case 'month':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND month_number = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $current_month]);
            break;

        case 'semester':
            $start = getSemesterStart();
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND record_date >= ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id, $start]);
            break;

        case 'total':
            $sql = "SELECT student_id, COUNT(*) as count 
                    FROM reading_records 
                    WHERE class_id = ? AND is_canceled = FALSE 
                    GROUP BY student_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$class_id]);
            break;
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 教师管理操作
// ============================================================

// 获取全部班级（含教师管理密码，供总管理 edit.php 使用）
function getAllClasses() {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT c.id, c.class_number, c.password, c.teacher_password,
                         (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count,
                         (SELECT COUNT(*) FROM reading_records r WHERE r.class_id = c.id AND r.is_canceled = FALSE) AS record_count,
                         (SELECT COUNT(*) FROM penalty_records p WHERE p.class_id = c.id) AS penalty_count
                         FROM classes c ORDER BY c.class_number ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 修改班级密码
function updateClassPassword($class_id, $new_password) {
    if (strlen($new_password) < 4) {
        return ['success' => false, 'message' => '密码至少 4 位'];
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE classes SET password = ? WHERE id = ?");
    $stmt->execute([$new_password, (int)$class_id]);
    return ['success' => true, 'message' => '班级密码已修改'];
}

// 修改教师管理密码
function updateTeacherPassword($class_id, $new_password) {
    if (strlen($new_password) < 4) {
        return ['success' => false, 'message' => '密码至少 4 位'];
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE classes SET teacher_password = ? WHERE id = ?");
    $stmt->execute([$new_password, (int)$class_id]);
    return ['success' => true, 'message' => '教师管理密码已修改'];
}

// 添加学生
function addStudent($class_id, $student_no, $name) {
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => '姓名不能为空'];
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO students (class_id, student_no, name_encoded) VALUES (?, ?, ?)");
    $stmt->execute([(int)$class_id, (int)$student_no, encodeName($name)]);
    return ['success' => true, 'message' => '已添加学生 ' . $name];
}

// 修改学生
function updateStudent($student_id, $name, $student_no = null) {
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => '姓名不能为空'];
    }
    $pdo = getDB();
    if ($student_no !== null && $student_no !== '') {
        $stmt = $pdo->prepare("UPDATE students SET name_encoded = ?, student_no = ? WHERE id = ?");
        $stmt->execute([encodeName($name), (int)$student_no, (int)$student_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE students SET name_encoded = ? WHERE id = ?");
        $stmt->execute([encodeName($name), (int)$student_id]);
    }
    return ['success' => true, 'message' => '已保存'];
}

// 删除学生（同时删除其记录）
function deleteStudent($student_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM reading_records WHERE student_id = ?");
    $stmt->execute([(int)$student_id]);
    $stmt = $pdo->prepare("DELETE FROM weekly_stats WHERE student_id = ?");
    $stmt->execute([(int)$student_id]);
    $stmt = $pdo->prepare("DELETE FROM penalty_records WHERE student_id = ?");
    $stmt->execute([(int)$student_id]);
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
    $stmt->execute([(int)$student_id]);
    return ['success' => true, 'message' => '学生及其记录已删除'];
}

// 清空班级全部数据（保留班级与名单）
function clearClassData($class_id) {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM reading_records WHERE class_id = ?")->execute([(int)$class_id]);
    $pdo->prepare("DELETE FROM weekly_stats WHERE class_id = ?")->execute([(int)$class_id]);
    $pdo->prepare("DELETE FROM penalty_records WHERE class_id = ?")->execute([(int)$class_id]);
    return ['success' => true, 'message' => '该班记录数据已清空'];
}

// ============================================================
// 批量导入（CSV：第一列学号，第二列姓名）
// ============================================================

// 解析 CSV 文本为行数组（兼容 UTF-8 BOM / GBK 编码 / 多种换行）
function parseCsvText($content) {
    // 去除 UTF-8 BOM
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    // 非 UTF-8 时尝试按 GBK 系列转换（Windows 版 Excel 导出的 CSV 常为 GBK）
    if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'GB18030,GBK,GB2312');
        if ($converted) $content = $converted;
    }
    // 统一换行符
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cells = str_getcsv($line);
        $rows[] = $cells;
    }
    return $rows;
}

// 规范化导入行：过滤表头/空行/非法行，返回 [有效行, 错误说明]
function normalizeImportRows($raw_rows) {
    $rows = [];
    $errors = [];
    foreach ($raw_rows as $idx => $cells) {
        $line_no = $idx + 1;
        $no = isset($cells[0]) ? trim((string)$cells[0]) : '';
        $name = isset($cells[1]) ? trim((string)$cells[1]) : '';
        // 学号非数字：表头（如"学号,姓名"）或无效行，跳过
        if ($no === '' || !ctype_digit($no)) {
            if ($no !== '' || $name !== '') {
                $errors[] = "第 {$line_no} 行跳过：学号必须是数字（表头行会自动跳过）";
            }
            continue;
        }
        if ($name === '') {
            $errors[] = "第 {$line_no} 行跳过：姓名为空";
            continue;
        }
        $name_len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name_len > 20) {
            $errors[] = "第 {$line_no} 行跳过：姓名过长";
            continue;
        }
        $rows[] = ['student_no' => (int)$no, 'name' => $name];
    }
    return [$rows, $errors];
}

// 执行导入（学号已存在时直接覆盖姓名，返回统计）
function importStudents($class_id, $raw_rows) {
    list($rows, $errors) = normalizeImportRows($raw_rows);
    if (empty($rows)) {
        return [
            'success' => false,
            'message' => '没有可导入的学生（格式：第一列学号，第二列姓名）',
            'imported' => 0,
            'updated' => 0,
            'errors' => $errors
        ];
    }

    $pdo = getDB();
    // 现有学号集合（用于判断覆盖或新增；文件内重复学号也按后者覆盖前者处理）
    $stmt = $pdo->prepare("SELECT student_no FROM students WHERE class_id = ?");
    $stmt->execute([(int)$class_id]);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $existing[(int)$n] = true;
    }

    $imported = 0;
    $updated = 0;
    $upd = $pdo->prepare("UPDATE students SET name_encoded = ? WHERE class_id = ? AND student_no = ?");
    $ins = $pdo->prepare("INSERT INTO students (class_id, student_no, name_encoded) VALUES (?, ?, ?)");
    foreach ($rows as $r) {
        $encoded = encodeName($r['name']);
        if (isset($existing[$r['student_no']])) {
            $upd->execute([$encoded, (int)$class_id, $r['student_no']]);
            $updated++;
        } else {
            $ins->execute([(int)$class_id, $r['student_no'], $encoded]);
            $existing[$r['student_no']] = true;
            $imported++;
        }
    }

    $msg = "成功导入 {$imported} 名学生";
    if ($updated > 0) $msg .= "，覆盖 {$updated} 名（学号已存在，姓名已更新）";
    if (!empty($errors)) $msg .= "；格式问题 " . count($errors) . " 处（已跳过）";
    return ['success' => true, 'message' => $msg, 'imported' => $imported, 'updated' => $updated, 'errors' => $errors];
}

// 解析上传的名单文件（.xlsx 用 SimpleXLSX，其余按文本/CSV 处理），返回 ['rows' => [...]] 或 ['error' => '...']
function parseStudentFile($upload) {
    if (!isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        return ['error' => '文件上传失败，请重试'];
    }
    if ($upload['size'] > 512 * 1024) {
        return ['error' => '文件过大（最大 512KB）'];
    }
    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            return ['error' => '服务器缺少 zip 扩展，无法解析 .xlsx，请把名单另存为 .csv 后上传'];
        }
        require_once __DIR__ . '/simplexlsx.php';
        $xlsx = Shuchkin\SimpleXLSX::parse($upload['tmp_name']);
        if (!$xlsx) {
            return ['error' => '无法解析该 Excel 文件（' . Shuchkin\SimpleXLSX::parseError() . '），请确认是用 Excel 保存的 .xlsx'];
        }
        $rows = [];
        foreach ($xlsx->rows() as $row) {
            $cells = array_values((array)$row);
            if (!empty($cells)) $rows[] = $cells;
        }
        return ['rows' => $rows];
    }
    // 其他（.csv 等）按文本解析
    return ['rows' => parseCsvText(file_get_contents($upload['tmp_name']))];
}
// 输出学生名单模板（.xlsx）
// 直接构建标准 ZIP 字节流（STORE 无压缩），不依赖 ZipArchive 扩展与临时文件，
// 兼容 tempnam 受限/禁用的主机环境
function outputStudentTemplate() {
    $files = [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',
        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="学生名单" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',
        'xl/worksheets/sheet1.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>学号</t></is></c><c r="B1" t="inlineStr"><is><t>姓名</t></is></c></row>'
            . '<row r="2"><c r="A2" t="n"><v>1</v></c><c r="B2" t="inlineStr"><is><t>示例学生</t></is></c></row>'
            . '<row r="3"><c r="A3" t="n"><v>2</v></c><c r="B3" t="inlineStr"><is><t>示例学生</t></is></c></row>'
            . '</sheetData></worksheet>',
    ];

    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $nameLen = strlen($name);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0) . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += 30 + $nameLen + $size;
    }
    $cdSize = strlen($central);
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), $cdSize, $offset, 0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="学生名单模板.xlsx"');
    echo $local . $central . $eocd;
    exit;
}
