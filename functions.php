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
}

// 检查教师管理登录状态（教师只能管理自己所在班级）
function checkAdminAuth() {
    if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true || empty($_SESSION['teacher_class_id'])) {
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

// 检查是否在可记录时间段内
function canRecord() {
    $current_hour = (int)date('H');
    $current_minute = (int)date('i');

    // 早读时间: 6:20-7:10
    $morning_start = 6 * 60 + 20;
    $morning_end = 7 * 60 + 10;

    // 晚读时间: 17:45-18:20
    $evening_start = 17 * 60 + 45;
    $evening_end = 18 * 60 + 20;

    $current_minutes = $current_hour * 60 + $current_minute;

    return ($current_minutes >= $morning_start && $current_minutes <= $morning_end) ||
           ($current_minutes >= $evening_start && $current_minutes <= $evening_end);
}

// 获取当前记录类型 (早读/晚读)
function getCurrentRecordType() {
    $current_hour = (int)date('H');
    return ($current_hour < 12) ? 'morning' : 'evening';
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

// 检查当前时间段是否有扣分
function hasPenaltyInCurrentSession($student_id) {
    $pdo = getDB();
    $current_week = getWeekNumber();
    $current_type = getCurrentRecordType();
    $class_id = getClassId();

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM penalty_records 
                          WHERE class_id = ? AND student_id = ? AND week_number = ? AND record_type = ?");
    $stmt->execute([$class_id, $student_id, $current_week, $current_type]);
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
    $has_penalty = hasPenaltyInCurrentSession($student_id);

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

// 扣分处理（扣分不限次数）
function penalizeStudent($student_id) {
    if (!canRecord()) {
        return ['success' => false, 'message' => '当前不在可记录时间段内'];
    }

    $pdo = getDB();
    $class_id = getClassId();
    $current_week = getWeekNumber();
    $current_type = getCurrentRecordType();

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
    $has_penalty_in_session = hasPenaltyInCurrentSession($student_id);

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
?>
