# HANDOFF.md — 班级朗读记录系统 交接文档

> 最后更新: 2026-09-12（v1.0.0.0，首个正式版：多班级多年级、单会话登录、Excel 批量导入、统计排行、所有页面底部作者信息）

## 一、需求（用户原始要求）

1. 从 `zd.zip`（zd4/ 目录）起步，改掉原蓝紫渐变 UI，部署到 GitHub 与 InfinityFree FTP（`/htdocs/morning-reading/`）。
2. 点击即时反馈（不等服务器回传），一次朗读最多加一分，扣分不限次数。
3. 印章文字「优秀/差」，自绘弹窗替代浏览器 alert（老师从远处能看到）。
4. **多班级大改**：所有班级共用一套站点，登录 = 班级号 + 密码；初始密码 `admin` + 两位班号；教师管理界面（本班）与总管理界面（superadmin，纯背网址无入口）两级分开。
5. **年级维度**：新增初一、初二、高一、高二、高三（原有初三），登录界面加年级下拉默认初三；初中每个年级 14 个班，高中每个年级 11 个班，共 75 个班。
6. **Excel 批量导入学生名单**：老师不用逐字输入；第一列学号、第二列姓名；模板下载 + 上传 .xlsx → 预览 → 确认导入；冲突即覆盖；模板也用 Excel（不用 CSV，老师听不懂）。
7. **统计**：今日/本周/本月/学期/总统计；月/学期/总不计负分；学期按寒暑假分割；加分优先抵消负分并不被记录。
8. **时段可配置**：总管理界面可改早晚读时间段，首页和后台接口同步更新；修复硬编码「小时<12=早读」导致 11 点被算早读的 bug。
9. **登录有效期**：记录页 3 小时，教师管理页 7 天；到期立即跳转登录页（不能出现记一半登录过期但还在主页加不上分的情况）。
10. **单会话登录**：同一个班级同时只允许一个记录会话，另一台设备登录后旧会话立即失效；坚决不能有浏览器与服务器数据不同步。
11. **性能优化**：统计页太卡、页面加载慢；优化计算逻辑和查询次数。
12. **数据存云端**：浏览器只保留登录凭据（session），不存业务数据。
13. **生产上线前清空调试数据**：只保留表结构，所有班级名单和密码重置。
14. **v1.0.0.0 追加**：参考 countdown-desktop 的文档与版本管理（a.b.c.d），所有页面底部加作者信息（陈彦均）、版本号、GPL-3.0 许可证、鸣谢豆包技术支持、GitHub 仓库与 Issues 链接；补 HANDOFF.md 交接文档。

## 二、版本历史

| 版本 | 技术 | 结论 |
|------|------|------|
| （无 tag）zd4 原始版 | PHP + MySQL，单班级，蓝紫 UI | 起点，UI 被用户否决 |
| （无 tag）纸墨印章 UI + 基础功能 | 同上，改 UI + 加分/扣分/统计 | 上线可用 |
| （无 tag）多班级大改 | 加 classes 表、两级管理、base64 姓名 | 14 班共用 |
| （无 tag）年级维度 | classes 加 grade 字段，75 班种子 | 初中14/高中11 |
| （无 tag）Excel 导入 + 时段配置 + 单会话 + 登录过期 + 性能优化 + 代码审查 | 加 SimpleXLSX、heartbeat.php、active_session_token、getDB 单例、addRecord 事务 | 功能完整 |
| **v1.0.0.0** | **同上** | **首个正式版：所有页面底部作者信息/版本号/许可证/鸣谢，README 与 HANDOFF 补全，git tag** |

## 三、架构

```
浏览器（原生 HTML/CSS/JS，无框架）
  │  HTTP 请求（session cookie 鉴权）
  ▼
PHP 8.x（无框架，单文件多页面，PDO 连 MySQL）
  ├─ index.php      班级登录 + 记录页（加分/扣分，3h 过期）
  ├─ stats.php      统计页（今日/本周/本月/学期/总，3h 过期）
  ├─ admin.php      教师管理（本班名单/密码/清空，7d 过期）
  ├─ edit.php       总管理 superadmin（所有班级/时段，无入口链接）
  ├─ heartbeat.php  心跳接口（前端定时调用，检测过期/被踢）
  ├─ config.php     生产配置（DB 凭据 + 总管理密码 + 版本常量，不入库）
  ├─ functions.php  核心业务逻辑（鉴权/记录/统计/导入/时段，46KB）
  ├─ style.css      纸墨印章主题样式
  ├─ chart.umd.min.js  Chart.js 本地文件（不依赖 CDN，校园网友好）
  └─ simplexlsx.php SimpleXLSX Excel 解析库（MIT，单文件无依赖）
  │
  ▼
MySQL / MariaDB（InnoDB，utf8mb4）
  ├─ classes          班级（75 行：初中各14 + 高中各11）
  ├─ students         学生（name_encoded = base64 姓名）
  ├─ reading_records  加分记录（唯一索引防重复）
  ├─ penalty_records  扣分记录
  ├─ weekly_stats     周统计（主键 class_id+student_id+week_number）
  └─ settings         系统设置（时段 + db_version）
```

**关键设计决策**：
- **无框架**：PHP 单文件多页面，部署简单（InfinityFree 免费主机不支持 Composer/框架）。
- **getDB() 单例**：整个请求只建一个 PDO 连接，事务可跨函数共享。
- **单会话**：`classes.active_session_token`，login 生成随机 token 写入，checkAuth 每次校验，不一致则踢下线。
- **登录过期**：浏览器端 `performance.now()` 单调时钟倒计时（不受系统时间修改影响），到期立即跳转；`heartbeat.php` 低频兜底检测服务器端提前过期。
- **加分抵消**：加分时若本周有负分 → DELETE 一条 penalty_records + penalty_count-1 + 写 is_canceled=TRUE 抵消记录（不计入统计，但用于「已加分」锁定）；扣分时先抵消正分。
- **数据库唯一索引兜底**：`reading_records` 有 `(class_id, student_id, record_date, record_type)` 唯一索引，即使竞态也不可能重复加分。
- **addRecord 事务**：抵消操作三步（DELETE + UPDATE + INSERT）用事务包裹，中间失败回滚。
- **中文姓名 base64**：数据库字符集不支持中文也不影响，读取时自动解码。

## 四、踩过的错误 / 经验

### 部署与主机（InfinityFree 免费主机）
1. **tempnam() 返回 false 导致 ZipArchive 500**：InfinityFree 禁用临时目录，`tempnam()` 返回 false，ZipArchive 打开失败。修复：纯 PHP 手写标准 ZIP 字节流输出模板下载（`outputStudentTemplate`）。
2. **JS 挑战页**：InfinityFree 对非浏览器 UA 返回 JS 挑战页。curl 验证需：UA `Mozilla/5.0` → 正则取 `c=toNumbers("...")` → `openssl aes-128-cbc` 解密得 `__test` cookie → 第二次带 Cookie 访问同 URL 加 `?i=1` 才是真实页面。
3. **HTTP 可用，HTTPS 返回 000**：InfinityFree 免费主机的 SSL 证书有时异常，用 `http://` 访问。
4. **不支持 WebSocket/SSE 长连接**：免费主机不支持常驻进程，单会话踢下线用心跳 + 前端倒计时替代。
5. **FTP 部署必须回读 md5 校验**：HTTP 访问 PHP 文件返回执行结果（或挑战页），无法直接比对内容；用 FTP 回读文件算 md5 确认部署一致。
6. **临时 PHP 诊断脚本执行完必须 FTP DELE 删除**：不要留在生产环境。

### PHP / Session
7. **session_destroy() 不清空 $_SESSION**：只销毁服务器端 session 文件，`$_SESSION` 数组仍在内存，后续 `isset()` 仍为 true，导致门禁失效。修复：退出时先 `session_unset()` 再 `session_destroy()`。
8. **服务器 UTC 时区偏差 8 小时**：InfinityFree 服务器默认 UTC，`date('Y-m-d')` 比北京时间晚 8 小时，导致时段判断和记录日期错误。修复：`config.php` 顶部 `date_default_timezone_set('Asia/Shanghai')`。

### 业务逻辑
9. **getCurrentRecordType 硬编码「小时<12=早读」**：导致 11 点被算成早读（实际应按配置的晚读开始时间判断）。修复：读 `settings` 表的时段配置，当前时间在早读区间=早读，在晚读区间=晚读，否则=非记录时段。
10. **统计页学号列显示自增 ID 而非学号**：原代码用 `students.id`（自增主键 1、2、3…）作为学号显示。修复：`getStudentMap()` 返回 `student_no`，统计页用真学号。
11. **getDB() 无单例缓存**：每次调用新建 PDO 连接，一个页面可能创建 5-10 个连接，浪费资源且可能触发连接数限制。修复：`static $pdo` 单例。
12. **addRecord 无事务**：抵消负分的三步操作（DELETE penalty_records + UPDATE weekly_stats + INSERT reading_records）中间失败会数据不一致。修复：事务包裹，唯一索引冲突时返回友好提示而非异常。
13. **getStudentStatus 与 getAllStudentsStatus 的 has_penalty 计算不一致**：前者额外查一次 penalty_records 表，后者用已查的 weekly_stats.penalty_count。统一为用 weekly_stats.penalty_count（减少一次查询）。

### Excel 导入
14. **老师听不懂「导出为 CSV」**：直接用 Excel 格式（.xlsx），模板也用 Excel，第一列学号第二列姓名。
15. **冲突处理**：学号已存在的自动覆盖（更新姓名，保留学号和历史记录），不是跳过。
16. **SimpleXLSX 单文件库**：MIT License，无依赖，直接 include 即可（不用 Composer）。

### UI
17. **「已加分」标签挤动姓名**：原标签和姓名在同一行，加了标签后姓名位移。修复：`added-chip` 移到姓名下方独立 div。
18. **时段设置表单错位**：晚读开始标签在第一行末、输入框掉到第二行首。修复：`period-field` 包裹标签+输入框。

## 五、项目结构

```
morning-reading/
├── index.php           班级登录 + 记录页（加分/扣分，3h 过期，performance.now() 倒计时 + 5min 心跳）
├── stats.php           统计页（今日/本周/本月/学期/总，排行榜 + 饼图，3h 过期）
├── admin.php           教师管理（本班学生增删改/Excel导入/两套密码修改/清空本班数据，7d 过期）
├── edit.php            总管理 superadmin（所有班级两套密码/任意班学生/清空数据/时段设置，无入口链接）
├── heartbeat.php       通用心跳接口（?type=record 3h / ?type=admin 7d，返回 expired+redirect 或 alive）
├── config.php          生产配置（DB 凭据 + SUPERADMIN_PASSWORD + APP_VERSION/APP_AUTHOR/APP_REPO，不入库）
├── config.example.php  配置模板（入库，部署时复制为 config.php）
├── functions.php       核心业务逻辑（46KB：鉴权/记录/统计/导入/时段/批量查询）
├── style.css           纸墨印章主题样式（23KB，含 .site-footer 页脚）
├── chart.umd.min.js    Chart.js 4.4.9 本地文件（207KB，不依赖 CDN）
├── simplexlsx.php      SimpleXLSX Excel 解析库（41KB，MIT，namespace Shuchkin）
├── README.md           用户文档（功能/部署/表结构/FAQ/版本规则/作者鸣谢/License）
├── HANDOFF.md          本文件（交接文档：需求/版本历史/架构/踩坑/项目结构/toolchain/部署/版本规则/已知限制/验证方法）
├── LICENSE             GPL-3.0
└── .gitignore          排除 config.php（含真实 DB 凭据和总管理密码）
```

## 六、toolchain

| 工具 | 位置/版本 | 说明 |
|------|-----------|------|
| PHP | 8.x（InfinityFree 自带） | PDO + pdo_mysql，无框架 |
| MySQL | InfinityFree 共享主机（sql211.infinityfree.com） | 数据库名/用户名 `if0_38682505_morning_reading` / `if0_38682505` |
| 本地 MySQL | 3307 端口，socket `/tmp/mysql-test.sock`，datadir `/tmp/mysql-data` | 开发验证用 |
| 本地 PHP 内置服务器 | `127.0.0.1:8766`，测试副本 `/tmp/zd4-test/` | 开发验证用 |
| Chart.js | 4.4.9 本地文件 `chart.umd.min.js` | 不依赖 CDN（校园网对 CF CDN 延迟高） |
| SimpleXLSX | 单文件 `simplexlsx.php`（MIT） | Excel 解析，无依赖 |
| git | 已登录 tgcz2011 / tgcz2011@yeah.net | 仓库 `tgcz2011/morning-reading`，分支 main |
| FTP | `ftpupload.net:21`，用户 `if0_38682505` | 生产部署，curl 必须加 `--noproxy '*'` |
| 线上地址 | `http://zztool.free.nf/morning-reading/` | HTTP 可用，HTTPS 曾返回 000 |

## 七、部署与发布

### 本地开发验证
```bash
# 启动本地 MySQL（首次需初始化 datadir）
/opt/homebrew/bin/mysqld --datadir=/tmp/mysql-data --socket=/tmp/mysql-test.sock --port=3307 --bind-address=127.0.0.1 &

# 复制源码到测试目录并打本地 DB 补丁
cp -r /Users/tgcz2011/Downloads/zd_extract/zd4/* /tmp/zd4-test/
sed -i '' "s/DB_HOST', '[^']*'/DB_HOST', '127.0.0.1'/; s/DB_USER', '[^']*'/DB_USER', 'root'/; s/DB_PASS', '[^']*'/DB_PASS', ''/; s/DB_PORT', [0-9]*/DB_PORT', 3307/" /tmp/zd4-test/config.php

# 启动 PHP 内置服务器
(cd /tmp/zd4-test && nohup php -S 127.0.0.1:8766) &

# 访问 http://127.0.0.1:8766/
```

### 生产部署（FTP）
```bash
cd /Users/tgcz2011/Downloads/zd_extract/zd4
# 上传文件
curl --noproxy '*' -s -T <file> "ftp://if0_38682505:<password>@ftpupload.net/htdocs/morning-reading/<file>"
# 回读校验 md5（必须，HTTP 无法直接比对 PHP 源码）
curl --noproxy '*' -s "ftp://if0_38682505:<password>@ftpupload.net/htdocs/morning-reading/<file>" -o /tmp/dl_<file>
md5 -q <file>  # 应与 md5 -q /tmp/dl_<file> 一致
```

### 线上验证（InfinityFree JS 挑战解法）
```bash
CH=$(curl --noproxy '*' -sL -A "Mozilla/5.0" "http://zztool.free.nf/morning-reading/index.php")
C=$(echo "$CH" | grep -oE 'c=toNumbers\("[^"]*"\)' | sed 's/c=toNumbers("//;s/")//')
D=$(echo "$C" | xxd -r -p | openssl enc -d -aes-128-cbc -K f655ba9d09a112d4968c63579db590b4 -iv 98344c2eee86c3994890592585b49f80 -nopad 2>/dev/null | xxd -p | tr -d '\n')
curl --noproxy '*' -sL -A "Mozilla/5.0" -H "Cookie: __test=$D" "http://zztool.free.nf/morning-reading/index.php?i=1"
```

### Git 发布
```bash
cd /Users/tgcz2011/Downloads/morning-reading-gh
# 同步源码（config.php 不入库，用 config.example.php）
cp /Users/tgcz2011/Downloads/zd_extract/zd4/{functions.php,index.php,admin.php,edit.php,stats.php,style.css,heartbeat.php,simplexlsx.php,chart.umd.min.js} .
git add -A
git -c user.name=tgcz2011 -c user.email=tgcz2011@yeah.net commit -m "..."
git -c user.name=tgcz2011 -c user.email=tgcz2011@yeah.net tag -a v1.0.0.0 -m "..."
git push origin main
git push origin v1.0.0.0
```

**注意**：`config.php` 含真实数据库凭据和总管理密码，已被 `.gitignore` 排除，**禁止提交到公开仓库**；公开仓库只保留 `config.example.php` 模板。

## 八、版本规则

`a.b.c.d`：d=小改动/修复，c=小添加，b=大改，a=大添加；去掉点后数值严格递增。当前最高已发布 tag：**v1.0.0.0**。

每次更新必须同步更新：
1. `config.php` 和 `config.example.php` 中的 `APP_VERSION` 常量
2. `README.md`（当前版本说明）
3. `HANDOFF.md`（本文件，版本历史 + 最后更新日期）
4. git tag

## 九、数据库表结构

### classes（班级，75 行）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK AUTO_INCREMENT | 自增主键 |
| grade | TINYINT | 7=初一 8=初二 9=初三 10=高一 11=高二 12=高三 |
| class_number | TINYINT | 班级号（1-14 初中，1-11 高中） |
| password | VARCHAR | 班级记录密码（初始 admin+两位班号） |
| teacher_password | VARCHAR | 教师管理密码（初始同班级密码） |
| active_session_token | VARCHAR(64) | 当前活跃会话 token（单会话登录用，NULL=无活跃会话） |
| created_at | DATETIME | 创建时间 |
| UNIQUE | uk_grade_class (grade, class_number) | 同年级同班号唯一 |

### students（学生）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK AUTO_INCREMENT | 自增主键（**不是学号**） |
| class_id | INT | 所属班级 classes.id |
| student_no | VARCHAR | 学号（老师导入的第一列） |
| name_encoded | TEXT | base64 编码的姓名（数据库不支持中文时的兼容方案） |
| INDEX | idx_class (class_id) | |

### reading_records（加分记录）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK AUTO_INCREMENT | |
| class_id | INT | |
| student_id | INT | |
| record_type | ENUM('morning','evening') | 早读/晚读 |
| record_date | DATE | 记录日期 |
| created_at | DATETIME | |
| is_canceled | TINYINT(1) | 是否被扣分抵消（1=抵消标记，不计入统计） |
| week_number | INT | 学年周序号（用于周统计） |
| month_number | VARCHAR(7) | YYYY-MM（用于月统计） |
| semester_week | INT | 学期内周序号（用于学期统计） |
| UNIQUE | uk_student_date_type (class_id, student_id, record_date, record_type) | **数据库层面防重复加分** |
| INDEX | 多个 | idx_class_date, idx_student_week 等 |

### penalty_records（扣分记录）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK AUTO_INCREMENT | |
| class_id | INT | |
| student_id | INT | |
| week_number | INT | 学年周序号（扣分按周累计） |
| penalty_time | DATETIME | 扣分时间 |
| record_type | ENUM('morning','evening') NULL | 扣分时段（可为空） |

### weekly_stats（周统计）
| 字段 | 类型 | 说明 |
|------|------|------|
| class_id | INT | PK 第一列 |
| student_id | INT | PK 第二列 |
| week_number | INT | PK 第三列 |
| morning_count | INT | 本周早读加分次数 |
| evening_count | INT | 本周晚读加分次数 |
| penalty_count | INT | 本周扣分次数（加分抵消时 -1） |
| last_penalty_time | DATETIME | 最近一次扣分时间 |

### settings（系统设置，键值对）
| 字段 | 类型 | 说明 |
|------|------|------|
| setting_key | VARCHAR PK | 键名 |
| setting_value | TEXT | 值 |

预置键：`morning_start`（默认 06:20）、`morning_end`（默认 07:00）、`evening_start`（默认 17:45）、`evening_end`（默认 18:30）、`db_version`（当前 5，用于迁移控制）。

**无外键约束**（InfinityFree 共享主机可能不支持，用应用层保证一致性）。

## 十、关键业务逻辑

### 加分流程（addRecord，functions.php）
1. 检查登录态（3h 过期 + 单会话 token）
2. 检查当前时段是否在配置的早读/晚读区间
3. 检查该生今天此时段是否已加过分（`hasAddedThisSession`，查 reading_records 含抵消记录）→ 已加过则返回友好提示
4. **事务开始**
5. 检查本周是否有负分（`weekly_stats.penalty_count > 0`）：
   - 有负分：DELETE 一条 penalty_records（按 last_penalty_time 最早的）→ penalty_count -1 → INSERT 一条 reading_records 带 `is_canceled=1`（抵消标记，不计入统计但用于「已加分」锁定）
   - 无负分：INSERT 一条 reading_records（正常加分）→ 更新 weekly_stats 对应 count +1
6. **事务提交**
7. 唯一索引冲突时（竞态）回滚并返回友好提示

### 扣分流程（penalizeStudent）
1. 检查本周是否有正分（reading_records 未取消的）：
   - 有正分：把最早一条正分标记为 `is_canceled=1` → weekly_stats 对应 count -1
   - 无正分：INSERT 一条 penalty_records → penalty_count +1
2. 扣分不限次数

### 统计口径
- **今日**：今天 reading_records 中 is_canceled=0 的次数（不计负分）
- **本周**：净分 = (morning_count + evening_count) - penalty_count（weekly_stats 直接读）
- **本月/学期/总**：reading_records 中 is_canceled=0 的次数（不计负分）
- 学期分割：`$semester_starts` 数组（如 `['2026-02-26','2026-09-01',...]`），取当前日期之前最近的一个作为本学期起点

### 单会话登录
1. login 时生成 `bin2hex(random_bytes(32))` 作为 token
2. UPDATE classes SET active_session_token=token WHERE id=?
3. $_SESSION['session_token']=token
4. checkAuth 每次请求：SELECT active_session_token FROM classes WHERE id=?，与 $_SESSION['session_token'] 比较
5. 不一致 → 返回 `{kicked:true}`，前端 AJAX 检测到自动跳转登录页
6. 退出时 UPDATE classes SET active_session_token=NULL

### 登录过期
- 记录页/统计页：`$_SESSION['login_time']`，3 小时（10800 秒）
- 教师管理页：`$_SESSION['teacher_login_time']`，7 天（604800 秒）
- 总管理页：无过期
- 前端：页面打开时从服务器取剩余时间 → `performance.now()` 单调时钟倒计时（不受系统时间修改影响）→ 到期立即 `window.location.href` 跳转
- 心跳兜底：记录页每 5 分钟、教师页每 30 分钟调用 `heartbeat.php`，服务器端检测到过期返回 `{expired:true, redirect:'...'}`，前端跳转

## 十一、已知限制 / 待办

1. **InfinityFree 免费主机限制**：不支持 WebSocket/SSE，单会话踢下线有最多 5 分钟延迟（心跳间隔）；前端倒计时保证到期立即跳转，但被踢下线依赖心跳。
2. **无外键约束**：删除班级/学生时需手动清理关联记录（当前清空数据功能用 TRUNCATE，正常）。
3. **总管理界面无入口链接**：靠保密性降低被访问概率，仍应设置强密码（当前 `0904`，建议生产环境修改）。
4. **base64 姓名不是加密**：仅为规避数据库字符集限制，不要在姓名中存储敏感信息。
5. **Excel 导入上限**：SimpleXLSX 一次性加载整个文件到内存，超大文件（>10MB）可能内存不足；班级名单通常几十行，无影响。
6. **cancelRecord 只能取消当天的记录**：设计意图（当天误操作可撤销），跨天的记录不能取消。
7. **HTTPS 证书**：InfinityFree 免费主机 SSL 有时异常，当前用 HTTP；如需 HTTPS 需配置 Cloudflare 或升级主机。
8. **多显示器/移动端**：UI 已做响应式，但未在所有设备上充分测试。

## 十二、验证方法备忘

### 本地回归测试
```bash
# 测试脚本放 /tmp/zd4-test/，参考历史测试脚本命名
# _test_grade_migration.php    年级迁移
# _test_grade_count.php        班级数量（初中14/高中11）
# _test_perf.php               性能（查询次数）
# _test_concurrency.php        并发（唯一索引兜底）
# _test_heartbeat.php          心跳/过期
# _test_review.php             代码审查回归（getDB单例/学号/事务/一致性）

# 运行
php /tmp/zd4-test/_test_review.php
```

### 生产验证清单
- [ ] `http://zztool.free.nf/morning-reading/` 登录页正常加载，底部有 v1.0.0.0 页脚
- [ ] 选年级+班级号+初始密码登录成功
- [ ] 学生卡片显示正常，「已加分」标签在姓名下方不挤动
- [ ] 加分即时反馈（波纹+toast），服务器返回前 UI 已有反应
- [ ] 重复加分被拦截（弹出大字提示）
- [ ] 扣分后加分优先抵消负分（统计页净分正确）
- [ ] 统计页学号列显示真学号（不是 1、2、3…）
- [ ] 统计页饼图正常渲染（本地 Chart.js，不请求 CDN）
- [ ] 另一台设备登录后，旧设备操作返回「已在其他设备登录」并跳转
- [ ] 3 小时后记录页自动跳转登录页（可临时改 config 缩短过期时间测试）
- [ ] Excel 模板下载正常（admin.php?action=download_template）
- [ ] Excel 导入：冲突学号覆盖、坏行跳过、预览正确
- [ ] 总管理 edit.php 时段修改后，记录页时段判断同步更新
- [ ] FTP 回读所有修改文件的 md5 与本地一致

### 生产数据库快速检查（线上 PHP 临时脚本）
```php
<?php
require_once 'config.php';
$pdo = getDB();
echo "classes: " . $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn() . "\n";
echo "students: " . $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn() . "\n";
echo "reading_records: " . $pdo->query("SELECT COUNT(*) FROM reading_records")->fetchColumn() . "\n";
echo "settings: " . $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() . "\n";
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) echo "  {$r['setting_key']}={$r['setting_value']}\n";
```
执行完立即 FTP DELE 删除。

## 十三、联系人

- **作者**：陈彦均（东阳市外国语学校）
- **GitHub**：[tgcz2011](https://github.com/tgcz2011)
- **个人主页**：[zztool.free.nf](https://zztool.free.nf)
- **邮箱**：tgcz2011@yeah.net（git 配置）
- **项目仓库**：[github.com/tgcz2011/morning-reading](https://github.com/tgcz2011/morning-reading)
- **问题反馈**：[GitHub Issues](https://github.com/tgcz2011/morning-reading/issues)（反馈时请注明页面底部的版本号）
- **技术支持**：豆包（Doubao）
