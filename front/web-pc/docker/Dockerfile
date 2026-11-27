FROM php:8.2.35-fpm

# 安装系统依赖、Python 及 PHP 扩展（gd/mbstring/bcmath 供 dompdf/mpdf 周账单 PDF）
# cron：周账单 bitfinex:weekly-statement 定时任务
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    cron \
    util-linux \
    python3 \
    python3-pip \
    python3-requests \
    python3-dateutil \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*


# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . /var/www/html

# 安装 PHP 依赖
# COMPOSER_NO_PLUGINS 不是 Composer 支持的环境变量；必须使用 --no-plugins。
# think-install 会在 post-autoload-dump 中启动框架并迁移数据库，--no-scripts 单独使用也无法阻止。
# 构建时禁用插件和脚本，框架服务由 app/service.php 显式注册；
# 静态资源发布与数据库迁移由运行时 docker/start-web.sh 执行。
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-plugins --no-scripts

# 设置工作目录
# WORKDIR /var/www/html

# 清空工作目录，确保不会有旧文件残留
# RUN rm -rf /var/www/html/* /var/www/html/.* 2>/dev/null || true


# 复制项目文件
COPY . /var/www/html

# 无数据库验证框架服务注册及自定义应用类的自动加载优先级。
RUN php scripts/verify_composer_build.php

# 查看 Login.php 的 MD5
RUN if [ -f /var/www/html/app/admin/controller/Login.php ]; then \
        md5sum /var/www/html/app/admin/controller/Login.php; \
    else \
        echo "Login.php 文件不存在"; \
    fi


# 设置权限 + PDF 运行时目录（周账单 dompdf/mpdf）
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/runtime/mpdf_tmp \
    && mkdir -p /var/www/html/runtime/fund_weekly_statements \
    && mkdir -p /var/www/html/runtime/log \
    && chmod -R 777 /var/www/html/runtime

# 构建时校验：预置 Sun-ExtA 字体 + dompdf（mpdf 可选）
RUN test -f /var/www/html/app/bitfinex/resource/dompdf_fonts/installed-fonts.json \
    && test -f /var/www/html/app/bitfinex/resource/dompdf_fonts/sun_exta_normal_1d94f97103e142360bd043e770c5efb1.ttf \
    && php -r "require '/var/www/html/vendor/autoload.php'; exit(class_exists('Dompdf\\\\Dompdf') ? 0 : 1);" \
    && echo 'PDF deps OK (dompdf + dompdf_fonts)'

# 查看 Login.php 的 MD5
RUN if [ -f /var/www/html/app/admin/controller/Login.php ]; then \
        md5sum /var/www/html/app/admin/controller/Login.php; \
    else \
        echo "Login.php 文件不存在"; \
    fi

# 复制 Nginx 配置
COPY docker/nginx.dev.conf /etc/nginx/sites-available/default

# 查看 Login.php 的 MD5
RUN if [ -f /var/www/html/app/admin/controller/Login.php ]; then \
        md5sum /var/www/html/app/admin/controller/Login.php; \
    else \
        echo "Login.php 文件不存在"; \
    fi


# Supervisor 配置
RUN echo '[supervisord]\n\
nodaemon=true\n\
\n\
[program:php-fpm]\n\
command=/usr/local/sbin/php-fpm\n\
autostart=true\n\
autorestart=true\n\
\n\
[program:nginx]\n\
command=/usr/sbin/nginx -g "daemon off;"\n\
autostart=true\n\
autorestart=true' > /etc/supervisor/conf.d/supervisord.conf

# 追加 cron 到 Supervisor（周账单定时，不改动上面原有 php-fpm/nginx）
RUN printf '\n\
[program:cron]\n\
command=/var/www/html/docker/start-cron.sh\n\
autostart=true\n\
autorestart=true\n' >> /etc/supervisor/conf.d/supervisord.conf

# 周账单 crontab（每 5 分钟尝试生成上一 UTC 周 PDF）
COPY docker/crontab /etc/cron.d/fg-bitfinex-weekly-statement
RUN chmod 0644 /etc/cron.d/fg-bitfinex-weekly-statement \
    && sed -i 's/\r$//' /var/www/html/docker/*.sh \
    && chmod +x /var/www/html/docker/cron-weekly-statement.sh /var/www/html/docker/start-cron.sh \
    && touch /var/www/html/runtime/log/weekly_statement_cron.log \
    && chown www-data:www-data /var/www/html/runtime/log/weekly_statement_cron.log


# 查看 Login.php 的 MD5
RUN if [ -f /var/www/html/app/admin/controller/Login.php ]; then \
        md5sum /var/www/html/app/admin/controller/Login.php; \
    else \
        echo "Login.php 文件不存在"; \
    fi

# 暴露端口
EXPOSE 80

# 启动服务
RUN chmod +x /var/www/html/docker/start-web.sh

CMD ["/var/www/html/docker/start-web.sh"]
