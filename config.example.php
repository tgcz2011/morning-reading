<?php
// ============================================================
// 配置模板：部署时复制本文件为 config.php，并填写真实信息
//   cp config.example.php config.php
// config.php 含数据库与登录密码，已被 .gitignore 排除，禁止入库
// ============================================================

// 强制使用北京时间（服务器默认 UTC 会导致时段判断偏差 8 小时）
date_default_timezone_set('Asia/Shanghai');

// 数据库配置
define('DB_HOST', '请填写数据库主机');
define('DB_USER', '请填写数据库用户名');
define('DB_PASS', '请填写数据库密码');
define('DB_NAME', '请填写数据库名');
define('DB_PORT', 3306);

// 总管理密码（登录 edit.php 使用；edit.php 无任何入口链接，纯背网址访问）
define('SUPERADMIN_PASSWORD', '请填写总管理密码');

// 班级数量：自动创建 1~CLASS_COUNT 个班
// 初始密码 = admin + 两位班级号（一班=admin01，二班=admin02，以此类推）
define('CLASS_COUNT', 14);

// 学期开始日期：学期统计按此日期分割
// 统计时取「当前日期之前最近的一个日期」作为本学期起点
$semester_starts = [
    '2026-02-26',
    '2026-09-01',
    '2027-02-25',
    '2027-09-01',
];

// 默认学生名单（学号 => 姓名），仅当某班还没有任何学生时写入该班
// 例如：$default_students = [1 => '张三', 2 => '李四']; 空值 '' 表示跳过该学号
// 姓名会被自动 base64 转码后存储（name_encoded 列），不受数据库不支持中文的限制
$default_students = [
    1 => '马欣怡', 2 => '王雨洁', 3 => '王烁', 4 => '韦思萱', 5 => '韦蒋昀',
    6 => '', 7 => '卢泺逸', 8 => '包国宏', 9 => '许昊林', 10 => '杜加烨',
    11 => '李锦程', 12 => '吴舒恺', 13 => '何雨轩', 14 => '张京达', 15 => '',
    16 => '张雅雯', 17 => '张静以', 18 => '', 19 => '陈书瑶', 20 => '陈彦均',
    21 => '陈慧笑', 22 => '金圣涵', 23 => '郑宸睿', 24 => '郑熙楚', 25 => '单皓莮',
    26 => '赵奕欣', 27 => '胡知行', 28 => '胡晨刚', 29 => '俞舒嫣', 30 => '钱俊豪',
    31 => '徐晟轩', 32 => '徐婧怡', 33 => '徐澄汐', 34 => '郭博涛', 35 => '曹一宸',
    36 => '康哲铭', 37 => '章宸', 38 => '斯展', 39 => '楼弈天', 40 => '楼湖城',
    41 => '蔡铭炜'
];

// 创建数据库连接
function getDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("数据库连接失败: " . $e->getMessage());
    }
}

// 姓名转码：base64 编码存储（数据库不支持中文时的兼容方案）
function encodeName($name) { return base64_encode($name); }
function decodeName($encoded) { return base64_decode($encoded); }

// 数字转中文（1 → 一，用于"一班"等显示）
function chineseNumber($n) {
    $digits = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
    if ($n < 10) return $digits[$n];
    if ($n < 20) return '十' . $digits[$n % 10];
    return $digits[intval($n / 10)] . '十' . $digits[$n % 10];
}

// 取当前日期所属学期起点
function getSemesterStart($date = null) {
    global $semester_starts;
    $date = $date ?: date('Y-m-d');
    $best = null;
    foreach ($semester_starts as $start) {
        if ($start <= $date && ($best === null || $start > $best)) $best = $start;
    }
    return $best ?: $semester_starts[0];
}

// 初始化数据库表与种子数据（首次访问自动执行）
function initDatabase() {
    $pdo = getDB();

    // 班级表（password=班级密码，teacher_password=教师管理密码，两者分开但初始相同）
    $pdo->exec("CREATE TABLE IF NOT EXISTS classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_number INT NOT NULL UNIQUE,
        password VARCHAR(64) NOT NULL,
        teacher_password VARCHAR(64) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 迁移：老库 classes 表没有 teacher_password 列时补列，初始值 = 班级密码
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM classes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('teacher_password', $cols)) {
            $pdo->exec("ALTER TABLE classes ADD COLUMN teacher_password VARCHAR(64) NOT NULL DEFAULT ''");
            $pdo->exec("UPDATE classes SET teacher_password = password");
        }
    } catch (PDOException $e) {
        // 表不存在时忽略（上面已建表）
    }

    // 学生表（姓名 base64 转码后存 name_encoded）
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        student_no INT NOT NULL,
        name_encoded VARCHAR(128) NOT NULL,
        INDEX idx_class (class_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 朗读记录表
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        student_id INT NOT NULL,
        record_type ENUM('morning', 'evening') NOT NULL,
        record_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_canceled BOOLEAN DEFAULT FALSE,
        week_number INT,
        month_number INT,
        semester_week INT,
        INDEX idx_class_student_date (class_id, student_id, record_date),
        INDEX idx_class_date_type (class_id, record_date, record_type),
        INDEX idx_class_week (class_id, week_number),
        INDEX idx_class_month (class_id, month_number)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 周统计表
    $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_stats (
        class_id INT NOT NULL,
        student_id INT NOT NULL,
        week_number INT NOT NULL,
        morning_count INT DEFAULT 0,
        evening_count INT DEFAULT 0,
        penalty_count INT DEFAULT 0,
        last_penalty_time TIMESTAMP NULL,
        PRIMARY KEY (class_id, student_id, week_number)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 扣分记录表
    $pdo->exec("CREATE TABLE IF NOT EXISTS penalty_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        student_id INT NOT NULL,
        week_number INT NOT NULL,
        penalty_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        record_type ENUM('morning', 'evening') NULL,
        INDEX idx_class_student_week (class_id, student_id, week_number)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 全局设置表（早晚读时间段等，可在总管理界面修改）
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ([
        'morning_start' => '06:20',
        'morning_end'   => '07:10',
        'evening_start' => '17:45',
        'evening_end'   => '18:20',
    ] as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // 种子：创建 1~CLASS_COUNT 个班，班级密码与教师管理密码初始相同 = admin + 两位班级号
    for ($n = 1; $n <= CLASS_COUNT; $n++) {
        $initial_pwd = 'admin' . str_pad($n, 2, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT IGNORE INTO classes (class_number, password, teacher_password) VALUES (?, ?, ?)");
        $stmt->execute([$n, $initial_pwd, $initial_pwd]);
    }

    // 种子：四班预置默认学生名单（其他班由老师在管理界面添加）
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_number = 4");
    $stmt->execute();
    $class4 = $stmt->fetch();
    if ($class4) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM students WHERE class_id = ?");
        $stmt->execute([$class4['id']]);
        if ((int)$stmt->fetch()['c'] === 0) {
            global $default_students;
            foreach ($default_students as $no => $name) {
                if ($name === '') continue;
                $stmt = $pdo->prepare("INSERT INTO students (class_id, student_no, name_encoded) VALUES (?, ?, ?)");
                $stmt->execute([$class4['id'], (int)$no, encodeName($name)]);
            }
        }
    }
}
?>
