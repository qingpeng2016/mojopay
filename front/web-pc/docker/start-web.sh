#!/bin/sh
set -eu

# runtime 是独立 Docker volume，会覆盖镜像构建期创建的目录；必须在 PHP-FPM
# 启动前创建 ThinkAdmin 的分片模板缓存目录，避免首次登录写 cache/xx/<hash>.php 失败。
mkdir -p /var/www/html/runtime/cache /var/www/html/runtime/log \
  /var/www/html/runtime/mpdf_tmp /var/www/html/runtime/fund_weekly_statements
chown -R www-data:www-data /var/www/html/runtime
chmod -R u+rwX,g+rwX /var/www/html/runtime

# 运行时发布静态资源并执行数据库迁移。
# 构建阶段已用 --no-plugins --no-scripts 跳过安装钩子，框架服务由 app/service.php 显式注册，
# 这里在容器启动时补做；此时真实 DB 配置已由镜像内 conf/database-{env}.yaml + 环境变量注入就绪。
cd /var/www/html
php think xadmin:publish --migrate || { echo "[start-web] xadmin:publish --migrate 失败，详见上方日志"; exit 1; }

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
