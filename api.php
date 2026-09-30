<?php
/**
 * ============================================================
 * 班级朗读记录系统 API v1
 *
 * 认证方式：Authorization: Bearer <token>（或 ?token= 参数）
 *   username = "年级-班级号"（如 9-6 = 初三6班，0-14 不存在）
 *   password = 教师管理密码（admin.php 登录用的那个）
 *
 * token 生成公式（种子按小时轮换）：
 *   slot_key  = date('YmdH')                       # 当前小时，如 2026093014
 *   slot_seed = sha256(API_SEED . ':' . slot_key)  # API_SEED 在 config.php
 *   token     = sha256(username . ':' . password . ':' . slot_seed)
 *
 * 验证窗口：当前小时 ± 1 小时（防止请求正好跨过整点边界失败）。
 * 因此 token 最长有效约 3 小时、最短约 1 小时，每小时变化一次。
 *
 * 使用示例：
 *   curl "https://zztool.free.nf/morning-reading/api.php?username=9-6&action=students" \
 *        -H "Authorization: Bearer <token>"
 *
 * 全部端点见教师管理页（admin.php?tab=api）的 API 文档。
 * ============================================================
 */

require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- 认证 ----------

// 计算某小时槽位的 token
function apiTokenFor($username, $password, $slot_key) {
    $slot_seed = hash('sha256', API_SEED . ':' . $slot_key);
    return hash('sha256', $username . ':' . $password . ':' . $slot_seed);
}

// 认证并返回班级记录；失败返回 null
function apiAuthenticate() {
    // 取 token：Authorization: Bearer xxx 或 ?token=xxx
    $token = null;
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        $token = trim($m[1]);
    }
    if (!$token && isset($_REQUEST['token'])) {
        $token = trim((string)$_REQUEST['token']);
    }
    if (!$token || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    // username = 年级-班号（如 9-6）
    $username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : (isset($_REQUEST['u']) ? trim($_REQUEST['u']) : '');
    if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', $username, $m)) {
        return null;
    }
    $grade = (int)$m[1];
    $class_number = (int)$m[2];

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE grade = ? AND class_number = ?");
    $stmt->execute([$grade, $class_number]);
    $class = $stmt->fetch();
    if (!$class || empty($class['teacher_password'])) {
        return null;
    }

    // 当前/上/下 3 个时段窗口比对
    $t = time();
    for ($offset = -1; $offset <= 1; $offset++) {
        $slot_key = date('YmdH', $t + $offset * 3600);
        if (hash_equals(apiTokenFor($username, $class['teacher_password'], $slot_key), $token)) {
            return $class;
        }
    }
    return null;
}

// 按学号查找学生
function apiFindStudent($class_id, $student_no) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM students WHERE class_id = ? AND student_no = ?");
    $stmt->execute([(int)$class_id, (int)$student_no]);
    return $stmt->fetch();
}

// ---------- 统一响应 ----------
function apiOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError($message, $code = 400) {
    apiOut(['success' => false, 'code' => $code, 'message' => $message], $code);
}

// ---------- 主流程 ----------

$class = apiAuthenticate();
if (!$class) {
    apiOut([
        'success' => false,
        'code' => 401,
        'message' => '认证失败：token 无效或已过期。token 按小时变化，请到教师管理页（admin.php?tab=api）复制最新 token。',
    ], 401);
}

// 设置班级上下文（functions.php 的 addRecord/统计等通过 session 读班级）
$class_id = (int)$class['id'];
$_SESSION['class_id'] = $class_id;
$_SESSION['class_number'] = (int)$class['class_number'];
$_SESSION['grade'] = (int)$class['grade'];

$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$pdo = getDB();

try {
    switch ($action) {

        // ---------- 状态 ----------
        case 'status':
            $now = time();
            apiOut([
                'success' => true,
                'data' => [
                    'class_id'      => $class_id,
                    'class_name'    => getClassName($class['class_number'], $class['grade']),
                    'grade'         => (int)$class['grade'],
                    'class_number'  => (int)$class['class_number'],
                    'server_time'   => date('Y-m-d H:i:s', $now),
                    'period_text'   => getPeriodRangeText(),
                    'current_type'  => getCurrentRecordType(), // morning / evening / null
                    'can_record'    => canRecord(),
                    'token_slot'    => date('YmdH', $now),     // 当前种子时槽
                    'token_expires' => date('Y-m-d H:i:s', $now + 3600), // 本时槽结束后进入下一窗口
                ],
            ]);
            break;

        // ---------- 学生名单（含今日状态/周统计） ----------
        case 'students':
            $students = getStudents($class_id);
            $status = getAllStudentsStatus($class_id);
            $list = [];
            foreach ($students as $s) {
                $sid = (int)$s['id'];
                $st = isset($status[$sid]) ? $status[$sid] : [
                    'today_morning' => false, 'today_evening' => false, 'weekly_score' => 0,
                    'penalty_count' => 0, 'has_penalty_in_session' => false, 'session_added' => false,
                ];
                $list[] = [
                    'student_id'    => $sid,
                    'student_no'    => $s['student_no'],
                    'name'          => $s['name'],
                    'today_morning' => $st['today_morning'],
                    'today_evening' => $st['today_evening'],
                    'weekly_score'  => $st['weekly_score'],
                    'penalty_count' => $st['penalty_count'],
                    'session_added' => $st['session_added'],
                ];
            }
            apiOut(['success' => true, 'data' => ['count' => count($list), 'students' => $list]]);
            break;

        // ---------- 统计（day/week/month/semester/total） ----------
        case 'stats':
            $period = isset($_REQUEST['period']) ? trim($_REQUEST['period']) : 'week';
            if (!in_array($period, ['day', 'week', 'month', 'semester', 'total'], true)) {
                apiError('period 参数无效：可选 day/week/month/semester/total');
            }
            $result = getStatisticsCombined($period);
            $map = getStudentMap($class_id);
            $rows = [];
            foreach ($result['table'] as $r) {
                $sid = (int)$r['student_id'];
                $info = isset($map[$sid]) ? $map[$sid] : ['name' => '?', 'student_no' => ''];
                $rows[] = [
                    'student_no' => $info['student_no'],
                    'name'       => $info['name'],
                    'score'      => (int)$r['score'],
                ];
            }
            usort($rows, function ($a, $b) { return $b['score'] - $a['score']; });
            apiOut(['success' => true, 'data' => ['period' => $period, 'ranking' => $rows]]);
            break;

        // ---------- 加分 ----------
        case 'add_record':
            $student_no = isset($_REQUEST['student_no']) ? (int)$_REQUEST['student_no'] : 0;
            $student = $student_no > 0 ? apiFindStudent($class_id, $student_no) : false;
            if (!$student) apiError('未找到学号 ' . $_REQUEST['student_no'] . ' 的学生');
            $type = isset($_REQUEST['type']) && in_array($_REQUEST['type'], ['morning', 'evening'], true) ? $_REQUEST['type'] : null;
            $r = addRecord((int)$student['id'], $type);
            if (!$r['success']) apiError($r['message'], 400);
            apiOut(['success' => true, 'message' => $r['message'], 'data' => ['student_no' => $student_no]]);
            break;

        // ---------- 取消记录 ----------
        case 'cancel_record':
            $student_no = isset($_REQUEST['student_no']) ? (int)$_REQUEST['student_no'] : 0;
            $student = $student_no > 0 ? apiFindStudent($class_id, $student_no) : false;
            if (!$student) apiError('未找到学号 ' . $_REQUEST['student_no'] . ' 的学生');
            $type = isset($_REQUEST['type']) && in_array($_REQUEST['type'], ['morning', 'evening'], true) ? $_REQUEST['type'] : null;
            $r = cancelRecord((int)$student['id'], $type);
            if (!$r['success']) apiError($r['message'], 400);
            apiOut(['success' => true, 'message' => $r['message']]);
            break;

        // ---------- 扣分 ----------
        case 'penalize':
            $student_no = isset($_REQUEST['student_no']) ? (int)$_REQUEST['student_no'] : 0;
            $student = $student_no > 0 ? apiFindStudent($class_id, $student_no) : false;
            if (!$student) apiError('未找到学号 ' . $_REQUEST['student_no'] . ' 的学生');
            $r = penalizeStudent((int)$student['id']);
            if (!$r['success']) apiError(isset($r['message']) ? $r['message'] : '扣分失败', 400);
            apiOut(['success' => true, 'message' => isset($r['message']) ? $r['message'] : '扣分成功', 'data' => $r]);
            break;

        // ---------- 添加学生 ----------
        case 'add_student':
            $student_no = isset($_REQUEST['student_no']) ? (int)$_REQUEST['student_no'] : 0;
            $name = isset($_REQUEST['name']) ? trim((string)$_REQUEST['name']) : '';
            if ($student_no <= 0 || $name === '') apiError('student_no 和 name 为必填参数');
            $r = addStudent($class_id, $student_no, $name);
            if (!$r['success']) apiError($r['message'], 400);
            apiOut(['success' => true, 'message' => $r['message']]);
            break;

        // ---------- 修改学生 ----------
        case 'update_student':
            $student_no = isset($_REQUEST['student_no']) ? (int)$_REQUEST['student_no'] : 0;
            $name = isset($_REQUEST['name']) ? trim((string)$_REQUEST['name']) : '';
            $student = $student_no > 0 ? apiFindStudent($class_id, $student_no) : false;
            if (!$student) apiError('未找到学号 ' . $_REQUEST['student_no'] . ' 的学生');
            if ($name === '') apiError('name 为必填参数');
            $new_no = isset($_REQUEST['new_no']) && $_REQUEST['new_no'] !== '' ? (int)$_REQUEST['new_no'] : null;
            $r = updateStudent((int)$student['id'], $name, $new_no);
            apiOut(['success' => true, 'message' => $r['message']]);
            break;

        // ---------- 删除学生 ----------
        case 'delete_student':
            $student_no = isset($_REQUEST['student_no']) ? (int)$_REQUEST['student_no'] : 0;
            $student = $student_no > 0 ? apiFindStudent($class_id, $student_no) : false;
            if (!$student) apiError('未找到学号 ' . $_REQUEST['student_no'] . ' 的学生');
            $r = deleteStudent((int)$student['id']);
            apiOut(['success' => true, 'message' => $r['message']]);
            break;

        // ---------- 批量导入学生（冲突覆盖） ----------
        // 支持两种格式：
        //   JSON：{"students":[{"no":1,"name":"张三"},...]}
        //   文本：每行 "学号,姓名"（UTF-8）
        case 'import_students':
            $raw = file_get_contents('php://input');
            $rows = [];
            if ($raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json) && isset($json['students']) && is_array($json['students'])) {
                    foreach ($json['students'] as $item) {
                        $no = isset($item['no']) ? (string)$item['no'] : (isset($item['student_no']) ? (string)$item['student_no'] : '');
                        $nm = isset($item['name']) ? (string)$item['name'] : '';
                        $rows[] = [$no, $nm];
                    }
                } elseif (is_array($json)) {
                    foreach ($json as $item) {
                        $no = isset($item['no']) ? (string)$item['no'] : (isset($item['student_no']) ? (string)$item['student_no'] : '');
                        $nm = isset($item['name']) ? (string)$item['name'] : '';
                        $rows[] = [$no, $nm];
                    }
                } else {
                    // 文本行格式：学号,姓名
                    $rows = parseCsvText($raw);
                }
            }
            if (empty($rows)) apiError('请求体为空：请传 JSON {"students":[{"no":1,"name":"张三"}]} 或文本行 "1,张三"');
            $r = importStudents($class_id, $rows);
            apiOut([
                'success' => $r['success'],
                'message' => $r['message'],
                'imported' => $r['imported'],
                'updated'  => $r['updated'],
                'errors'   => isset($r['errors']) ? $r['errors'] : [],
            ], $r['success'] ? 200 : 400);
            break;

        // ---------- 清空记录（保留名单） ----------
        case 'clear_data':
            $r = clearClassData($class_id);
            apiOut(['success' => true, 'message' => $r['message']]);
            break;

        // ---------- 清空全部（含名单） ----------
        case 'clear_all_data':
            $r = clearClassAllData($class_id);
            apiOut(['success' => $r['success'], 'message' => $r['message']]);
            break;

        default:
            apiError('未知 action：' . $action . '。可用：status/students/stats/add_record/cancel_record/penalize/add_student/update_student/delete_student/import_students/clear_data/clear_all_data', 400);
    }
} catch (Exception $e) {
    apiError('服务器错误：' . $e->getMessage(), 500);
}
