<?php
require_once 'functions.php';
initDatabase();

// ================= 总管理登录（仅密码，无入口链接，纯背网址访问） =================
if (isset($_POST['super_login'])) {
    if (superLogin($_POST['password'])) {
        header('Location: edit.php');
        exit;
    } else {
        $error = "总管理密码错误";
    }
}

if (!isset($_SESSION['super_logged_in']) || $_SESSION['super_logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>总管理 - 班级朗读记录系统</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="header-title">
                    <h1>总管理</h1>
                    <span class="session-seal">总管理</span>
                </div>
            </div>
            <div class="login-form">
                <h2>请输入总管理密码</h2>
                <?php if (isset($error)): ?>
                    <div class="message error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="总管理密码" required>
                    <button type="submit" name="super_login">进入总管理</button>
                </form>
                <p class="login-hint">总管理密码在 config.php 的 SUPERADMIN_PASSWORD 中设置</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ================= 处理总管理操作（全部班级） =================
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'classes';
$sel_class = isset($_GET['class']) ? (int)$_GET['class'] : 1;

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirect = 'edit.php?tab=' . $tab . '&class=' . $sel_class;

    if ($action === 'change_class_password' && isset($_POST['class_id'], $_POST['new_password'])) {
        $r = updateClassPassword((int)$_POST['class_id'], $_POST['new_password']);
    } elseif ($action === 'change_teacher_password' && isset($_POST['class_id'], $_POST['new_password'])) {
        $r = updateTeacherPassword((int)$_POST['class_id'], $_POST['new_password']);
    } elseif ($action === 'add_student' && isset($_POST['class_id'], $_POST['student_no'], $_POST['name'])) {
        $r = addStudent((int)$_POST['class_id'], $_POST['student_no'], $_POST['name']);
    } elseif ($action === 'update_student' && isset($_POST['student_id'], $_POST['name'])) {
        $r = updateStudent((int)$_POST['student_id'], $_POST['name'], isset($_POST['student_no']) ? $_POST['student_no'] : null);
    } elseif ($action === 'delete_student' && isset($_POST['student_id'])) {
        $r = deleteStudent((int)$_POST['student_id']);
    } elseif ($action === 'clear_data' && isset($_POST['class_id'])) {
        $r = clearClassData((int)$_POST['class_id']);
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
                $_SESSION['import_preview'][$sel_class] = ['rows' => $raw, 'raw_count' => count($raw)];
                $r = ['success' => true, 'message' => '已解析 ' . count($rows) . ' 名学生，请在下方确认'];
                $redirect = 'edit.php?tab=students&class=' . $sel_class . '&preview=1';
            }
        }
    } elseif ($action === 'confirm_import') {
        // 第二步：确认导入
        if (!empty($_SESSION['import_preview'][$sel_class])) {
            $r = importStudents($sel_class, $_SESSION['import_preview'][$sel_class]['rows']);
            unset($_SESSION['import_preview'][$sel_class]);
        } else {
            $r = ['success' => false, 'message' => '没有待确认的导入数据'];
        }
    } elseif ($action === 'cancel_import') {
        unset($_SESSION['import_preview'][$sel_class]);
        $r = ['success' => true, 'message' => '已取消导入'];
    } elseif ($action === 'super_logout') {
        unset($_SESSION['super_logged_in']);
        header('Location: edit.php');
        exit;
    } else {
        $r = ['success' => false, 'message' => '无效操作'];
    }

    $_SESSION['edit_msg'] = $r['message'];
    $_SESSION['edit_msg_type'] = $r['success'] ? 'success' : 'error';
    header('Location: ' . $redirect);
    exit;
}

// 下载导入模板（Excel .xlsx）
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    outputStudentTemplate();
}

$message = '';
$message_type = '';
if (isset($_SESSION['edit_msg'])) {
    $message = $_SESSION['edit_msg'];
    $message_type = $_SESSION['edit_msg_type'];
    unset($_SESSION['edit_msg']);
    unset($_SESSION['edit_msg_type']);
}

$classes = getAllClasses();
// 待确认的导入预览（会话中）
$import_preview = isset($_SESSION['import_preview'][$sel_class]) ? $_SESSION['import_preview'][$sel_class] : null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>总管理 - 班级朗读记录系统</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">
                <h1>总管理</h1>
                <span class="session-seal">总管理</span>
            </div>
            <div class="time-info"><?php echo date('Y年m月d日 H:i'); ?></div>
        </div>

        <div class="nav-bar">
            <a href="edit.php?tab=classes" class="nav-btn <?php echo $tab === 'classes' ? 'active' : ''; ?>">班级与密码</a>
            <a href="edit.php?tab=students&class=<?php echo $sel_class; ?>" class="nav-btn <?php echo $tab === 'students' ? 'active' : ''; ?>">学生名单</a>
            <a href="edit.php?tab=data" class="nav-btn <?php echo $tab === 'data' ? 'active' : ''; ?>">数据管理</a>
            <form method="POST" style="margin:0;padding:0;display:inline;">
                <input type="hidden" name="action" value="super_logout">
                <button type="submit" class="nav-btn nav-logout">退出总管理</button>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="admin-body">
            <?php if ($tab === 'classes'): ?>
                <!-- ========== 全部班级与密码 ========== -->
                <h2 class="stats-title">全部班级与密码</h2>
                <div class="stats-note">
                    <strong>说明：</strong>
                    <span>班级密码 = 学生登录记录页用；教师管理密码 = 老师登录 admin.php 用。两者分开，可分别修改。</span>
                </div>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th width="10%">班级</th>
                            <th width="11%">学生数</th>
                            <th width="11%">记录数</th>
                            <th width="10%">扣分数</th>
                            <th width="14%">班级密码</th>
                            <th width="16%">修改班级密码</th>
                            <th width="14%">教师管理密码</th>
                            <th width="14%">修改教师管理密码</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                        <tr>
                            <td><?php echo getClassName($c['class_number']); ?></td>
                            <td><?php echo $c['student_count']; ?></td>
                            <td><?php echo $c['record_count']; ?></td>
                            <td><?php echo $c['penalty_count']; ?></td>
                            <td><code class="pwd-show"><?php echo htmlspecialchars($c['password']); ?></code></td>
                            <td>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="action" value="change_class_password">
                                    <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                    <input type="text" name="new_password" class="admin-input tiny" placeholder="新密码" required>
                                    <button type="submit" class="admin-btn small">保存</button>
                                </form>
                            </td>
                            <td><code class="pwd-show"><?php echo htmlspecialchars($c['teacher_password']); ?></code></td>
                            <td>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="action" value="change_teacher_password">
                                    <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                    <input type="text" name="new_password" class="admin-input tiny" placeholder="新密码" required>
                                    <button type="submit" class="admin-btn small">保存</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'students'): ?>
                <!-- ========== 任意班级学生名单 ========== -->
                <h2 class="stats-title">学生名单管理</h2>
                <div class="period-selector">
                    <?php foreach ($classes as $c): ?>
                        <a href="edit.php?tab=students&class=<?php echo $c['id']; ?>"
                           class="period-btn <?php echo $sel_class == $c['id'] ? 'active' : ''; ?>"><?php echo getClassName($c['class_number']); ?></a>
                    <?php endforeach; ?>
                </div>

                <?php
                $sel_students = getStudents($sel_class);
                ?>
                <div class="stats-note">
                    <strong>共 <?php echo count($sel_students); ?> 名学生（姓名自动转码存储）</strong>
                </div>

                <!-- 批量导入（Excel） -->
                <div class="import-box">
                    <div class="import-title">批量导入（Excel）</div>
                    <div class="import-sub">下载模板或用自己班的全班 Excel 名单：<strong>第一列学号，第二列姓名</strong>，每行一名学生，可含表头行；填好后直接上传 <strong>.xlsx</strong> 文件即可</div>
                    <form method="POST" enctype="multipart/form-data" class="admin-inline-form" style="flex-wrap:wrap;">
                        <input type="hidden" name="action" value="preview_import">
                        <input type="file" name="csv_file" accept=".xlsx,.csv" class="admin-input" required>
                        <button type="submit" class="admin-btn solid">解析并预览</button>
                        <a href="edit.php?action=download_template" class="admin-btn">下载 Excel 模板</a>
                    </form>

                    <?php if ($import_preview): ?>
                    <?php $preview_rows = normalizeImportRows($import_preview['rows'])[0]; ?>
                    <div class="import-preview">
                        <div class="stats-note" style="margin-top:12px;">
                            <strong>预览：</strong>共解析 <?php echo count($preview_rows); ?> 名学生（学号已存在的会被跳过），确认后写入：
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
                <!-- ========== 任意班级数据清空 ========== -->
                <h2 class="stats-title">数据管理</h2>
                <div class="stats-note">
                    <strong>清空数据：</strong>
                    <span>删除该班全部朗读记录、扣分与统计（保留班级和名单），操作不可恢复。</span>
                </div>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th width="14%">班级</th>
                            <th width="14%">学生数</th>
                            <th width="14%">记录数</th>
                            <th width="14%">扣分数</th>
                            <th width="44%">操作</th>
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

    <!-- 自绘确认弹窗（必须在脚本之前，脚本要绑定其按钮事件） -->
    <div class="modal-overlay" id="editModalOverlay" hidden>
        <div class="modal-card">
            <div class="modal-stamp" id="editModalStamp">!</div>
            <div class="modal-title" id="editModalTitle"></div>
            <div class="modal-desc" id="editModalDesc"></div>
            <div class="modal-actions">
                <button class="modal-btn ghost" id="editModalCancel">取消</button>
                <button class="modal-btn solid" id="editModalOk">确定</button>
            </div>
        </div>
    </div>

    <script>
        // 自绘确认弹窗
        let confirmOkCallback = null;

        function askConfirm(title, desc) {
            return new Promise(resolve => {
                document.getElementById('editModalStamp').textContent = '!';
                document.getElementById('editModalTitle').textContent = title;
                document.getElementById('editModalDesc').textContent = desc;
                document.getElementById('editModalOk').textContent = '确定';
                document.getElementById('editModalCancel').hidden = false;
                confirmOkCallback = () => { hideAsk(); resolve(true); };
                document.getElementById('editModalOverlay').hidden = false;
            });
        }

        function hideAsk() {
            document.getElementById('editModalOverlay').hidden = true;
            confirmOkCallback = null;
        }

        const okBtn = document.getElementById('editModalOk');
        const cancelBtn = document.getElementById('editModalCancel');
        const overlay = document.getElementById('editModalOverlay');
        if (okBtn) okBtn.addEventListener('click', () => confirmOkCallback && confirmOkCallback());
        if (cancelBtn) cancelBtn.addEventListener('click', () => { hideAsk(); });
        if (overlay) overlay.addEventListener('click', e => {
            if (e.target === e.currentTarget) hideAsk();
        });

        async function confirmAndSubmit(form, title, desc) {
            const ok = await askConfirm(title, desc);
            if (ok) form.submit();
        }
    </script>
</body>
</html>
