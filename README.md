# 成绩查询系统

原生 PHP 8.1+、PDO MySQL 和 PhpSpreadsheet 实现的动态查询项目系统。管理员可配置查询项目、查询条件、结果字段和数据，并查看人员查询次数、IP 与设备概况。

## 环境要求

- PHP 8.1 及以上
- PDO MySQL、Sodium、Mbstring、Fileinfo、Zip、OpenSSL 扩展
- MySQL 5.7 或 8.0
- Nginx 或 Apache
- Composer 2。开源Release不包含 `vendor/`，部署时需按锁文件安装依赖

## 首次安装

### 一键安装包

一键安装包已经包含锁定依赖。解压后在项目目录执行：

```bash
bash install.sh
```

Windows命令行可执行：

```bat
install.bat
```

安装程序会自动识别PHP-FPM工作进程用户，不允许把 `root` 当作PHP-FPM用户；无法自动识别时才会要求手工输入。随后依次询问数据库主机、端口、数据库名称、数据库用户名和密码，最后要求输入超级管理员用户名、密码及确认密码。密码输入不会显示；目标数据库不存在时会尝试创建，已存在时必须为空库。确认安装后才会写入 `.env` 和数据库。

Linux安装时，程序根目录会确保PHP-FPM组具有进入和读取权限；`.env` 会设置为安装者所有、PHP-FPM主组可读的 `0640`，`storage/` 会设置为同组可写的 `0770`。不要把 `.env` 改成全局可读的 `0644` 或 `0777`。

### 从源码安装

1. 安装锁定版本的生产依赖：

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

2. 复制 `.env.example` 为 `.env`，填写数据库连接并生成至少32字节的随机 `APP_KEY`。推荐格式为 `base64:<随机字节的Base64>`。
3. 确保 `storage/imports`、`storage/logs`、`storage/legacy` 对 PHP-FPM 用户可写，并禁止 Web 访问。
4. 仅通过 CLI 初始化：

   ```bash
   php tools/install.php
   ```

5. 如需迁移旧数据：

   ```bash
   php tools/migrate_json_to_mysql.php
   ```

   如果默认项目已有数据且确认需要覆盖，可使用 `--force`。迁移成功后，根目录旧 `data.json` 会移入受保护的 `storage/legacy`。

6. 非交互式 `tools/install.php` 仍使用安全随机数创建一次性临时密码，只在当前终端显示一次；首次登录后必须立即修改密码。交互式一键安装则使用安装者输入的超级管理员用户名和密码，不强制再次改密。重复执行安装程序不会重置已有管理员的密码、状态或角色。

默认 `admin` 的角色为“超级管理员”，状态为“正常”。普通管理员通过 `/register.php` 公开提交用户名、手机号、邮箱和密码，账号会以“待审核”状态保存，必须由超级管理员在“管理员账号”页面审核通过后才能登录。登录支持用户名或中国大陆手机号。

## 现有系统升级

升级前先备份网站文件和数据库，然后执行统一、可重复运行的迁移入口：

```bash
php tools/migrate.php
php tools/smoke_test.php
```

`tools/install.php` 与 `tools/migrate.php` 共用 `MigrationRunner`。迁移会保留成绩、查询项目、Excel批次、查询日志、原管理员密码哈希和登录记录；已记录的迁移会安全跳过。迁移 SQL 使用兼容 MySQL 5.7 与 8.0 的结构检查，不依赖 `ADD COLUMN IF NOT EXISTS`。

Cloudflare Turnstile 默认关闭。超级管理员可在“系统设置”中配置总开关、登录/注册子开关、Site Key、加密保存的 Secret Key、允许域名、主题和尺寸。数据库设置优先于 `.env` 默认值；后台不会把 Secret Key 回写 `.env`，也不会再次显示明文。

如果数据库中的 Turnstile Secret Key 无法使用当前 `APP_KEY` 解密，系统会将安全验证与注册邮箱验证码标记为“配置不可用”并安全拒绝相关请求，不会把异常误判成关闭状态，也不会绕过验证。超级管理员应在当前环境的“系统设置”中重新保存测试用 Site Key 和 Secret Key；不要跨环境复制密文或在日志中输出密钥。

## 独立空库安装验收

全新安装测试必须使用与当前业务库隔离的空数据库。维护者可以预先创建并授权一个以 `securitytest_` 开头、以 `_install_test` 结尾的数据库，然后执行：

```bash
php tools/test_fresh_install.php \
  --database=securitytest_fas_install_test \
  --allow-existing-empty
```

`--database securitytest_fas_install_test` 空格形式同样受支持。脚本会拒绝当前业务数据库、系统数据库、名称不符合规则的数据库以及任何非空数据库；默认保留维护者预创建的测试库。只有明确传入 `--drop-after-test` 时才会在复核安全名称后删除测试数据库。

## 目录结构

```text
├── admin.php                 # 后台兼容入口，仅加载控制器
├── admin_download.php        # Excel下载兼容入口
├── index.php                 # 前台查询入口
├── login.php / register.php  # 管理员认证入口
├── app/
│   ├── Controllers/          # 请求处理、权限检查和页面数据装配
│   ├── Repositories/         # PDO数据访问（含GradeRepository）
│   ├── Services/             # Excel、项目、认证与系统设置业务服务
│   ├── Support/              # 验证和通用异常
│   └── Views/
│       ├── auth/             # 登录与注册视图
│       └── admin/
│           ├── layout.php    # 后台公共布局
│           ├── pages/        # 概览、项目、数据、导入、日志、安全页面
│           └── components/   # 弹窗和可复用后台组件
├── assets/                   # 本地CSS和JavaScript
├── database/                 # 初始化结构和可重复迁移
├── deploy/                   # Web服务器保护规则
├── storage/                  # 受保护的日志、导入预检和历史数据
└── tools/                    # 安装、迁移、清理和烟雾测试CLI
```

公开URL保持不变。后台入口、控制器、页面模板和弹窗组件通过服务端 `require` 组合，业务权限、CSRF、事务和审计仍在原服务端流程中执行。

## Excel导入与项目删除

- 新建查询项目时只填写名称、说明、前台提示、状态和排序，项目标识由服务器使用日期与 `random_bytes()` 自动生成，编辑时保持不变。
- 无字段项目可以直接上传现有 Excel。系统读取第一个工作表第一行，自动生成字段标识、推断文本/数字/日期、识别敏感字段，并在管理员确认后以同一事务创建字段和导入数据。
- 已有字段项目上传时优先验证模板隐藏工作表中的字段身份，再按字段标识和字段名称匹配；新增列必须确认后创建，缺少旧列不会自动删除字段。
- 无字段项目下载“Excel填写示例”；已有字段项目下载包含 `_system_schema` 隐藏校验信息的当前项目模板。身份证号、手机号、学号和编号类列均使用文本格式。
- 项目归档保留全部历史。只有超级管理员可以在影响预览和三重确认后永久删除项目、字段、记录、字段值、查询日志和导入批次；管理员审计日志始终保留。

## Web服务器保护

- Apache 可使用项目根目录 `.htaccess`。
- Nginx 使用 `deploy/nginx-security.conf` 中的规则，并在重新加载前执行 `nginx -t`。
- 必须拒绝访问 `.env`、`data.json`、`app/`、`database/`、`storage/`、`tools/`、`vendor/` 和 `deploy/`。
- 普通反向代理地址或网段配置在 `TRUSTED_PROXIES`。启用 `TRUST_CLOUDFLARE_IP=1` 后，系统只在 `REMOTE_ADDR` 命中内置 Cloudflare 官方网段时读取 `CF-Connecting-IP`；非可信来源提交的转发头会被忽略。

## 运维命令

```bash
# 清理超过配置保留期的查询日志，默认180天
php tools/prune_query_logs.php

# 清理过期登录尝试、注册频率和历史封禁记录（登录自动解封不依赖本命令）
php tools/prune_auth_security.php

# 预览或执行查询向导草稿精准清理
php tools/prune_query_creation_drafts.php --dry-run
php tools/prune_query_creation_drafts.php --execute

# 预览或执行历史 Excel 上传限流记录清理
php tools/prune_excel_upload_attempts.php --dry-run
php tools/prune_excel_upload_attempts.php --execute

# 执行数据库结构、默认管理员、动态查询、加密和日志自检
php tools/smoke_test.php

# 检查全部一方PHP源码语法
find . -path ./vendor -prune -o -name '*.php' -print -exec php -l {} \;

# 检查当前运行副本是否含敏感或不应发布的文件
php tools/pre_open_source_check.php --runtime

# 检查独立脱敏Release目录（同时支持 --release /absolute/path）
php tools/pre_open_source_check.php --release=/absolute/path
```

Release 检查会递归扫描全部层级（排除 `vendor/`、`.git/` 和 `node_modules/`），阻断环境文件、运行日志、Excel/CSV、数据库备份、私钥和常见压缩/备份文件。发布包应从独立脱敏副本制作，不能直接打包正在运行的网站目录。

## 主要入口

- `/index.php`：动态查询页面
- `/login.php`：管理员登录
- `/register.php`：普通管理员注册申请
- `/admin.php`：管理后台
- `/logout.php`：安全退出确认

运行时全部业务数据保存在 MySQL 中，页面不会读取或写入 JSON 数据文件。敏感字段使用 Sodium 可逆加密，同时使用由 `APP_KEY` 派生的 HMAC 摘要支持精确查询。

## SMTP与注册邮箱验证码

SMTP通过 `.env` 中的 `MAIL_HOST`、`MAIL_PORT`、`MAIL_ENCRYPTION`、`MAIL_USERNAME`、`MAIL_PASSWORD`、`MAIL_FROM_ADDRESS` 和 `MAIL_FROM_NAME` 配置。不要把真实SMTP密码提交到Git。超级管理员只有在邮件服务和Turnstile均配置正常时，才能启用注册邮箱验证码。

## 安全与发布状态

- 不要提交 `.env`、真实Excel、CSV、日志、数据库备份、私钥或storage运行数据。
- 生产环境保持 `APP_DEBUG=0`，使用独立随机 `APP_KEY`，并限制 `.env` 与storage权限。
- 当前版本已通过静态检查、迁移测试和自动化安全检查。部署到正式环境前，建议维护者在独立空数据库和目标服务器环境中完成安装与浏览器验收。
- 安全问题请按 [SECURITY.md](SECURITY.md) 中的方式报告。

## 开源许可证

本项目使用 [MIT License](LICENSE)。
