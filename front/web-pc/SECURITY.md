# 安全配置说明

## 🔐 数据库密码安全管理

### **设计原则**

- **local 环境**：密码可以保存在 YAML 文件（本地开发，不上线）
- **develop/prod 环境**：密码**必须**通过环境变量设置（Docker/K8s 部署）

---

## 📋 配置文件说明

### **1. local 环境（本地开发）**

文件：`conf/database-local.yaml`

```yaml
password: '2kHjniEMBGh7rRWz'  # ✅ 可以写明文（本地用）
```

**说明：** 
- 本地开发使用，不会部署到服务器
- 可以添加到 `.gitignore`（可选）

---

### **2. develop/prod 环境（Docker 部署）**

文件：`conf/database-develop.yaml` / `conf/database-prod.yaml`

```yaml
password: ''  # ✅ 不保存密码，从环境变量读取
```

**说明：**
- YAML 中的 password 为空
- 实际密码通过 Docker/K8s 环境变量传入

---

## 🚀 运维配置方式

### **方式1：Docker 运行时设置（简单）**

```bash
docker run -d \
  -e APP_ENV=develop \
  -e DB_PASSWORD=2kHjniEMBGh7rRWz \
  fg-marketmaker-mis:develop
```

### **方式2：K8s Secret（推荐）**

```bash
# 1. 创建 Secret
kubectl create secret generic mysql-credentials \
  --from-literal=password='2kHjniEMBGh7rRWz' \
  --from-literal=username='mm' \
  --from-literal=host='172.16.38.233' \
  --from-literal=database='mm'

# 2. 在 Deployment 中引用
```

```yaml
env:
  - name: DB_PASSWORD
    valueFrom:
      secretKeyRef:
        name: mysql-credentials
        key: password
```

---

## 🔄 工作流程

### **本地开发**
```
local 环境 → 读取 database-local.yaml → 使用 YAML 中的密码 ✅
```

### **开发/生产部署**
```
develop/prod 环境 → 读取 database-xxx.yaml → 
发现 password 为空 → 从环境变量 DB_PASSWORD 读取 ✅
```

---

## ✅ 安全优势

| 项目 | 改进前 | 改进后 |
|-----|-------|-------|
| 密码存储 | ❌ 明文在 Git | ✅ 只在 K8s Secret |
| 代码泄露风险 | ❌ 高 | ✅ 低 |
| 密码修改 | ❌ 需要改代码提交 | ✅ 只改 Secret |
| 本地开发 | ✅ 方便 | ✅ 依然方便 |
| 多环境管理 | ❌ 统一密码 | ✅ 独立密码 |

---

## 📝 环境变量优先级

```
DB_PASSWORD 环境变量 > YAML 文件中的 password
```

**逻辑：**
```php
if ($dbPassword = getenv('DB_PASSWORD')) {
    $connections['mysql']['password'] = $dbPassword;  // 使用环境变量
} else {
    // 使用 YAML 中的值（local 环境）
}
```

---

## ⚠️ 注意事项

1. **开发/生产环境部署时，必须设置全部 5 个环境变量：**
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USERNAME`
   - `DB_PASSWORD`

2. **develop/prod 的 YAML 配置文件中所有敏感信息都是空字符串**
3. **local 环境的配置文件可以添加到 `.gitignore`（可选）**
4. **定期轮换密码**
5. **不要把 K8s Secret 内容提交到代码仓库**

---

## 📊 配置对比

| 环境 | YAML 文件内容 | 实际使用的值 |
|-----|-------------|------------|
| **local** | `hostname: 103.180.28.246`<br>`password: '2kHjniEMBGh7rRWz'` | YAML 文件中的值 ✅ |
| **develop** | `hostname: ''`<br>`password: ''` | 环境变量 ✅ |
| **prod** | `hostname: ''`<br>`password: ''` | 环境变量 ✅ |

