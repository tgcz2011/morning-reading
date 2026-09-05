<?php
// ============================================================
// 配置模板：部署时复制本文件为 config.php，并填写真实信息
//   cp config.example.php config.php
// config.php 含数据库与登录密码，已被 .gitignore 排除，禁止入库
// ============================================================

// 数据库配置
define('DB_HOST', '请填写数据库主机');
define('DB_USER', '请填写数据库用户名');
define('DB_PASS', '请填写数据库密码');
define('DB_NAME', '请填写数据库名');
define('DB_PORT', 3306);

// 身份验证密码
define('LOGIN_PASSWORD', '请填写登录密码');

// 学生名单 (学号 => 姓名)
$students = [
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

// 初始化数据库表
function initDatabase() {
    $pdo = getDB();
    
    // 创建记录表
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        record_type ENUM('morning', 'evening') NOT NULL,
        record_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_canceled BOOLEAN DEFAULT FALSE,
        week_number INT,
        month_number INT,
        semester_week INT,
        INDEX idx_student_date (student_id, record_date),
        INDEX idx_date_type (record_date, record_type),
        INDEX idx_week (week_number),
        INDEX idx_month (month_number)
    )");
    
    // 创建周统计表 (用于优化查询)
    $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_stats (
        student_id INT NOT NULL,
        week_number INT NOT NULL,
        morning_count INT DEFAULT 0,
        evening_count INT DEFAULT 0,
        penalty_count INT DEFAULT 0,
        last_penalty_time TIMESTAMP NULL,
        PRIMARY KEY (student_id, week_number)
    )");
    
    // 创建负分记录表 (新增)
    $pdo->exec("CREATE TABLE IF NOT EXISTS penalty_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        week_number INT NOT NULL,
        penalty_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        record_type ENUM('morning', 'evening') NULL,
        INDEX idx_student_week (student_id, week_number)
    )");
}
?>
