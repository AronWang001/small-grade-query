# Changelog

## 2026-07-27 — Initial open-source release

- 提供动态查询项目、动态查询条件和结果字段、前台查询日志以及敏感字段加密与HMAC精确匹配。
- 提供超级管理员与普通管理员、公开注册审核、用户名或手机号登录、IP防爆破、Session和CSRF防护。
- 提供Cloudflare Turnstile、SMTP注册邮箱验证码、系统设置和管理员审计日志。
- 提供Excel字段自动识别、导入预检、事务导入、模板下载、数据导出与项目永久删除。
- 提供查询创建向导、并发发布保护、草稿精准清理和Excel上传频率限制。
- 提供CLI安装、数据库迁移、烟雾测试、开源Release扫描和GitHub Actions安全检查。
- 开源副本不包含环境配置、运行日志、Excel、数据库备份、个人信息或任何部署环境密钥。
- 前台、认证页、退出页和后台页脚统一展示GitHub开源仓库地址。

## Security notes

- 依赖版本由 `composer.lock` 锁定，发布前执行Composer安全审计。
- 生产部署必须生成独立随机 `APP_KEY`，保持 `APP_DEBUG=0`，并保护 `.env`、`storage/` 和后台入口。
- 升级前应备份数据库和应用文件，并在独立环境中执行迁移与浏览器验收。
