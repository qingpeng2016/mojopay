# 本地运行指南（从零开始）

> 适合完全没有技术基础的同学。按照本文档一步一步操作，即可在自己电脑上启动后台，查询数据、操作系统。

---

## 目录

1. [准备工作](#1-准备工作)
2. [方法 A：Docker（推荐，最简单）](#2-方法-a-docker推荐最简单)
3. [方法 B：手动安装 PHP（备选）](#3-方法-b-手动安装-php备选)
4. [访问后台](#4-访问后台)
5. [导航到 RMPD 模块](#5-导航到-rmpd-模块)
6. [常见问题排查](#6-常见问题排查)

---

## 1. 准备工作

### 1.1 确认网络可以连接开发数据库

本项目连接的是公司开发环境的 MySQL 数据库服务器（`172.16.38.233`）。  
你的电脑需要能访问这台服务器，否则项目启动后会报数据库连接错误。

**确认方法：** 打开电脑终端（Windows 按 `Win+R` 输入 `cmd` 回车；Mac 搜索 Terminal），运行：

```bash
ping 172.16.38.233
```

如果看到类似 `Reply from 172.16.38.233` 或 `64 bytes from 172.16.38.233` 的回复，说明网络没问题。  
如果超时或 `Request timed out`，需要先连接公司 VPN 或在公司内网环境下操作。

### 1.2 获取代码

如果还没有项目代码，需要先克隆到本地：

```bash
git clone https://github.com/gph-tech/fg-marketmaker-mis.git
cd fg-marketmaker-mis
git checkout develop-ali
```

如果已经有代码，更新到最新版本：

```bash
cd fg-marketmaker-mis
git pull origin develop-ali
```

---

## 2. 方法 A：Docker（推荐，最简单）

**优点**：不需要单独安装 PHP、Composer 等，Docker 一键搞定所有环境依赖。  
**缺点**：需要安装 Docker Desktop，第一次构建镜像大约需要 5～10 分钟。

### 2.1 安装 Docker Desktop

**Windows 用户：**

1. 打开浏览器，访问 https://www.docker.com/products/docker-desktop/
2. 点击 **Download for Windows** 下载安装包
3. 双击安装包，全程点击 Next / Install，最后重启电脑
4. 重启后 Docker Desktop 会自动启动（任务栏右下角出现鲸鱼图标 🐋）

**Mac 用户：**

1. 打开浏览器，访问 https://www.docker.com/products/docker-desktop/
2. 根据你的 Mac 型号选择：
   - **M1/M2/M3 芯片**（Apple Silicon）：点击 **Download for Mac with Apple Chip**
   - **Intel 芯片**：点击 **Download for Mac with Intel Chip**
   - > 不确定芯片型号？点击左上角苹果图标 → 关于本机 → 查看"处理器"或"芯片"字样
3. 下载后打开 `.dmg` 文件，把 Docker 拖入 Applications 文件夹
4. 打开 Docker，按提示完成初始化

**验证安装成功：** 打开终端，运行：

```bash
docker --version
```

看到类似 `Docker version 26.x.x` 说明安装成功。

---

### 2.2 创建数据库配置文件

> 这一步是关键！项目通过 `conf/database-local.yaml` 文件来知道数据库在哪里、用什么账号密码。
> 这个文件不在 Git 仓库里（每个人在自己电脑上创建，不会被提交到 git）。

项目仓库里已经提供了填好的模板文件 `conf/database-local.yaml.template`，**直接复制改名即可**，不需要手动输入任何内容。

**Windows（PowerShell）：**

```powershell
Copy-Item conf\database-local.yaml.template conf\database-local.yaml
```

**Mac / Linux：**

```bash
cp conf/database-local.yaml.template conf/database-local.yaml
```

复制完成后，`conf/database-local.yaml` 就创建好了，**不需要修改任何内容**，配置已经填好。

---

### 2.3 构建 Docker 镜像

打开终端，进入项目根目录，运行：

```bash
docker build -f docker/Dockerfile \
  --build-arg APP_ENV=local \
  -t fg-marketmaker-mis:local .
```

> **Windows 用户注意**：如果上面的命令报错（多行命令在 CMD 里不支持），请改用 PowerShell 运行，或者把命令合并成一行：
> ```
> docker build -f docker/Dockerfile --build-arg APP_ENV=local -t fg-marketmaker-mis:local .
> ```

这一步会自动下载 PHP 8.2、安装所有依赖库，大约需要 5～10 分钟，请耐心等待。  
看到 `Successfully built` 或 `Successfully tagged` 说明构建成功。

---

### 2.4 启动容器

**Windows PowerShell（推荐）：**

```powershell
docker run -d --name fg-mis-local -p 8080:80 -v "${PWD}/conf:/var/www/html/conf" -v "${PWD}/runtime:/var/www/html/runtime" fg-marketmaker-mis:local
```

**Windows CMD：**

```cmd
docker run -d --name fg-mis-local -p 8080:80 -v "%cd%/conf:/var/www/html/conf" -v "%cd%/runtime:/var/www/html/runtime" fg-marketmaker-mis:local
```

**Mac / Linux：**

```bash
docker run -d \
  --name fg-mis-local \
  -p 8080:80 \
  -v "$(pwd)/conf:/var/www/html/conf" \
  -v "$(pwd)/runtime:/var/www/html/runtime" \
  fg-marketmaker-mis:local
```

参数说明：
- `-d`：后台运行（不占用终端窗口）
- `--name fg-mis-local`：给容器起个名字，方便后续管理
- `-p 8080:80`：把容器内部的 80 端口映射到本机的 8080 端口
- `-v .../conf:/var/www/html/conf`：把本地 conf 目录（含 database-local.yaml）挂载进容器，改配置不需要重新 build
- `-v .../runtime:/var/www/html/runtime`：把日志和缓存文件挂载到本地，方便排查问题

**验证启动成功：**

```powershell
docker ps
```

看到 `fg-mis-local` 且状态是 `Up` 说明容器正在运行。

---

### 2.5 管理容器

```bash
# 查看实时日志（排查错误时用）
docker logs -f fg-mis-local

# 停止容器
docker stop fg-mis-local

# 下次重新启动（不需要重新 build）
docker start fg-mis-local

# 删除容器（彻底清理）
docker rm -f fg-mis-local
```

跳到第 [4. 访问后台](#4-访问后台) 继续操作。

---

## 3. 方法 B：手动安装 PHP（备选）

如果不想用 Docker，可以直接在电脑上安装 PHP 环境。

### 3.1 Windows：安装 PHP 8.2

**推荐方式：下载官方 PHP 8.2**

1. 访问 https://windows.php.net/download/
2. 找到 **PHP 8.2** 部分，下载 **VS17 x64 Thread Safe** 的 `.zip` 版本
3. 解压到 `C:\php8.2\`
4. 把 `C:\php8.2\` 添加到系统 PATH 环境变量：
   - 右键"我的电脑" → 属性 → 高级系统设置 → 环境变量
   - 在"系统变量"里找到 `Path`，双击 → 新建 → 输入 `C:\php8.2`
   - 一路点确定

5. 启用必要的 PHP 扩展：
   - 用记事本打开 `C:\php8.2\php.ini-development`
   - 另存为 `C:\php8.2\php.ini`
   - 找到以下行，删掉前面的分号 `;`（分号是注释符，去掉才能启用）：
     ```
     ;extension=mbstring    → extension=mbstring
     ;extension=pdo_mysql   → extension=pdo_mysql
     ;extension=zip         → extension=zip
     ;extension=gd          → extension=gd
     ;extension=bcmath      → extension=bcmath
     ;extension=openssl     → extension=openssl
     ```
   - 保存文件

6. 验证安装：打开新的 CMD 窗口，运行：
   ```
   php --version
   ```
   看到 `PHP 8.2.x` 说明成功。

---

### 3.1 Mac：安装 PHP 8.2

**推荐方式：使用 Homebrew**

如果还没有 Homebrew，先安装（国内可能需要 VPN）：

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

安装 PHP 8.2：

```bash
brew install php@8.2
brew link php@8.2 --force
```

验证：

```bash
php --version
# 应该显示 PHP 8.2.x
```

---

### 3.2 安装 Composer（PHP 包管理器）

Composer 是 PHP 的依赖管理工具，相当于 Python 的 pip、Node 的 npm。

**Windows：**

1. 访问 https://getcomposer.org/download/
2. 下载并运行 **Composer-Setup.exe**
3. 安装过程中会自动检测 PHP 路径，一路 Next 即可

**Mac：**

```bash
brew install composer
```

验证安装：

```bash
composer --version
# 应该显示 Composer version 2.x.x
```

---

### 3.3 安装项目依赖

进入项目根目录，运行：

```bash
composer install
```

这会根据 `composer.json` 自动下载所有 PHP 依赖库到 `vendor/` 目录，大约需要 2～5 分钟。  
看到 `Generating optimized autoload files` 说明安装完成。

> **国内网络较慢时**，可以先配置国内镜像加速：
> ```bash
> composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
> ```
> 然后再运行 `composer install`。

---

### 3.4 创建数据库配置文件

同 [2.2 创建数据库配置文件](#22-创建数据库配置文件)，复制模板文件即可：

```bash
# Mac/Linux
cp conf/database-local.yaml.template conf/database-local.yaml
```

```powershell
# Windows PowerShell
Copy-Item conf\database-local.yaml.template conf\database-local.yaml
```

---

### 3.5 设置目录权限（Mac/Linux）

```bash
chmod -R 777 runtime/
```

Windows 跳过此步。

---

### 3.6 启动 PHP 内置开发服务器

```bash
php -S localhost:8080 public/router.php
```

看到类似以下输出说明服务已启动：

```
PHP 8.2.x Development Server (http://localhost:8080) started
```

**注意：** 这个终端窗口不能关闭，关闭后服务就停了。需要用另一个终端窗口操作其他命令。

---

## 4. 访问后台

### 4.1 打开浏览器

服务启动后，打开浏览器（推荐 Chrome），访问：

```
http://localhost:8080/admin.html
```

你会看到登录页面。

### 4.2 登录

输入账号密码登录。  
> 账号密码为开发环境后台账号，请向团队成员获取。通常超级管理员账号为 `admin`。

### 4.3 登录成功后

登录成功后你会进入管理后台首页，左侧有导航菜单。

---

## 5. 导航到 RMPD 模块

RMPD（策略交易系统）是本项目的核心模块，包含以下几个功能页面：

| 菜单项 | 功能说明 |
|--------|---------|
| **市场对审核** | 查看 AI 发现的套利市场对，进行人工审核（通过/拒绝） |
| **交易记录明细** | 查看每一笔套利交易的执行情况（价格、成交量、利润、状态） |
| **PNL 报表** | 综合盈亏仪表盘：收益率、趋势图、资金统计 |
| **AI 扫描日志** | 查看 AI 扫描 Polymarket 的历史调用记录和费用统计 |
| **风控配置** | 查看当前策略风控参数 |

在左侧菜单中找到 **RMPD** 或 **策略交易系统** 分组，点击展开即可看到上述子菜单。

**Bitfinex**：若已在主库执行 `scripts/bitfinex_menu_init.sql` 并完成 `php think clear`，侧边栏会出现 **Bitfinex** → **主页面板**（`accounts` 只读仪表盘；策略库连接 `db_bitfinex`，见 `app/bitfinex/docs/TECH.md`）。

### 5.1 市场对审核操作演示

1. 点击左侧 **市场对审核**
2. 默认显示"待复核"状态的市场对列表
3. 点击某一行右侧的 **通过** 或 **拒绝** 按钮，可以对单条记录审核
4. 勾选多条记录后，点击顶部 **批量通过** / **批量拒绝** 可批量操作
5. 使用顶部搜索框可按问题关键词、依赖类型、日期范围过滤

### 5.2 查看 PNL 报表

1. 点击左侧 **PNL 报表**
2. 页面顶部显示实时运营健康指标（监控中市场对数、单腿暴露等）
3. 第二行显示资金盈亏（总利润、投入资金、年化收益率、账户余额）
4. 中间的趋势图可切换 **7天 / 30天 / 90天** 视图
5. 底部按依赖类型（subset/implies/mutual_exclusive/equivalent）分组展示各类策略的利润和 ROI

---

## 6. 常见问题排查

### Q1：访问 localhost:8080 显示 502 或无法连接

**Docker 方法：**

```bash
# 检查容器是否在运行
docker ps -a

# 如果容器状态是 Exited，查看错误日志
docker logs fg-mis-local
```

**手动方法：**

- 检查终端里的 PHP 服务是否还在运行，没有就重新执行 `php -S localhost:8080 public/router.php`
- 确认端口 8080 没有被其他程序占用：
  - Mac/Linux：`lsof -i :8080`
  - Windows：`netstat -ano | findstr 8080`

---

### Q2：登录后报"数据库连接失败"或页面白屏

说明数据库配置有问题。检查步骤：

1. 确认 `conf/database-local.yaml` 文件存在，且内容格式正确（YAML 对缩进很敏感，不能用 Tab，只能用空格）
2. 确认能 ping 通 `172.16.38.233`（见第 1.1 节）
3. 查看日志文件：`runtime/log/database_config_YYYYMMDD.log`（把 YYYYMMDD 换成今天的日期），里面会显示实际用的数据库配置

---

### Q3：`composer install` 报错或很慢

- 切换国内镜像：
  ```bash
  composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
  composer install
  ```
- 如果报 PHP 扩展缺失，根据错误提示安装对应扩展（参见 3.1 节）

---

### Q4：Windows 下 YAML 文件格式错误

YAML 文件对格式要求很严格：
- **只能用空格缩进，不能用 Tab 键**
- 冒号后面必须有一个空格：`hostname: 127.0.0.1`（不能是 `hostname:127.0.0.1`）
- 建议用 VS Code 编辑，安装 YAML 插件可以自动检查格式

---

### Q5：Docker 镜像构建很慢

这是正常现象，第一次需要下载基础镜像和安装所有依赖（约 500MB+）。  
后续再次 build 会用缓存，速度会快很多。

如果网络太慢，可以先配置 Docker 国内镜像源：
1. 打开 Docker Desktop → Settings → Docker Engine
2. 在 JSON 配置中添加：
   ```json
   {
     "registry-mirrors": [
       "https://mirror.ccs.tencentyun.com",
       "https://hub-mirror.c.163.com"
     ]
   }
   ```
3. 点击 Apply & Restart

---

### Q6：如何更新代码后重新运行

**Docker 方法：**

```bash
git pull origin develop-ali

# 停止并删除旧容器
docker rm -f fg-mis-local

# 重新构建镜像（因为代码变了）
docker build -f docker/Dockerfile --build-arg APP_ENV=local -t fg-marketmaker-mis:local .

# 重新启动容器
docker run -d --name fg-mis-local -p 8080:80 \
  -v "$(pwd)/conf:/var/www/html/conf" \
  -v "$(pwd)/runtime:/var/www/html/runtime" \
  fg-marketmaker-mis:local
```

**手动方法：**

```bash
git pull origin develop-ali
composer install   # 如果 composer.json 有变化
# 重启 PHP 内置服务器（Ctrl+C 停止，然后重新运行）
php -S localhost:8080 public/router.php
```

---

## 附录：项目结构简介

```
fg-marketmaker-mis/
├── app/rmpd/              ← RMPD 策略交易模块（本项目主要功能）
│   ├── controller/        ← 业务逻辑（PHP 控制器）
│   ├── view/              ← 页面模板（HTML）
│   ├── const/Enum.php     ← 枚举常量定义
│   └── docs/TECH.md       ← RMPD 模块技术文档
├── app/bitfinex/          ← Bitfinex 策略模块（借贷/账户面板等）
├── conf/                  ← 环境配置（database-local.yaml 在这里创建）
├── public/                ← Web 根目录（浏览器访问的入口）
├── vendor/                ← PHP 依赖库（composer install 后生成）
├── runtime/               ← 运行时文件（日志、缓存，自动生成）
└── docker/                ← Docker 相关配置文件
```

---

*如有问题请联系开发团队。*
