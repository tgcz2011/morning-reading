<?php
require_once 'functions.php';

// 处理登录
if (isset($_POST['login'])) {
    if (login($_POST['password'])) {
        header('Location: index.php');
        exit;
    } else {
        $error = "密码错误";
    }
}

// 检查登录状态
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>班级朗读记录系统 - 登录</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="header-title">
                    <h1>班级朗读记录系统</h1>
                    <span class="session-seal">登录</span>
                </div>
            </div>
            <div class="login-form">
                <h2>请输入密码登录</h2>
                <?php if (isset($error)): ?>
                    <div class="message error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="输入密码" required>
                    <button type="submit" name="login">登录</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 处理AJAX请求
if (isset($_POST['ajax_action'])) {
    checkAuth();
    
    $student_id = (int)$_POST['student_id'];
    $result = null;
    
    switch ($_POST['ajax_action']) {
        case 'add_record':
            $result = addRecord($student_id);
            break;
        case 'cancel_record':
            $result = cancelRecord($student_id);
            break;
        case 'penalize':
            $result = penalizeStudent($student_id);
            break;
    }
    
    if ($result) {
        // 获取更新后的学生状态
        $status = getStudentStatus($student_id);
        $result['status'] = $status;
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

checkAuth();

// 显示消息
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>班级朗读记录系统</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">
                <h1>班级朗读记录系统</h1>
                <span class="session-seal"><?php echo (getCurrentRecordType() === 'morning' ? '早读' : '晚读'); ?></span>
            </div>
            <div class="time-info">
                <?php echo date('Y年m月d日 H:i'); ?> · 第<?php echo getWeekNumber(); ?>周
            </div>
        </div>
        
        <div class="nav-bar">
            <a href="index.php" class="nav-btn active">记录页面</a>
            <a href="stats.php" class="nav-btn">统计页面</a>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!canRecord()): ?>
            <div class="time-restriction">
                <strong>注意：</strong>当前不在记录时间段内（早读：6:15-7:00，晚读：17:35-18:30）
            </div>
        <?php endif; ?>
        
        <div class="students-grid" id="studentsGrid">
            <?php
            global $students;
            foreach ($students as $id => $name) {
                $status = getStudentStatus($id);
                $card_class = 'student-card';
                
                // 只要有负分或者当前时间段有扣分，卡片呈"待补"红色印章状态
                if ($status['weekly_score'] < 0 || $status['has_penalty_in_session']) {
                    $card_class .= ' penalized';
                } else {
                    // 没有负分且当前时间段没有扣分，正常显示
                    if (getCurrentRecordType() === 'morning' && $status['today_morning']) {
                        $card_class .= ' recorded';
                    } elseif (getCurrentRecordType() === 'evening' && $status['today_evening']) {
                        $card_class .= ' recorded';
                    }
                }
                
                $penalty_display = '';
                if ($status['weekly_score'] < 0) {
                    $penalty_display = '<div class="penalty-display">' . $status['weekly_score'] . '</div>';
                }
                
                // 检查是否在可记录时间内
                $can_record_now = canRecord();
                $add_disabled = !$can_record_now ? 'disabled' : '';
                $subtract_disabled = !$can_record_now ? 'disabled' : '';
                
                echo "
                <div class='{$card_class}' data-student-id='{$id}'>
                    <div class='student-info'>
                        <div class='student-id'>{$id}</div>
                        <div class='student-name'>{$name}</div>
                    </div>
                    <div class='action-buttons'>
                        <button class='action-btn add-btn' onclick='handleAdd({$id})' {$add_disabled}>+</button>
                        <button class='action-btn subtract-btn' onclick='handleSubtract({$id})' {$subtract_disabled}>-</button>
                    </div>
                    {$penalty_display}
                </div>";
            }
            ?>
        </div>
        
        <div class="instructions">
            <p class="strong">操作说明</p>
            <p>点击 + 加分 · 点击 − 扣分</p>
            <p>绿色「优秀」= 今日该时段已记录 · 红色「差」= 有扣分待补齐</p>
            <p>一次朗读时间最多加一分，扣分不限</p>
            <?php if (!canRecord()): ?>
            <p class="strong">当前不在记录时间段内，无法进行操作</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- 自绘弹窗（不用浏览器原生弹窗，方便老师从远处看到） -->
    <div class="modal-overlay" id="modalOverlay" hidden>
        <div class="modal-card">
            <div class="modal-stamp" id="modalStamp">!</div>
            <div class="modal-title" id="modalTitle"></div>
            <div class="modal-desc" id="modalDesc"></div>
            <div class="modal-actions">
                <button class="modal-btn ghost" id="modalCancel" hidden>取消</button>
                <button class="modal-btn solid" id="modalOk">知道了</button>
            </div>
        </div>
    </div>

    <script>
        // ================= Toast 底部轻提示 =================
        let toastTimer = null;
        function showToast(message, type) {
            let toast = document.getElementById('toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'toast';
                document.body.appendChild(toast);
            }
            toast.className = 'toast ' + type;
            toast.textContent = message;
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
        }
        
        // ================= 自绘弹窗（不用浏览器原生 alert/confirm） =================
        let modalOkCallback = null;
        let modalCancelCallback = null;
        
        function showModal({ stamp = '!', title = '', desc = '', okText = '知道了', cancelText = null }) {
            return new Promise(resolve => {
                document.getElementById('modalStamp').textContent = stamp;
                document.getElementById('modalTitle').textContent = title;
                document.getElementById('modalDesc').textContent = desc;
                const okBtn = document.getElementById('modalOk');
                const cancelBtn = document.getElementById('modalCancel');
                okBtn.textContent = okText;
                cancelBtn.hidden = !cancelText;
                cancelBtn.textContent = cancelText || '';
                modalOkCallback = () => { closeModal(); resolve(true); };
                modalCancelCallback = cancelText ? () => { closeModal(); resolve(false); } : null;
                document.getElementById('modalOverlay').hidden = false;
            });
        }
        
        function closeModal() {
            document.getElementById('modalOverlay').hidden = true;
            modalOkCallback = null;
            modalCancelCallback = null;
        }
        
        document.getElementById('modalOk').addEventListener('click', () => modalOkCallback && modalOkCallback());
        document.getElementById('modalCancel').addEventListener('click', () => modalCancelCallback && modalCancelCallback());
        document.getElementById('modalOverlay').addEventListener('click', e => {
            // 只有带取消按钮的确认弹窗才允许点空白关闭，提示弹窗必须点"知道了"
            if (e.target === e.currentTarget && modalCancelCallback) modalCancelCallback();
        });
        
        // ================= 点击即时反馈（不等服务器回传） =================
        const pendingCards = new Set();
        
        function beginAction(studentId, kind) {
            const card = document.querySelector(`.student-card[data-student-id="${studentId}"]`);
            if (!card) return;
            card.classList.remove('tapped-add', 'tapped-sub');
            void card.offsetWidth; // 强制重排以重启动画
            card.classList.add(kind === 'add' ? 'tapped-add' : 'tapped-sub');
            card.classList.add('pending');
        }
        
        function endAction(studentId) {
            const card = document.querySelector(`.student-card[data-student-id="${studentId}"]`);
            if (!card) return;
            card.classList.remove('tapped-add', 'tapped-sub', 'pending');
        }
        
        // ================= 处理加分 =================
        function handleAdd(studentId) {
            if (pendingCards.has(studentId)) return;
            pendingCards.add(studentId);
            
            // 点击立即有反应：卡片波纹 + 底部提示
            beginAction(studentId, 'add');
            showToast('正在加分…', 'info');
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=add_record&student_id=${studentId}`
            })
            .then(response => response.json())
            .then(data => {
                endAction(studentId);
                if (data.status) updateStudentCard(studentId, data.status);
                
                if (data.success) {
                    showToast(data.message, 'success');
                } else if (data.already_added) {
                    // 已经记录过 / 本次已加过分：弹大窗提示
                    showModal({
                        stamp: '!',
                        title: data.message,
                        desc: '每名同学一次朗读时间最多加一分',
                        okText: '知道了'
                    });
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                endAction(studentId);
                console.error('Error:', error);
                showToast('网络异常，操作未生效，请重试', 'error');
            })
            .finally(() => pendingCards.delete(studentId));
        }
        
        // ================= 处理扣分 =================
        async function handleSubtract(studentId) {
            if (pendingCards.has(studentId)) return;
            
            // 自绘确认弹窗（不用浏览器 confirm）
            const confirmed = await showModal({
                stamp: '−',
                title: '确定要扣分吗？',
                desc: '扣分没有上限，本次扣 1 分',
                okText: '确定扣分',
                cancelText: '取消'
            });
            if (!confirmed) return;
            
            pendingCards.add(studentId);
            beginAction(studentId, 'sub');
            showToast('正在扣分…', 'info');
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=penalize&student_id=${studentId}`
            })
            .then(response => response.json())
            .then(data => {
                endAction(studentId);
                if (data.status) updateStudentCard(studentId, data.status);
                
                if (data.success) {
                    showToast('扣分成功', 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                endAction(studentId);
                console.error('Error:', error);
                showToast('网络异常，操作未生效，请重试', 'error');
            })
            .finally(() => pendingCards.delete(studentId));
        }
        
        // ================= 更新学生卡片 =================
        function updateStudentCard(studentId, status) {
            const card = document.querySelector(`.student-card[data-student-id="${studentId}"]`);
            if (!card) return;
            
            // 更新卡片状态
            card.classList.remove('recorded', 'penalized');
            
            // 只要有负分或者当前时间段有扣分，卡片呈"差"红色印章状态
            if (status.weekly_score < 0 || status.has_penalty_in_session) {
                card.classList.add('penalized');
            } else {
                // 没有负分且当前时间段没有扣分，正常显示
                const currentType = '<?php echo getCurrentRecordType(); ?>';
                if ((currentType === 'morning' && status.today_morning) || 
                    (currentType === 'evening' && status.today_evening)) {
                    card.classList.add('recorded');
                }
            }
            
            // 更新负分显示（按钮下方）
            let penaltyDisplay = card.querySelector('.penalty-display');
            if (status.weekly_score < 0) {
                if (!penaltyDisplay) {
                    penaltyDisplay = document.createElement('div');
                    penaltyDisplay.className = 'penalty-display';
                    card.appendChild(penaltyDisplay);
                }
                penaltyDisplay.textContent = status.weekly_score;
            } else if (penaltyDisplay) {
                penaltyDisplay.remove();
            }
        }
        
        // 防止右键菜单
        document.addEventListener('contextmenu', function(e) {
            if (e.target.classList.contains('student-card')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>