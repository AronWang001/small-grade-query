# 成绩查询系统

成绩查询系统是一套基于原生 PHP、MySQL、JavaScript 和 CSS 开发的动态信息查询系统，适用于学校、学院及其他教育机构发布成绩、排名、奖学金评定、第二课堂成绩等查询项目。

管理员可以通过 Excel 快速创建查询项目，系统会自动识别表头并生成查询字段。用户在前台选择查询项目并填写对应条件后，即可查看查询结果。系统同时提供管理员注册审核、权限管理、查询日志、敏感数据保护和移动端适配等功能。

项目仓库：[AronWang001/small-grade-query](https://github.com/AronWang001/small-grade-query)

## 主要功能

- 支持创建、编辑、启用、停用、归档和永久删除查询项目
- 支持项目专属访问地址和动态查询页面
- 支持根据项目配置动态生成查询条件和结果字段
- 支持文本、密码、数字、日期和下拉选择等字段类型
- 支持上传 Excel 自动识别表头、字段类型和敏感字段
- 支持 Excel 新增、更新或新增、覆盖全部三种导入模式
- 支持动态下载 Excel 填写示例和项目模板
- 支持项目数据导出、分页查看、搜索、编辑和批量删除
- 支持敏感字段加密保存、HMAC 精确查询和后台脱敏展示
- 支持记录查询次数、查询时间、IP、设备、系统和浏览器信息
- 支持超级管理员和普通管理员两种角色
- 支持管理员公开注册、邮箱验证码和超级管理员审核
- 支持用户名或手机号登录
- 支持登录错误次数限制和 IP 临时封禁
- 支持 Cloudflare Turnstile 登录、注册和邮箱验证码安全验证
- 支持管理员操作审计日志和登录安全记录
- 支持桌面端、平板和手机端响应式布局
- 支持将前台查询结果保存为 PNG 图片

## 安装方式

运行环境：

- Linux 服务器
- PHP 8.1 或更高版本
- MySQL 5.7 或 MySQL 8.0
- Nginx 或 Apache
- PHP 扩展：`pdo_mysql`、`sodium`、`mbstring`、`fileinfo`、`zip`、`openssl`

### 使用一键安装包

从项目的 [Releases](https://github.com/AronWang001/small-grade-query/releases) 页面下载：

```text
small-grade-query-v1.0.0-installer.sh
```

准备一个空的网站目录，将安装脚本单独放入该目录，然后执行：

```bash
chmod +x small-grade-query-v1.0.0-installer.sh
./small-grade-query-v1.0.0-installer.sh
```

安装程序会自动释放程序文件并询问：

1. 数据库主机和端口
2. 数据库名称
3. 数据库用户名和密码
4. 网站访问地址
5. PHP-FPM 运行用户
6. 超级管理员用户名和密码

安装目录必须为空，目标数据库必须为空库。宝塔面板的 PHP-FPM 运行用户通常为 `www`，不能填写 `root`。

程序释放完成后，一键安装脚本会自动删除。安装成功后访问：

```text
https://你的域名/login.php
```

### 从源码安装

克隆项目：

```bash
git clone https://github.com/AronWang001/small-grade-query.git
cd small-grade-query
```

安装锁定版本的生产依赖：

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

执行交互式安装：

```bash
chmod +x install.sh
./install.sh
```

安装程序会自动生成 `.env` 和安全的 `APP_KEY`、初始化数据库结构并创建超级管理员。

如需手工配置，可以复制环境配置示例：

```bash
cp .env.example .env
```

填写数据库连接并生成 APP_KEY 后执行：

```bash
php tools/install.php
```

后续升级数据库结构执行：

```bash
php tools/migrate.php
```
