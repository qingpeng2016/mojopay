# MojoPay web-pc (ThinkAdmin)

PHP 后台：`mojopay/front/web-pc`，基于 ThinkAdmin 多应用模式。

## 应用模块

- `app/admin/` — ThinkAdmin 框架（登录、权限、菜单）
- `app/index/` — 默认入口
- `app/common/` — 公共
- `app/mojopay/` — **唯一业务模块**（MojoPay 业务库 `db_mojopay`）

## 路由与菜单

- 后台 SPA：`/admin.html#/<app>/<controller_snake>/<action>.html`
- 菜单 SQL：`scripts/mojopay_menu_init.sql`（主库 `mysql` 连接上的 `system_menu`）

## 数据库

- `config/database.php`：按 Git 分支选 `conf/database-{env}.yaml`
- **local / test**：所有 MySQL 连接写死为 `mojopay/conf/local.conf.yaml` 中的 `db` 段
- 业务表在 `db_mojopay`；ThinkAdmin 系统表在默认连接 `mysql`