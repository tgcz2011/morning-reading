<?php
require_once 'functions.php';
initDatabase();

// ================= 下载导入模板（Excel .xlsx，无需登录，模板无敏感数据） =================
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    outputStudentTemplate();
}

// ================= 教师登录（班级号 + 教师管理密码） =================
if (isset($_POST['teacher_login'])) {
    $grade = isset($_POST['grade']) ? (int)$_POST['grade'] : 9;
    $class_number = isset($_POST['class_number']) ? (int)$_POST['class_number'] : 0;
    if (teacherLogin($grade, $class_number, $_POST['password'])) {
        header('Location: admin.php');
        exit;
    } else {
        $error = "年级、班级或教师管理密码错误";
    }
}

// 教师登录有效期 7 天：过期即清除登录态回到登录页
if (isset($_SESSION['teacher_logged_in']) && $_SESSION['teacher_logged_in'] === true &&
    (!isset($_SESSION['teacher_login_time']) || time() - $_SESSION['teacher_login_time'] > 7 * 86400)) {
    session_unset();
    session_destroy();
}

if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>教师管理 - 班级朗读记录系统</title>
        <link rel="stylesheet" href="style.css?v=4">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="header-title">
                    <h1>教师管理</h1>
                    <span class="session-seal">教师</span>
                </div>
            </div>
            <div class="login-form">
                <h2>选择年级、输入班级号和教师管理密码</h2>
                <?php if (isset($error)): ?>
                    <div class="message error"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php $sel_grade = isset($grade) ? $grade : 9; ?>
                <form method="POST">
                    <select name="grade" id="gradeSelect" class="login-input" required>
                        <?php foreach (gradeList() as $g => $gname): ?>
                            <option value="<?php echo $g; ?>" <?php echo $g === $sel_grade ? 'selected' : ''; ?>><?php echo $gname; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="class_number" id="classNumberInput" class="login-input" placeholder="班级号，如 01" min="1" max="14" required>
                    <input type="password" name="password" placeholder="教师管理密码" required>
                    <button type="submit" name="teacher_login">进入管理</button>
                </form>
                <p class="login-hint">先选年级，再填班级号：初中 01-14，高中 01-11<br>教师管理密码与班级登录密码分开，初始相同（admin + 班级号）<br>忘记密码可联系总管理重置</p>
                <script>
                (function(){
                    var sel = document.getElementById('gradeSelect');
                    var inp = document.getElementById('classNumberInput');
                    function syncMax(){
                        var g = parseInt(sel.value, 10);
                        inp.max = (g >= 7 && g <= 9) ? 14 : 11;
                        if (inp.value && parseInt(inp.value, 10) > parseInt(inp.max, 10)) inp.value = '';
                    }
                    sel.addEventListener('change', syncMax);
                    syncMax();
                })();
                </script>
            </div>
        </div>
        <footer class="site-footer">
            <div class="footer-title">班级朗读记录系统 v<?php echo APP_VERSION; ?></div>
            <div>
                作者：<a href="http://zztool.free.nf" target="_blank" rel="noopener" class="author-link"><?php echo APP_AUTHOR; ?></a><span class="author-hint">（点我看作者主页）</span>
                <span class="footer-sep">·</span>
                技术支持：豆包（Doubao）
                <span class="footer-sep">·</span>
                GPL-3.0 许可
            </div>
            <div>
                <a href="<?php echo APP_REPO; ?>" target="_blank" rel="noopener">GitHub 仓库</a>
                <span class="footer-sep">·</span>
                问题反馈：<a href="<?php echo APP_REPO; ?>/issues" target="_blank" rel="noopener">GitHub Issues</a>
            </div>
        </footer>
    </body>
    </html>
    <?php
    exit;
}

// ================= 处理教师操作（仅限本班） =================
$teacher_class_id = getTeacherClassId();
$teacher_class_number = getTeacherClassNumber();
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'students';

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirect = 'admin.php?tab=' . $tab;

    if ($action === 'change_class_password' && isset($_POST['new_password'])) {
        $r = updateClassPassword($teacher_class_id, $_POST['new_password']);
    } elseif ($action === 'change_teacher_password' && isset($_POST['new_password'])) {
        $r = updateTeacherPassword($teacher_class_id, $_POST['new_password']);
    } elseif ($action === 'add_student' && isset($_POST['student_no'], $_POST['name'])) {
        $r = addStudent($teacher_class_id, $_POST['student_no'], $_POST['name']);
    } elseif ($action === 'update_student' && isset($_POST['student_id'], $_POST['name'])) {
        $r = updateStudent((int)$_POST['student_id'], $_POST['name'], isset($_POST['student_no']) ? $_POST['student_no'] : null);
    } elseif ($action === 'delete_student' && isset($_POST['student_id'])) {
        $r = deleteStudent((int)$_POST['student_id']);
    } elseif ($action === 'clear_data') {
        $r = clearClassData($teacher_class_id);
    } elseif ($action === 'clear_all_data') {
        $r = clearClassAllData($teacher_class_id);
    } elseif ($action === 'preview_import') {
        // 第一步：上传 Excel → 解析 → 存入会话待确认
        $parsed = parseStudentFile(isset($_FILES['csv_file']) ? $_FILES['csv_file'] : null);
        if (isset($parsed['error'])) {
            $r = ['success' => false, 'message' => $parsed['error']];
        } else {
            $raw = $parsed['rows'];
            list($rows) = normalizeImportRows($raw);
            if (empty($rows)) {
                $r = ['success' => false, 'message' => '未解析到有效行（格式：第一列学号，第二列姓名）'];
            } else {
                $_SESSION['import_preview'][$teacher_class_id] = ['rows' => $raw, 'raw_count' => count($raw)];
                $r = ['success' => true, 'message' => '已解析 ' . count($rows) . ' 名学生，请在下方确认'];
                $redirect = 'admin.php?tab=students&preview=1';
            }
        }
    } elseif ($action === 'confirm_import') {
        // 第二步：确认导入
        if (!empty($_SESSION['import_preview'][$teacher_class_id])) {
            $r = importStudents($teacher_class_id, $_SESSION['import_preview'][$teacher_class_id]['rows']);
            unset($_SESSION['import_preview'][$teacher_class_id]);
        } else {
            $r = ['success' => false, 'message' => '没有待确认的导入数据'];
        }
    } elseif ($action === 'cancel_import') {
        unset($_SESSION['import_preview'][$teacher_class_id]);
        $r = ['success' => true, 'message' => '已取消导入'];
    } elseif ($action === 'teacher_logout') {
        unset($_SESSION['teacher_logged_in'], $_SESSION['teacher_class_id'], $_SESSION['teacher_class_number'], $_SESSION['teacher_grade']);
        header('Location: admin.php');
        exit;
    } else {
        $r = ['success' => false, 'message' => '无效操作'];
    }

    $_SESSION['admin_msg'] = $r['message'];
    $_SESSION['admin_msg_type'] = $r['success'] ? 'success' : 'error';
    header('Location: ' . $redirect);
    exit;
}

$message = '';
$message_type = '';
if (isset($_SESSION['admin_msg'])) {
    $message = $_SESSION['admin_msg'];
    $message_type = $_SESSION['admin_msg_type'];
    unset($_SESSION['admin_msg']);
    unset($_SESSION['admin_msg_type']);
}

// 获取本班信息与学生
$stmt = getDB()->prepare("SELECT * FROM classes WHERE id = ?");
$stmt->execute([$teacher_class_id]);
$class_info = $stmt->fetch();
$students = getStudents($teacher_class_id);
// 教师管理登录剩余秒数（7天过期，浏览器端 performance.now() 倒计时）
$admin_remaining = max(0, 7 * 86400 - (time() - $_SESSION['teacher_login_time']));

// 待确认的导入预览（会话中）
$import_preview = isset($_SESSION['import_preview'][$teacher_class_id]) ? $_SESSION['import_preview'][$teacher_class_id] : null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>教师管理 - <?php echo getClassName($teacher_class_number, getTeacherGrade()); ?></title>
    <link rel="stylesheet" href="style.css?v=4">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">
                <h1>教师管理</h1>
                <span class="session-seal"><?php echo getClassName($teacher_class_number, getTeacherGrade()); ?></span>
            </div>
            <div class="time-info"><?php echo date('Y年m月d日 H:i'); ?></div>
        </div>

        <div class="nav-bar">
            <a href="index.php" class="nav-btn">记录页面</a>
            <a href="stats.php" class="nav-btn">统计页面</a>
            <a href="admin.php?tab=students" class="nav-btn <?php echo $tab === 'students' ? 'active' : ''; ?>">学生名单</a>
            <a href="admin.php?tab=passwords" class="nav-btn <?php echo $tab === 'passwords' ? 'active' : ''; ?>">本班密码</a>
            <a href="admin.php?tab=data" class="nav-btn <?php echo $tab === 'data' ? 'active' : ''; ?>">数据管理</a>
            <a href="admin.php?tab=api" class="nav-btn <?php echo $tab === 'api' ? 'active' : ''; ?>">API 接口</a>
            <form method="POST" style="margin:0;padding:0;display:inline;">
                <input type="hidden" name="action" value="teacher_logout">
                <button type="submit" class="nav-btn nav-logout">退出</button>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="admin-body">
            <?php if ($tab === 'students'): ?>
                <!-- ========== 学生名单管理（本班） ========== -->
                <h2 class="stats-title">学生名单管理</h2>
                <div class="stats-note">
                    <strong><?php echo getClassName($teacher_class_number, getTeacherGrade()); ?>：</strong>
                    <span>共 <?php echo count($students); ?> 名学生（姓名自动转码存储，不受数据库中文编码限制）</span>
                </div>

                <!-- 批量导入（Excel） -->
                <div class="import-box">
                    <div class="import-title">批量导入（Excel）</div>
                    <div class="import-sub">下载模板或用自己班的全班 Excel 名单：<strong>第一列学号，第二列姓名</strong>，每行一名学生，可含表头行；填好后直接上传 <strong>.xlsx</strong> 文件即可</div>
                    <form method="POST" enctype="multipart/form-data" class="admin-inline-form" style="flex-wrap:wrap;">
                        <input type="hidden" name="action" value="preview_import">
                        <input type="file" name="csv_file" accept=".xlsx,.csv" class="admin-input" required>
                        <button type="submit" class="admin-btn solid">解析并预览</button>
                        <a href="admin.php?action=download_template" class="admin-btn">下载 Excel 模板</a>
                    </form>

                    <?php if ($import_preview): ?>
                    <?php $preview_rows = normalizeImportRows($import_preview['rows'])[0]; ?>
                    <div class="import-preview">
                        <div class="stats-note" style="margin-top:12px;">
                            <strong>预览：</strong>共解析 <?php echo count($preview_rows); ?> 名学生（学号已存在的会直接覆盖姓名），确认后写入：
                        </div>
                        <table class="ranking-table admin-student-table">
                            <thead><tr><th width="25%">学号</th><th width="75%">姓名</th></tr></thead>
                            <tbody>
                                <?php
                                $shown = 0;
                                foreach ($preview_rows as $r) {
                                    if ($shown++ >= 50) break;
                                    echo "<tr><td>{$r['student_no']}</td><td>" . htmlspecialchars($r['name']) . "</td></tr>";
                                }
                                if (count($preview_rows) > 50) {
                                    echo '<tr><td colspan="2" style="color:var(--ink-faint);">… 其余 ' . (count($preview_rows) - 50) . ' 行略，共 ' . count($preview_rows) . ' 行</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                        <form method="POST" class="admin-inline-form" style="margin-top:10px;gap:10px;">
                            <input type="hidden" name="action" value="confirm_import">
                            <button type="submit" class="admin-btn solid">确认导入</button>
                        </form>
                        <form method="POST" class="admin-inline-form">
                            <input type="hidden" name="action" value="cancel_import">
                            <button type="submit" class="admin-btn small danger">取消</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" class="admin-add-form">
                    <input type="hidden" name="action" value="add_student">
                    <input type="number" name="student_no" class="admin-input" placeholder="学号" min="1" required>
                    <input type="text" name="name" class="admin-input" placeholder="姓名" required>
                    <button type="submit" class="admin-btn solid">添加学生</button>
                </form>

                <table class="ranking-table admin-student-table">
                    <thead>
                        <tr>
                            <th width="14%">学号</th>
                            <th width="26%">姓名</th>
                            <th width="44%">修改</th>
                            <th width="16%">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <form method="POST" class="admin-inline-form">
                                <input type="hidden" name="action" value="update_student">
                                <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                <td><input type="number" name="student_no" class="admin-input tiny" value="<?php echo $s['student_no']; ?>"></td>
                                <td><input type="text" name="name" class="admin-input" value="<?php echo htmlspecialchars($s['name']); ?>"></td>
                                <td><button type="submit" class="admin-btn small">保存修改</button></td>
                            </form>
                            <td>
                                <form method="POST" class="admin-inline-form"
                                      onsubmit="event.preventDefault(); confirmAndSubmit(this, '删除学生', '确定删除「<?php echo htmlspecialchars($s['name']); ?>」吗？其全部记录也会一并删除。');">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="admin-btn small danger">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:26px;color:var(--ink-faint);">本班暂无学生，请在上方添加</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'passwords'): ?>
                <!-- ========== 本班密码管理 ========== -->
                <h2 class="stats-title">本班密码管理</h2>
                <div class="stats-note">
                    <strong>班级密码：</strong><span>学生 / 班干部登录记录页使用</span><br>
                    <strong>教师管理密码：</strong><span>老师登录本管理界面使用；两者分开，可分别修改</span>
                </div>
                <table class="ranking-table">
                    <tbody>
                        <tr>
                            <td width="20%"><strong>班级密码</strong><br><code class="pwd-show"><?php echo htmlspecialchars($class_info['password']); ?></code></td>
                            <td>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="action" value="change_class_password">
                                    <input type="text" name="new_password" class="admin-input" placeholder="新班级密码（至少4位）" required>
                                    <button type="submit" class="admin-btn small">保存</button>
                                </form>
                            </td>
                        </tr>
                        <tr>
                            <td width="20%"><strong>教师管理密码</strong><br><code class="pwd-show"><?php echo htmlspecialchars($class_info['teacher_password']); ?></code></td>
                            <td>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="action" value="change_teacher_password">
                                    <input type="text" name="new_password" class="admin-input" placeholder="新教师管理密码（至少4位）" required>
                                    <button type="submit" class="admin-btn small">保存</button>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                </table>

            <?php elseif ($tab === 'data'): ?>
                <!-- ========== 本班数据管理 ========== -->
                <h2 class="stats-title">数据管理</h2>
                <div class="stats-note">
                    <strong>清空数据：</strong>
                    <span>删除本班全部朗读记录、扣分与统计（保留班级和名单）；下方红色按钮可连学生名单一起清空。操作不可恢复。</span>
                </div>
                <form method="POST" class="admin-inline-form"
                      onsubmit="event.preventDefault(); confirmAndSubmit(this, '清空数据', '确定清空「<?php echo getClassName($teacher_class_number, getTeacherGrade()); ?>」的全部记录数据吗？此操作不可恢复。');">
                    <input type="hidden" name="action" value="clear_data">
                    <button type="submit" class="admin-btn small danger">清空本班全部数据</button>
                </form>
                <form method="POST" class="admin-inline-form"
                      onsubmit="event.preventDefault(); confirmAndSubmit(this, '清空全部数据（含名单）', '确定清空「<?php echo getClassName($teacher_class_number, getTeacherGrade()); ?>」的全部数据吗？

此操作将删除所有学生名单和记录，不可恢复！');">
                    <input type="hidden" name="action" value="clear_all_data">
                    <button type="submit" class="admin-btn small danger" style="background:#8b0000;">清空本班全部数据（含名单）</button>
                </form>
            <?php elseif ($tab === 'api'): ?>
                <!-- ========== API 接口文档（本班） ========== -->
                <?php
                $api_username = getTeacherGrade() . '-' . $teacher_class_number;
                $api_stmt = getDB()->prepare("SELECT teacher_password FROM classes WHERE id = ?");
                $api_stmt->execute([$teacher_class_id]);
                $api_pass = (string)$api_stmt->fetchColumn();
                $api_slot_key = date('YmdH');
                $api_seed = hash('sha256', API_SEED . ':' . $api_slot_key . ':teacher'); // 教师身份种子
                $api_token = hash('sha256', $api_username . ':' . $api_pass . ':' . $api_seed);
                $api_base = 'http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'zztool.free.nf') . '/morning-reading/api.php';
                ?>
                <h2 class="stats-title">API 接口文档</h2>
                <div class="stats-note">
                    <strong>认证方式（三步）：</strong>
                    <span>① 调 <code>action=get_seed</code> 获取种子（无需登录，需带身份参数）→ ② 客户端用「用户名 + 密码 + 种子」本地算出 token → ③ 请求头带 <code>Authorization: Bearer &lt;token&gt;</code>（或 <code>?token=</code>）。密码和种子永不通过网络传输；token 每小时随种子轮换自动失效。</span>
                </div>

                <div class="import-box">
                    <div class="import-title">我的 API Token（教师身份 · 当前时段）</div>
                    <div class="import-sub">用户名：<code><?php echo $api_username; ?></code>（年级-班号） · 本页展示的是「教师管理」身份 token · 种子时槽：<code><?php echo $api_slot_key; ?></code>（北京时间，每小时变化）</div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0;">
                        <code id="apiTokenBox" style="font-size:.9rem;background:#efe8d8;padding:8px 12px;border-radius:6px;word-break:break-all;flex:1;min-width:200px;"><?php echo $api_token; ?></code>
                        <button type="button" class="admin-btn solid" id="apiTokenCopyBtn">复制 Token</button>
                        <button type="button" class="admin-btn" id="apiTokenRefreshBtn">刷新显示</button>
                    </div>
                    <div class="import-sub"><strong>三种身份</strong>（用户名/密码不同，种子也不同 → token 互不相同，权限从低到高）：</div>
                    <table class="ranking-table" style="margin:10px 0 14px;">
                        <thead><tr><th width="14%">身份</th><th width="20%">用户名</th><th width="26%">密码</th><th width="40%">可用操作</th></tr></thead>
                        <tbody>
                            <tr><td>record 班级记录</td><td><code>9-6</code></td><td>班级登录密码</td><td>查名单/统计、加分、取消、扣分</td></tr>
                            <tr><td>teacher 教师管理</td><td><code>9-6</code></td><td>教师管理密码</td><td>全部端点（本班，与本页一致）</td></tr>
                            <tr><td>superadmin 总管理</td><td><code>superadmin</code></td><td>总管理密码</td><td>全部端点 + 任意班级（需 <code>grade_class=9-6</code>）</td></tr>
                        </tbody>
                    </table>
                    <div class="import-sub">Token 生成（客户端本地算）：<br>
                        <code>种子 = get_seed 接口返回</code>（同一时刻三种身份的种子互不相同；有效约 1~3 小时）<br>
                        <code>token = sha256(用户名 . ':' . 密码 . ':' . 种子)</code><br>
                        跨整点窗口（±1 小时）请求自动通过；密码永不出现在请求中。
                    </div>
                </div>

                <h3 class="stats-title" style="font-size:1.05rem;">端点一览（最低身份）</h3>
                <table class="ranking-table" style="margin-bottom:18px;">
                    <thead>
                        <tr>
                            <th width="12%">方法</th>
                            <th width="34%">端点</th>
                            <th width="18%">最低身份</th>
                            <th width="36%">说明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>GET</td><td><code>action=get_seed&amp;identity=record/teacher/superadmin</code></td><td>无需登录</td><td>获取当前小时种子（identity 三种任选）</td></tr>
                        <tr><td>GET</td><td><code>action=verify_token</code></td><td>record</td><td>验证 token 是否有效，返回 valid/身份/剩余有效期（无效返回 200 + valid=false）</td></tr>
                        <tr><td>GET</td><td><code>action=status</code></td><td>record</td><td>班级信息、当前时段、是否可记录、token 时槽、身份</td></tr>
                        <tr><td>GET</td><td><code>action=students</code></td><td>record</td><td>学生名单（学号/姓名/今日早读晚读/周得分/已加分）</td></tr>
                        <tr><td>GET</td><td><code>action=stats&amp;period=week</code></td><td>record</td><td>统计排行；period 可选 day/week/month/semester/total</td></tr>
                        <tr><td>POST</td><td><code>action=add_record&amp;student_no=1</code></td><td>record</td><td>加分（一次朗读最多加一分；自动抵消本周负分）</td></tr>
                        <tr><td>POST</td><td><code>action=cancel_record&amp;student_no=1</code></td><td>record</td><td>取消本次朗读记录</td></tr>
                        <tr><td>POST</td><td><code>action=penalize&amp;student_no=1</code></td><td>record</td><td>扣分（不限次数；优先抵消本周正分）</td></tr>
                        <tr><td>POST</td><td><code>action=add_student&amp;student_no=1</code></td><td>teacher</td><td>添加学生；<code>name</code> 建议用 <code>-d</code> 表单传（中文）</td></tr>
                        <tr><td>POST</td><td><code>action=update_student&amp;student_no=1</code></td><td>teacher</td><td>修改学生姓名；可加 <code>new_no=</code> 改学号</td></tr>
                        <tr><td>POST</td><td><code>action=delete_student&amp;student_no=1</code></td><td>teacher</td><td>删除学生（同时删除其全部记录）</td></tr>
                        <tr><td>POST</td><td><code>action=import_students</code></td><td>teacher</td><td>批量导入（JSON 或文本行，冲突自动覆盖）</td></tr>
                        <tr><td>POST</td><td><code>action=clear_data</code></td><td>teacher</td><td>清空本班记录（保留名单）</td></tr>
                        <tr><td>POST</td><td><code>action=clear_all_data</code></td><td>teacher</td><td>清空本班全部数据（含名单）</td></tr>
                    </tbody>
                </table>

                <h3 class="stats-title" style="font-size:1.05rem;">调用示例（bash：先取种子，再算 token）</h3>
                <pre class="api-pre"># ① 获取教师身份种子（无需登录；identity 可换 record/superadmin）
SEED=$(curl -s "<?php echo $api_base; ?>?action=get_seed&identity=teacher" | \
  python3 -c "import json,sys;print(json.load(sys.stdin)['data']['seed'])")

# ② 用「用户名:密码:种子」算 token（用户名=<?php echo $api_username; ?>，密码=教师管理密码，请替换为真实密码）
TOKEN=$(printf '%s' "<?php echo $api_username; ?>:教师管理密码:$SEED" | sha256sum | cut -d' ' -f1)

# ③ 查看学生名单
curl "<?php echo $api_base; ?>?username=<?php echo $api_username; ?>&action=students" \
  -H "Authorization: Bearer $TOKEN"

# ④ 给学号 3 加分（自动判断早读/晚读时段）
curl -X POST "<?php echo $api_base; ?>?username=<?php echo $api_username; ?>&action=add_record&student_no=3" \
  -H "Authorization: Bearer $TOKEN"

# ⑤ 添加学生（中文参数建议用 -d 表单传，避免 URL 编码问题）
curl -X POST "<?php echo $api_base; ?>?username=<?php echo $api_username; ?>&action=add_student" \
  -H "Authorization: Bearer $TOKEN" -d "student_no=6" -d "name=王小明"

# ⑥ 批量导入（JSON，学号已存在自动覆盖姓名）
curl -X POST "<?php echo $api_base; ?>?username=<?php echo $api_username; ?>&action=import_students" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"students":[{"no":1,"name":"张三"},{"no":2,"name":"李四"}]}'

# ⑦ 总管理身份操作任意班级（如 8-3）：换种子身份即可
SEED=$(curl -s "<?php echo $api_base; ?>?action=get_seed&identity=superadmin" | \
  python3 -c "import json,sys;print(json.load(sys.stdin)['data']['seed'])")
TOKEN=$(printf '%s' "superadmin:总管理密码:$SEED" | sha256sum | cut -d' ' -f1)
curl "<?php echo $api_base; ?>?username=superadmin&action=students&grade_class=8-3" \
  -H "Authorization: Bearer $TOKEN"</pre>

                <div class="stats-note">
                    <strong>安全提示：</strong>
                    <span>① <code>get_seed</code> 无需登录即可取种子，但没有密码就算不出 token；② 密码永不进入请求，中间人最多拿到当小时有效的 token；③ 修改 config.php 中的 <code>API_SEED</code> 可使全校所有 token 立即失效；④ 总管理身份可操作任意班级，token 请勿泄露或提交到公开仓库；⑤ 本页展示的是教师身份 token，用班级登录身份请将 get_seed 的 identity 换成 record。</span>
                </div>
                <script>
                    // 复制当前 Token
                    document.getElementById('apiTokenCopyBtn').addEventListener('click', function () {
                        var t = document.getElementById('apiTokenBox').textContent.trim();
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(t).then(function () {
                                apiCopyTip();
                            });
                        } else {
                            // 老浏览器降级：选中文本
                            var range = document.createRange();
                            range.selectNode(document.getElementById('apiTokenBox'));
                            window.getSelection().removeAllRanges();
                            window.getSelection().addRange(range);
                        }
                    });
                    function apiCopyTip() {
                        var tip = document.getElementById('apiCopyTip');
                        if (!tip) {
                            tip = document.createElement('div');
                            tip.id = 'apiCopyTip';
                            tip.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#2F6B4F;color:#fff;padding:10px 18px;border-radius:8px;font-size:.9rem;z-index:99;box-shadow:0 4px 12px rgba(0,0,0,.25);';
                            document.body.appendChild(tip);
                        }
                        tip.textContent = 'Token 已复制到剪贴板（当前时段有效，跨小时后自动变化）';
                        clearTimeout(tip._t);
                        tip._t = setTimeout(function () { tip.remove(); }, 2500);
                    }
                    document.getElementById('apiTokenRefreshBtn').addEventListener('click', function () {
                        location.reload();
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- 自绘确认弹窗（必须在脚本之前，脚本要绑定其按钮事件） -->
    <div class="modal-overlay" id="adminModalOverlay" hidden>
        <div class="modal-card">
            <div class="modal-stamp" id="adminModalStamp">!</div>
            <div class="modal-title" id="adminModalTitle"></div>
            <div class="modal-desc" id="adminModalDesc"></div>
            <div class="modal-actions">
                <button class="modal-btn ghost" id="adminModalCancel">取消</button>
                <button class="modal-btn solid" id="adminModalOk">确定</button>
            </div>
        </div>
    </div>

    <script>
        // 自绘确认弹窗
        let confirmOkCallback = null;

        function askConfirm(title, desc) {
            return new Promise(resolve => {
                document.getElementById('adminModalStamp').textContent = '!';
                document.getElementById('adminModalTitle').textContent = title;
                document.getElementById('adminModalDesc').textContent = desc;
                document.getElementById('adminModalOk').textContent = '确定';
                document.getElementById('adminModalCancel').hidden = false;
                confirmOkCallback = () => { hideAsk(); resolve(true); };
                document.getElementById('adminModalOverlay').hidden = false;
            });
        }

        function hideAsk() {
            document.getElementById('adminModalOverlay').hidden = true;
            confirmOkCallback = null;
        }

        const okBtn = document.getElementById('adminModalOk');
        const cancelBtn = document.getElementById('adminModalCancel');
        const overlay = document.getElementById('adminModalOverlay');
        if (okBtn) okBtn.addEventListener('click', () => confirmOkCallback && confirmOkCallback());
        if (cancelBtn) cancelBtn.addEventListener('click', () => { hideAsk(); });
        if (overlay) overlay.addEventListener('click', e => {
            if (e.target === e.currentTarget) hideAsk();
        });

        async function confirmAndSubmit(form, title, desc) {
            const ok = await askConfirm(title, desc);
            if (ok) form.submit();
        }

        // 登录倒计时：performance.now() 单调时钟，不受系统时间修改影响，到期立即跳转
        var adminRemainingMs = <?php echo $admin_remaining * 1000; ?>;
        var adminPageLoadTime = performance.now();
        setInterval(function() {
            if (performance.now() - adminPageLoadTime >= adminRemainingMs) {
                window.location.href = 'admin.php';
            }
        }, 1000);

        // 心跳兜底：每30分钟检查一次（7天过期，提前过期概率低）
        setInterval(function() {
            fetch('heartbeat.php?type=admin', {cache: 'no-store'})
                .then(function(r) { return r.json(); })
                .then(function(d) { if (d.expired) window.location.href = d.redirect; })
                .catch(function() {});
        }, 1800000);
    </script>
    <footer class="site-footer">
        <div class="footer-title">班级朗读记录系统 v<?php echo APP_VERSION; ?></div>
        <div>
            作者：<a href="http://zztool.free.nf" target="_blank" rel="noopener" class="author-link"><?php echo APP_AUTHOR; ?></a><span class="author-hint">（点我看作者主页）</span>
            <span class="footer-sep">·</span>
            技术支持：豆包（Doubao）
            <span class="footer-sep">·</span>
            GPL-3.0 许可
        </div>
        <div>
            <a href="<?php echo APP_REPO; ?>" target="_blank" rel="noopener">GitHub 仓库</a>
            <span class="footer-sep">·</span>
            问题反馈：<a href="<?php echo APP_REPO; ?>/issues" target="_blank" rel="noopener">GitHub Issues</a>
        </div>
    </footer>
</body>
</html>
