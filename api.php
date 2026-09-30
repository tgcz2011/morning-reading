<?php
/**
 * ============================================================
 * 班级朗读记录系统 API v1.1
 *
 * 认证流程（三步）：
 *   1. 客户端先调 get_seed 获取种子（无需 token）：
 *        GET api.php?action=get_seed&identity=record|teacher|superadmin
 *      → 返回 { seed, slot, identity, server_time }
 *   2. 客户端本地计算 token：
 *        token = sha256(用户名 : 密码 : 种子)
 *      三种身份的种子在同一请求条件下互不相同
 *      （seed = sha256(API_SEED : 当前小时 : 身份)，身份不同 → 种子不同）。
 *   3. 请求携带 token：
 *        Authorization: Bearer <token>  或  ?token=<token>
 *
 * 用户名与密码（对应三种身份，权限从高到低）：
 *   superadmin  用户名 = superadmin，密码 = SUPERADMIN_PASSWORD（config.php）
 *   teacher     用户名 = 年级-班号（如 9-6），密码 = 教师管理密码
 *   record      用户名 = 年级-班号（如 9-6），密码 = 班级登录密码
 *
 * 服务器按 superadmin → teacher → record 顺序尝试，命中即取该身份；
 * 若某班级两套密码相同，用同一用户名+密码请求会得到更高身份权限。
 *
 * 验证窗口：当前小时 ± 1 小时（3 个 slot 都尝试），
 * token 最长有效约 3 小时、最短约 1 小时，每小时随种子轮换失效。
 *
 * 权限矩阵：
 *   record      status / students / stats / add_record / cancel_record / penalize
 *   teacher     全部端点（本班，与教师管理页一致）
 *   superadmin  全部端点 + 需用 grade_class=年级-班号 指定班级（可操作任意班）
 *
 * 使用示例（bash）：
 *   SEED=$(curl -s "...api.php?action=get_seed&identity=teacher" | 解析 seed)
 *   TOKEN=$(printf '%s' "9-6:admin06:$SEED" | sha256sum | cut -d' ' -f1)
 *   curl -H "Authorization: Bearer $TOKEN" "...api.php?username=9-6&action=students"
 *
 * 全部端点见教师管理页（admin.php?tab=api）的 API 文档。
 * ============================================================
 */

require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- 种子 / Token ----------

// 某身份在某个小时槽位的种子（三种身份在同一请求条件下互不相同）
function apiSeed($identity, $slot_key) {
    return hash('sha256', API_SEED . ':' . $slot_key . ':' . $identity);
}

function apiTokenFor($username, $password, $seed) {
    return hash('sha256', $username . ':' . $password . ':' . $seed);
}

// 按身份解析用户名对应的密码与班级；无效返回 null
function apiIdentityInfo($identity, $username) {
    switch ($identity) {
        case 'superadmin':
            return ($username === 'superadmin' && defined('SUPERADMIN_PASSWORD') && SUPERADMIN_PASSWORD !== '')
                ? ['password' => SUPERADMIN_PASSWORD, 'class' => null]
                : null;
        case 'teacher':
        case 'record':
            if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', $username, $m)) return null;
            $class = apiFindClass((int)$m[1], (int)$m[2]);
            if (!$class) return null;
            $password = ($identity === 'teacher') ? $class['teacher_password'] : $class['password'];
            if ($password === '' || $password === null) return null;
            return ['password' => $password, 'class' => $class];
    }
    return null;
}

function apiFindClass($grade, $class_number) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE grade = ? AND class_number = ?");
    $stmt->execute([(int)$grade, (int)$class_number]);
    return $stmt->fetch();
}

// 认证：返回 ['identity' => ..., 'class' => ...]；失败返回 null
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

    $username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : (isset($_REQUEST['u']) ? trim($_REQUEST['u']) : '');
    if ($username === '') return null;

    // 按 superadmin → teacher → record 顺序尝试（密码相同时取更高权限）
    foreach (['superadmin', 'teacher', 'record'] as $identity) {
        $info = apiIdentityInfo($identity, $username);
        if ($info === null) continue;
        for ($offset = -1; $offset <= 1; $offset++) {
            $slot = date('YmdH', time() + $offset * 3600);
            if (hash_equals(apiTokenFor($username, $info['password'], apiSeed($identity, $slot)), $token)) {
                return ['identity' => $identity, 'class' => $info['class']];
            }
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

$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

// 获取种子：无鉴权，客户端凭此 + 用户名 + 密码 本地计算 token
if ($action === 'get_seed') {
    $identity = isset($_REQUEST['identity']) ? trim($_REQUEST['identity']) : '';
    if (!in_array($identity, ['record', 'teacher', 'superadmin'], true)) {
        apiError('identity 参数无效：可选 record（班级登录）/ teacher（教师管理）/ superadmin（总管理）');
    }
    $slot = date('YmdH');
    apiOut([
        'success' => true,
        'data' => [
            'identity'    => $identity,
            'slot'        => $slot,
            'seed'        => apiSeed($identity, $slot),
            'server_time' => date('Y-m-d H:i:s'),
            'formula'     => 'token = sha256(用户名 : 密码 : 种子)，该种子仅此身份在当前小时内有效',
        ],
    ]);
}

$auth = apiAuthenticate();
if (!$auth) {
    apiOut([
        'success' => false,
        'code' => 401,
        'message' => '认证失败：token 无效或已过期。请先调用 action=get_seed（带上身份）获取种子，再用 用户名+密码+种子 计算 token。',
        'slot' => date('YmdH'), // 客户端可用服务器时槽重算
    ], 401);
}

$identity = $auth['identity'];
$class = $auth['class'];

// superadmin：班级从请求参数解析（grade_class=9-6 或 grade + class_number）
if ($identity === 'superadmin') {
    $gc = isset($_REQUEST['grade_class']) ? trim($_REQUEST['grade_class']) : '';
    if ($gc !== '' && preg_match('/^(\d{1,2})-(\d{1,2})$/', $gc, $gm)) {
        $grade = (int)$gm[1];
        $class_number = (int)$gm[2];
    } else {
        $grade = isset($_REQUEST['grade']) ? (int)$_REQUEST['grade'] : 0;
        $class_number = isset($_REQUEST['class_number']) ? (int)$_REQUEST['class_number'] : 0;
    }
    $class = apiFindClass($grade, $class_number);
    if (!$class) {
        apiError('总管理身份需指定班级：grade_class=年级-班号（如 9-6）或 grade + class_number');
    }
}

$class_id = (int)$class['id'];
$_SESSION['class_id'] = $class_id;
$_SESSION['class_number'] = (int)$class['class_number'];
$_SESSION['grade'] = (int)$class['grade'];

// 权限：仅教师/总管理可做的端点（record 身份访问 → 403）
$teacher_only = ['add_student', 'update_student', 'delete_student', 'import_students', 'clear_data', 'clear_all_data'];
if ($identity === 'record' && in_array($action, $teacher_only, true)) {
    apiError('该操作需要教师管理或总管理权限（当前身份：班级记录）', 403);
}

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
                    'identity'      => $identity,
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
            apiError('未知 action：' . $action . '。可用：get_seed/status/students/stats/add_record/cancel_record/penalize/add_student/update_student/delete_student/import_students/clear_data/clear_all_data', 400);
    }
} catch (Exception $e) {
    apiError('服务器错误：' . $e->getMessage(), 500);
}
