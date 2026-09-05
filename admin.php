<?php
require_once 'functions.php';
initDatabase();

// ================= 教师登录 =================
if (isset($_POST['admin_login'])) {
    if (adminLogin($_POST['password'])) {
        header('Location: admin.php');
        exit;
    } else {
        $error = "管理密码错误";
    }
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>教师管理 - 班级朗读记录系统</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="header-title">
                    <h1>教师管理</h1>
                    <span class="session-seal">管理</span>
                </div>
            </div>
            <div class="login-form">
                <h2>请输入教师管理密码</h2>
                <?php if (isset($error)): ?>
                    <div class="message error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="管理密码" required>
                    <button type="submit" name="admin_login">进入管理</button>
                </form>
                <p class="login-hint">管理密码在 config.php 的 ADMIN_PASSWORD 中设置</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ================= 处理管理操作 =================
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'classes';
$sel_class = isset($_GET['class']) ? (int)$_GET['class'] : 1;

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirect = 'admin.php?tab=' . $tab . '&class=' . $sel_class;

    if ($action === 'change_password' && isset($_POST['class_id'], $_POST['new_password'])) {
        $r = updateClassPassword((int)$_POST['class_id'], $_POST['new_password']);
    } elseif ($action === 'add_student' && isset($_POST['class_id'], $_POST['student_no'], $_POST['name'])) {
        $r = addStudent((int)$_POST['class_id'], $_POST['student_no'], $_POST['name']);
    } elseif ($action === 'update_student' && isset($_POST['student_id'], $_POST['name'])) {
        $r = updateStudent((int)$_POST['student_id'], $_POST['name'], isset($_POST['student_no']) ? $_POST['student_no'] : null);
    } elseif ($action === 'delete_student' && isset($_POST['student_id'])) {
        $r = deleteStudent((int)$_POST['student_id']);
    } elseif ($action === 'clear_data' && isset($_POST['class_id'])) {
        $r = clearClassData((int)$_POST['class_id']);
    } elseif ($action === 'admin_logout') {
        unset($_SESSION['admin_logged_in']);
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

$classes = getAllClasses();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>教师管理 - 班级朗读记录系统</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">
                <h1>教师管理</h1>
                <span class="session-seal">管理</span>
            </div>
            <div class="time-info"><?php echo date('Y年m月d日 H:i'); ?></div>
        </div>

        <div class="nav-bar">
            <a href="index.php" class="nav-btn">记录页面</a>
            <a href="stats.php" class="nav-btn">统计页面</a>
            <a href="admin.php?tab=classes" class="nav-btn <?php echo $tab === 'classes' ? 'active' : ''; ?>">班级密码</a>
            <a href="admin.php?tab=students&class=<?php echo $sel_class; ?>" class="nav-btn <?php echo $tab === 'students' ? 'active' : ''; ?>">学生名单</a>
            <a href="admin.php?tab=data" class="nav-btn <?php echo $tab === 'data' ? 'active' : ''; ?>">班级数据</a>
            <form method="POST" style="margin:0;padding:0;display:inline;">
                <input type="hidden" name="action" value="admin_logout">
                <button type="submit" class="nav-btn nav-logout">退出管理</button>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="admin-body">
            <?php if ($tab === 'classes'): ?>
                <!-- ========== 班级密码管理 ========== -->
                <h2 class="stats-title">班级密码管理</h2>
                <div class="stats-note"><strong>初始密码：</strong><span>admin + 两位班级号（一班 = admin01）。修改后立即生效。</span></div>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th width="12%">班级</th>
                            <th width="12%">当前密码</th>
                            <th width="16%">学生数</th>
                            <th width="14%">记录数</th>
                            <th width="14%">扣分数</th>
                            <th width="32%">新密码</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                        <tr>
                            <td><?php echo getClassName($c['class_number']); ?></td>
                            <td><code class="pwd-show"><?php echo htmlspecialchars($c['password']); ?></code></td>
                            <td><?php echo $c['student_count']; ?></td>
                            <td><?php echo $c['record_count']; ?></td>
                            <td><?php echo $c['penalty_count']; ?></td>
                            <td>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="action" value="change_password">
                                    <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                    <input type="text" name="new_password" class="admin-input" placeholder="新密码（至少4位）" required>
                                    <button type="submit" class="admin-btn small">保存</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'students'): ?>
                <!-- ========== 学生名单管理 ========== -->
                <h2 class="stats-title">学生名单管理</h2>
                <div class="period-selector">
                    <?php foreach ($classes as $c): ?>
                        <a href="admin.php?tab=students&class=<?php echo $c['id']; ?>"
                           class="period-btn <?php echo $sel_class == $c['id'] ? 'active' : ''; ?>"><?php echo getClassName($c['class_number']); ?></a>
                    <?php endforeach; ?>
                </div>

                <?php
                $sel_students = getStudents($sel_class);
                $sel_class_info = null;
                foreach ($classes as $c) { if ($c['id'] == $sel_class) $sel_class_info = $c; }
                ?>
                <div class="stats-note">
                    <strong><?php echo getClassName($sel_class_info['class_number']); ?>：</strong>
                    <span>共 <?php echo count($sel_students); ?> 名学生（姓名自动转码存储，不受数据库中文编码限制）</span>
                </div>

                <form method="POST" class="admin-add-form">
                    <input type="hidden" name="action" value="add_student">
                    <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
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
                        <?php foreach ($sel_students as $s): ?>
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
                        <?php if (empty($sel_students)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:26px;color:var(--ink-faint);">本班暂无学生，请在上方添加</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'data'): ?>
                <!-- ========== 班级数据管理 ========== -->
                <h2 class="stats-title">班级数据管理</h2>
                <div class="stats-note">
                    <strong>清空数据：</strong>
                    <span>删除该班全部朗读记录、扣分与统计（保留班级和名单），操作不可恢复。</span>
                </div>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th width="16%">班级</th>
                            <th width="16%">学生数</th>
                            <th width="16%">记录数</th>
                            <th width="16%">扣分数</th>
                            <th width="36%">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                        <tr>
                            <td><?php echo getClassName($c['class_number']); ?></td>
                            <td><?php echo $c['student_count']; ?></td>
                            <td><?php echo $c['record_count']; ?></td>
                            <td><?php echo $c['penalty_count']; ?></td>
                            <td>
                                <form method="POST" class="admin-inline-form"
                                      onsubmit="event.preventDefault(); confirmAndSubmit(this, '清空数据', '确定清空「<?php echo getClassName($c['class_number']); ?>」的全部记录数据吗？此操作不可恢复。');">
                                    <input type="hidden" name="action" value="clear_data">
                                    <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="admin-btn small danger">清空该班全部数据</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // 删除学生 / 清空数据前的确认（自绘弹窗，不用浏览器 confirm）
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

        document.getElementById('adminModalOk').addEventListener('click', () => confirmOkCallback && confirmOkCallback());
        document.getElementById('adminModalCancel').addEventListener('click', () => { hideAsk(); });
        document.getElementById('adminModalOverlay').addEventListener('click', e => {
            if (e.target === e.currentTarget) hideAsk();
        });

        async function confirmAndSubmit(form, title, desc) {
            const ok = await askConfirm(title, desc);
            if (ok) form.submit();
        }
    </script>

    <!-- 自绘确认弹窗 -->
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
</body>
</html>
