# 配置文件目录

此目录存放项目的配置文件（YAML 格式）

## 数据库配置文件

每个环境使用独立的配置文件：

- **database-local.yaml**: 本地开发环境
- **database-develop.yaml**: 开发环境  
- **database-prod.yaml**: 生产环境

### 环境自动切换

系统会根据 Git 分支自动选择对应的配置文件：

| Git 分支 | 配置文件 | 环境说明 |
|---------|---------|---------|
| main / master / production / prod | database-prod.yaml | 生产环境 |
| develop / dev / development | database-develop.yaml | 开发环境 |
| 其他分支 | database-local.yaml | 本地开发（默认） |

### 使用方式

1. 根据需要编辑对应环境的 YAML 文件
2. 配置会在 `config/database.php` 中自动加载
3. 某些动态值（如 `fields_cache`、`sqlite.database`）会在 PHP 中自动设置

### 配置示例

```yaml
mysql:
  type: mysql
  hostname: 127.0.0.1
  database: your_database
  username: your_username
  password: your_password
  hostport: 3306
  charset: utf8mb4
  # ... 其他配置项
```

### 优势

- ✅ 每个环境独立配置文件，互不干扰
- ✅ 配置与代码分离，便于管理
- ✅ YAML 格式更清晰易读
- ✅ 支持版本控制（可将敏感信息的文件加入 .gitignore）
- ✅ 便于团队协作和环境迁移

