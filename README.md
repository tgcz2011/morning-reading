# 班级朗读记录系统

一个轻量的 PHP + MySQL 多班级晨读 / 晚读打卡系统，供班干部在早读、晚读时段快速记录全班朗读情况，并按日 / 周 / 月 / 学期 / 总统计排行。

## 功能

### 多班级 · 多年级共用一套站点
- 登录 = **年级 + 班级号 + 密码**；登录界面有年级下拉（默认初三）
- 内置 **6 个年级**：初一、初二、初三、高一、高二、高三
- **初中每个年级 14 个班，高中每个年级 11 个班**，共 75 个班
- 初始密码 = `admin` + 两位班级号（一班 = `admin01`，十四班 = `admin14`）
- 同班号跨年级密码相同（如初一1班和初三1班都是 `admin01`）

### 两级管理
- **教师管理界面**（`admin.php`）：老师用「年级 + 班级号 + 教师管理密码」登录，只管理自己班级（学生名单增删改、Excel 批量导入、本班密码修改、清空本班记录）；教师管理密码与班级密码分开，初始相同
- **总管理界面**（`edit.php`）：相当于 superadmin，管理全部班级与全部老师（所有班级的两套密码、任意班学生名单、清空数据、早晚读时段设置）；**没有任何页面显示其入口**，纯背网址访问，登录使用 `config.php` 中的 `SUPERADMIN_PASSWORD`

### 记录规则
- 早读 / 晚读两种记录类型，仅在配置的时间段内开放操作（时段可在总管理界面修改）
- 学生卡片一键「+ 加分 / − 扣分」，点击即时反馈（波纹动画 + 底部提示），不等服务器回传；网络异常有明确提示
- **一次朗读时间最多加一分**（本次朗读已加过则锁定并弹出大字提示），**扣分不限次数**
- **加分优先抵扣已有扣分**：本周有负分时，加分不记正分，而是删除一条扣分记录并写一条抵消标记（不计入统计，但用于「已加分」锁定）
- **扣分优先抵消已有正分**：本周有正分时，扣分不记负分，而是把一条正分标记为已取消

### 状态显示
- 已记录卡片盖绿色「优秀」印章，有扣分的卡片盖红色「差」印章（整周显示）
- 本次朗读已加过分的卡片带「已加分」角标（放在姓名下方，不挤动姓名）
- 所有弹窗均为页面自绘大字弹窗（不用浏览器原生 alert/confirm），方便老师从远处看到

### 统计
- 统计页支持 **今日 / 本周 / 本月 / 学期 / 总统计**
- 本周显示净分（加分 − 扣分）；今日 / 本月 / 学期 / 总统计按正分次数计、不计负分
- 学期按配置的寒暑假结束时间分割
- 正分分布饼状图（前 10 名 + 其他）
- 排行榜显示排名、学号、姓名、分数、占比条

### 登录与安全
- **单会话登录**：同一个班级同时只允许一个记录会话；另一台设备登录后，旧会话立即失效（操作时返回「已在其他设备登录」并跳转，心跳兜底检测）
- **登录有效期**：记录页 / 统计页 3 小时，教师管理页 7 天；到期自动跳转登录页
- **浏览器端倒计时**：页面打开时从服务器取剩余时间，用 `performance.now()` 单调时钟倒计时（不受系统时间修改影响），到期立即跳转；低频心跳兜底检测服务器端提前过期
- 学生姓名以 base64 存储于 `name_encoded` 列，读取时自动解码显示，数据库字符集不支持中文也不影响
- 图表使用本地 Chart.js（无需访问 CDN，校园网友好）

## 界面

「纸墨 · 印章」主题：米白纸面（#F2EDE0）、墨黑通栏（#26241F）、朱红印章点缀（#C43A2C）、松绿（#2F6B4F）；已记录卡片盖绿色「优秀」印章，有扣分的卡片盖红色「差」印章，移动端自适应。

## 技术栈

- **后端**：PHP 8.x（PDO），无框架，单文件多页面
- **数据库**：MySQL / MariaDB（InnoDB，utf8mb4）
- **前端**：原生 HTML / CSS / JavaScript，无构建工具
- **图表**：Chart.js 4.4.9（本地文件，不依赖 CDN）
- **Excel 解析**：SimpleXLSX（MIT License，单文件无依赖）

## 目录结构

```
├── index.php          # 班级登录 + 记录页（加分/扣分，3小时过期）
├── stats.php          # 统计页（今日/本周/本月/学期/总统计，3小时过期）
├── admin.php          # 教师管理页（本班名单/密码/清空/API文档，7天过期）
├── edit.php           # 总管理页（superadmin，所有班级/时段设置，无入口链接）
├── api.php            # HTTP API（Token 认证，全功能端点）
├── heartbeat.php      # 心跳接口（前端定时调用，检测登录是否过期/被踢）
├── config.php         # 生产配置（数据库凭据+总管理密码，不入库）
├── config.example.php # 配置模板（入库，部署时复制为 config.php）
├── functions.php      # 核心业务逻辑（鉴权/记录/统计/导入/时段）
├── style.css          # 纸墨印章主题样式
├── chart.umd.min.js   # Chart.js 本地文件
└── simplexlsx.php     # SimpleXLSX Excel 解析库
```

## 数据库表结构

| 表 | 说明 | 关键字段 |
|---|---|---|
| `classes` | 班级 | id, grade(7初一~12高三), class_number, password, teacher_password, active_session_token |
| `students` | 学生 | id, class_id, student_no, name_encoded(base64) |
| `reading_records` | 朗读记录（加分） | id, class_id, student_id, record_type(morning/evening), record_date, is_canceled, week_number, month_number |
| `penalty_records` | 扣分记录 | id, class_id, student_id, week_number, penalty_time, record_type |
| `weekly_stats` | 周统计（主键 class_id+student_id+week_number） | morning_count, evening_count, penalty_count |
| `settings` | 系统设置（键值对） | morning_start, morning_end, evening_start, evening_end, db_version |

索引：`reading_records` 有唯一索引 `(class_id, student_id, record_date, record_type)`，数据库层面防止同一学生同一天同一时段重复加分。

## 部署

### 环境要求
- PHP 8.x + PDO 扩展 + pdo_mysql
- MySQL 5.7+ / MariaDB 10.2+（InnoDB）
- 需先在数据库服务商处建好数据库（数据库名、用户名、密码）

### 步骤
1. 复制配置模板并填写真实信息：
   ```bash
   cp config.example.php config.php
   ```
2. 在 `config.php` 中填写：
   - `DB_HOST` / `DB_PORT` / `DB_USER` / `DB_PASS` / `DB_NAME`：数据库连接
   - `SUPERADMIN_PASSWORD`：总管理界面（edit.php）登录密码
   - `CLASS_COUNT`：初中每班数量（默认 14，高中固定 11）
   - `$semester_starts`：各学期开学日期（用于学期统计分割）
3. 将全部文件上传到站点目录
4. 访问 `index.php`，首次访问会自动建表并创建 75 个班（初中各14 + 高中各11）
5. 登录记录页：选年级 → 填班级号 → 输入班级密码（初始 `admin` + 两位班号）
6. 登录教师管理：`admin.php`，年级 + 班级号 + 教师管理密码（初始同班级密码）
7. 总管理：直接访问 `edit.php`，输入 `SUPERADMIN_PASSWORD`

### 升级
- 覆盖文件后访问任意页面，`initDatabase()` 会自动执行迁移（加列、加索引、补建班级），通过 `db_version` 控制，幂等可重复执行
- 迁移不会删除现有数据

## 批量导入学生名单（Excel）

- 教师管理 / 总管理的「学生名单」页：下载 Excel 模板 → 填写（**第一列学号，第二列姓名**，可含表头行）→ 上传 .xlsx → 解析预览 → 确认导入
- **学号已存在的自动覆盖**（更新姓名，保留学号和历史记录）；文件内重复学号后者覆盖前者
- 坏行（空姓名、非数字学号等）自动跳过并提示；兼容 .csv 文件
- 模板下载无需登录，直接访问 `admin.php?action=download_template`
- xlsx 解析使用 [SimpleXLSX](https://github.com/shuchkin/simplexlsx)（MIT License，单文件无依赖）

## API 接口

系统提供 HTTP API（`api.php`），可用脚本或程序调用记录页与教师管理页的全部操作。API 文档在**教师管理页 →「API 接口」**标签页可见（登录后自动展示当前班级的 Token）。

### 认证（三步）

```
① 获取种子：GET api.php?action=get_seed&identity=record|teacher|superadmin   （无需登录）
② 计算 token：token = sha256(用户名 . ':' . 密码 . ':' . 种子)
③ 请求：Authorization: Bearer <token>   或   ?token=<token>
```

- 种子由服务端生成（按小时轮换），**同一时刻三种身份的种子互不相同**；密码和种子永不通过网络传输，中间人最多拿到当小时有效的 token
- 验证窗口为当前小时 ± 1 小时，客户端本地时间不准也能用（种子由服务器下发，天然校准）
- 修改 `API_SEED`（config.php）会使全校所有 token 立即失效

**三种身份**（权限从低到高）：

| 身份 | 用户名 | 密码 | 可用操作 |
|------|--------|------|----------|
| record 班级记录 | 年级-班号（如 `9-6`） | 班级登录密码 | 查名单/统计、加分、取消、扣分 |
| teacher 教师管理 | 年级-班号（如 `9-6`） | 教师管理密码 | 全部端点（本班） |
| superadmin 总管理 | `superadmin` | 总管理密码 | 全部端点 + 任意班级（需 `grade_class=9-6`） |

```
# 示例：教师身份取种子并计算 token（bash）
SEED=$(curl -s ".../api.php?action=get_seed&identity=teacher" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['seed'])")
TOKEN=$(printf '%s' "9-6:教师管理密码:$SEED" | sha256sum | cut -d' ' -f1)

curl ".../api.php?username=9-6&action=students" -H "Authorization: Bearer $TOKEN"

> **curl 拿到挑战页？** InfinityFree 免费托管对非浏览器请求注入 JS 挑战页（浏览器自动执行所以网页无感）。
> 服务器端无法在源码里关闭（平台层防护，免费套餐强制）。用项目自带的自动客户端即可完全无感调用：

```
# api_client.py：纯 Python 标准库、零依赖，自动完成挑战 + 自动取种子算 token
python3 api_client.py --identity teacher   --user 9-6 --pass 教师密码 verify_token   # 验证 token 是否有效
python3 api_client.py --identity record    --user 9-6 --pass 班级密码 students       # 学生名单
python3 api_client.py --identity record    --user 9-6 --pass 班级密码 stats --period week
python3 api_client.py --identity record    --user 9-6 --pass 班级密码 add_record --student-no 3
python3 api_client.py --identity superadmin --user superadmin --pass 总管理密码 status --grade-class 8-3
```

其他语言调用只需两步：① 请求带浏览器 UA 时第一次遇到挑战页，用挑战页中的 `c=toNumbers("...")`（配合页内明文给出的 AES 密钥/IV）解出 `__test` cookie；② 带该 cookie 重访同 URL 并附加 `?i=1`。cookie 有效期 6 小时，同 IP 内复用即可。
```

### 端点

| 方法 | action | 参数 | 最低身份 | 说明 |
|------|--------|------|----------|------|
| GET | `get_seed` | `identity=record/teacher/superadmin` | 无需登录 | 获取当前小时种子 |
| GET | `verify_token` | — | record | 验证 token 是否有效（返回 `valid`/身份/剩余有效期；无效返回 200 + `valid=false`） |
| GET | `status` | — | record | 班级信息、当前时段、是否可记录、Token 时槽、身份 |
| GET | `students` | — | record | 学生名单（学号/姓名/今日早读晚读/周得分/已加分） |
| GET | `stats` | `period=day/week/month/semester/total` | record | 统计排行 |
| POST | `add_record` | `student_no`（可选 `type=morning/evening`） | record | 加分（一次最多加一分，自动抵消负分） |
| POST | `cancel_record` | `student_no`（可选 `type`） | record | 取消本次记录 |
| POST | `penalize` | `student_no` | record | 扣分（不限次数，优先抵消正分） |
| POST | `add_student` | `student_no`, `name` | teacher | 添加学生 |
| POST | `update_student` | `student_no`, `name`（可选 `new_no`） | teacher | 修改学生 |
| POST | `delete_student` | `student_no` | teacher | 删除学生（含其全部记录） |
| POST | `import_students` | JSON body 或文本行 | teacher | 批量导入（冲突覆盖） |
| POST | `clear_data` | — | teacher | 清空记录（保留名单） |
| POST | `clear_all_data` | — | teacher | 清空全部（含名单） |

错误响应统一为 `{"success": false, "code": <HTTP>, "message": "..."}`，认证失败返回 HTTP 401。

### 网页免登录（Token 直链）

三个页面支持「带 token 直接打开即登录」，链接由管理页一键生成（教师页 API 文档页 / 总管理页顶部），无需输密码：

| 页面 | 链接形式 | 需要的 token 身份 |
|------|----------|-------------------|
| 记录页 `index.php` | `?username=9-6&token=<64位hex>&t=<生成时刻>` | record（班级登录密码） |
| 教师页 `admin.php` | 同上 | teacher（教师管理密码） |
| 总管理页 `edit.php` | `?username=superadmin&token=<64位hex>&t=<生成时刻>` | superadmin（总管理密码） |

- 三种身份用户名/密码/种子均不同 → token 互不相同；身份不足会被拒绝（如 record token 打不开教师页）。
- `t` 为链接生成时刻（Unix 时间戳），**链接自生成起 2 小时有效**，过期或 t 被篡改（未来时间）一律回到登录页。
- 验证通过后页面会用与表单登录完全一致的会话登录并跳转（302 清掉 URL 中的 token），浏览器地址栏不残留 token。
- 链接等于对应身份的密码，请勿外传；页面内生成入口均带有警示文案。

## 安全注意事项

- `config.php` 含真实数据库凭据和总管理密码，已被 `.gitignore` 排除，**禁止提交到公开仓库**；公开仓库只保留 `config.example.php` 模板
- 总管理界面 `edit.php` 没有任何入口链接，靠保密性降低被访问概率，但仍应设置强密码
- 单会话登录防止两个老师同时记录同一班级；如需多人同时记录，请使用不同班级账号
- 学生姓名 base64 存储不是加密，仅为规避数据库字符集限制；不要在姓名中存储敏感信息

## 常见问题

**Q: 登录后提示「已在其他设备登录」？**
A: 同一个班级同时只允许一个记录会话。另一台设备登录后，旧会话失效。在旧设备上重新登录即可（会踢掉新设备的会话）。

**Q: 记录页打开很久后加不上分？**
A: 记录页登录有效期 3 小时，到期自动跳转登录页。如果页面打开超过 3 小时，重新登录即可。

**Q: 加分后统计页没有显示？**
A: 加分优先抵扣本周已有扣分。如果该生本周有负分，加分不会记为正分，而是抵消一条负分。本周统计显示净分（加分 − 扣分），今日/本月/学期/总统计只计正分次数。

**Q: 如何修改早晚读时间段？**
A: 访问总管理界面 `edit.php`，在「时段设置」中修改。修改后记录页和后台接口立即生效。

**Q: 数据库不支持中文怎么办？**
A: 学生姓名以 base64 存储于 `name_encoded` 列，读取时自动解码，数据库字符集不支持中文也不影响。

## 版本号规则

`a.b.c.d`：d=小改动/修复，c=小添加，b=大改，a=大添加；去掉点后数值严格递增。

当前版本：**v1.0.0.0**（首个正式版：多班级多年级、单会话登录、Excel 批量导入、统计排行、时段可配置）。

## 作者与鸣谢

- **作者**：陈彦均（东阳市外国语学校）
- **技术支持**：豆包（Doubao）—— 提供全栈开发与代码审查支持
- **GitHub**：[tgcz2011](https://github.com/tgcz2011)
- **个人主页**：[zztool.free.nf](https://zztool.free.nf)

## 问题反馈

- **GitHub Issues**：[tgcz2011/morning-reading/issues](https://github.com/tgcz2011/morning-reading/issues)
- 反馈时请注明版本号（页面底部可见）、操作步骤和错误现象。

## License

GPL-3.0，见 [LICENSE](LICENSE)。
