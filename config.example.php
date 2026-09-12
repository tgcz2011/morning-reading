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

// 班级数量：每个年级自动创建 1~CLASS_COUNT 个班
// 初始密码 = admin + 两位班级号（一班=admin01，二班=admin02，以此类推）
define('CLASS_COUNT', 14);

// 数据库结构版本：每次表结构/种子变更时递增，initDatabase 据此跳过已完成的初始化
define('DB_VERSION', 5);

// 项目版本号（a.b.c.d：d=小改动/修复，c=小添加，b=大改，a=大添加）
define('APP_VERSION', '1.0.0.0');
define('APP_AUTHOR', '陈彦均');
define('APP_REPO', 'https://github.com/tgcz2011/morning-reading');

// 年级列表：7=初一 8=初二 9=初三（原有年级）10=高一 11=高二 12=高三
function gradeList() {
    return [7 => '初一', 8 => '初二', 9 => '初三', 10 => '高一', 11 => '高二', 12 => '高三'];
}

// 年级数字转中文名（9 -> 初三）
function gradeName($grade) {
    $list = gradeList();
    return isset($list[(int)$grade]) ? $list[(int)$grade] : '';
}

// 每个年级的班级数：初中（初一/初二/初三）14 个班，高中（高一/高二/高三）11 个班
function gradeClassCount($grade) {
    $grade = (int)$grade;
    if ($grade >= 7 && $grade <= 9) return 14;  // 初中
    if ($grade >= 10 && $grade <= 12) return 11; // 高中
    return 0;
}

// 学期开始日期：学期统计按此日期分割
// 统计时取「当前日期之前最近的一个日期」作为本学期起点
$semester_starts = [
    '2026-02-26',
    '2026-09-01',
    '2027-02-25',
    '2027-09-01',
];

// 创建数据库连接
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    return $pdo;
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

    // 版本检查：结构与种子已是最新版本时直接跳过，避免每次请求跑 75 次 INSERT IGNORE
    try {
        $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'db_version'")->fetchColumn();
        if ($v !== false && (int)$v === DB_VERSION) return;
    } catch (PDOException $e) {
        // settings 表不存在时继续初始化
    }

    // 班级表（grade=年级 7初一/8初二/9初三/10高一/11高二/12高三；password=班级密码，teacher_password=教师管理密码）
    // 班号在同一年级内唯一：UNIQUE(grade, class_number)
    $pdo->exec("CREATE TABLE IF NOT EXISTS classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade TINYINT NOT NULL DEFAULT 9,
        class_number INT NOT NULL,
        password VARCHAR(64) NOT NULL,
        teacher_password VARCHAR(64) NOT NULL DEFAULT '',
        active_session_token VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_grade_class (grade, class_number)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 迁移①：老库 classes 表没有 teacher_password 列时补列，初始值 = 班级密码
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM classes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('teacher_password', $cols)) {
            $pdo->exec("ALTER TABLE classes ADD COLUMN teacher_password VARCHAR(64) NOT NULL DEFAULT ''");
            $pdo->exec("UPDATE classes SET teacher_password = password");
        }
        // 迁移②：老库没有 grade 列时补列，原有班级全部归属初三（9）
        if (!in_array('grade', $cols)) {
            $pdo->exec("ALTER TABLE classes ADD COLUMN grade TINYINT NOT NULL DEFAULT 9 AFTER id");
        }
        // 迁移③：老库班号单列唯一 → 改为(年级,班号)联合唯一
        $idx = $pdo->query("SHOW INDEX FROM classes")->fetchAll(PDO::FETCH_ASSOC);
        $has_uk = false;
        $has_single = false;
        foreach ($idx as $ix) {
            if ($ix['Key_name'] === 'uk_grade_class') $has_uk = true;
            if ($ix['Key_name'] === 'class_number' && (int)$ix['Non_unique'] === 0) $has_single = true;
        }
        if (!$has_uk && $has_single) {
            $pdo->exec("ALTER TABLE classes DROP INDEX class_number, ADD UNIQUE KEY uk_grade_class (grade, class_number)");
        } elseif (!$has_uk) {
            $pdo->exec("ALTER TABLE classes ADD UNIQUE KEY uk_grade_class (grade, class_number)");
        }
        // 迁移④：老库没有 active_session_token 列时补列（单会话登录用）
        if (!in_array('active_session_token', $cols)) {
            $pdo->exec("ALTER TABLE classes ADD COLUMN active_session_token VARCHAR(64) NULL AFTER teacher_password");
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
        INDEX idx_class_month (class_id, month_number),
        UNIQUE KEY uk_student_date_type (class_id, student_id, record_date, record_type)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 迁移：老库 reading_records 没有唯一索引时补加（数据库层面防重复加分兜底）
    try {
        $idx = $pdo->query("SHOW INDEX FROM reading_records")->fetchAll(PDO::FETCH_ASSOC);
        $has_uk = false;
        foreach ($idx as $ix) {
            if ($ix['Key_name'] === 'uk_student_date_type') { $has_uk = true; break; }
        }
        if (!$has_uk) {
            // 先清理可能存在的重复数据（保留最新的一条），再加唯一索引
            $pdo->exec("DELETE t1 FROM reading_records t1 INNER JOIN reading_records t2 
                        WHERE t1.class_id=t2.class_id AND t1.student_id=t2.student_id 
                        AND t1.record_date=t2.record_date AND t1.record_type=t2.record_type 
                        AND t1.id < t2.id");
            $pdo->exec("ALTER TABLE reading_records ADD UNIQUE KEY uk_student_date_type (class_id, student_id, record_date, record_type)");
        }
    } catch (PDOException $e) {
        // 表不存在或加索引失败时忽略
    }

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

    // 种子：每个年级创建对应数量的班（初中14个，高中11个），初始密码 = admin + 两位班级号
    $stmt = $pdo->prepare("INSERT IGNORE INTO classes (grade, class_number, password, teacher_password) VALUES (?, ?, ?, ?)");
    foreach (array_keys(gradeList()) as $grade) {
        $cnt = gradeClassCount($grade);
        for ($n = 1; $n <= $cnt; $n++) {
            $initial_pwd = 'admin' . str_pad($n, 2, '0', STR_PAD_LEFT);
            $stmt->execute([$grade, $n, $initial_pwd, $initial_pwd]);
        }
    }

    // 清理：高中实际只有11个班，删除早期误建的高中12-14班（仅当该班无学生无记录时，安全兜底）
    try {
        $toDelete = $pdo->query("SELECT c.id FROM classes c
            WHERE c.grade IN (10,11,12) AND c.class_number > 11
            AND NOT EXISTS (SELECT 1 FROM students s WHERE s.class_id=c.id)
            AND NOT EXISTS (SELECT 1 FROM reading_records r WHERE r.class_id=c.id)
            AND NOT EXISTS (SELECT 1 FROM penalty_records p WHERE p.class_id=c.id)")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($toDelete)) {
            $ids = implode(',', array_map('intval', $toDelete));
            $pdo->exec("DELETE FROM classes WHERE id IN ($ids)");
        }
    } catch (PDOException $e) {
        // 表结构未就绪时忽略
    }

    // 写入当前版本号，后续请求直接跳过初始化
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('db_version', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([DB_VERSION]);
    } catch (PDOException $e) {}
}
?>
