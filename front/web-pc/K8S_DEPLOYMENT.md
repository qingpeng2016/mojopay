# Kubernetes 部署指南

## 🎯 环境变量配置（重要！）

由于 CI/CD 部署时没有 .git 目录，需要通过**环境变量**指定环境类型。

### **在 K8s Deployment 中添加环境变量**

#### **步骤1：创建 Secret（保存敏感信息）**
```bash
kubectl create secret generic mysql-credentials \
  --from-literal=host='172.16.38.233' \
  --from-literal=port='3306' \
  --from-literal=database='mm' \
  --from-literal=username='mm' \
  --from-literal=password='2kHjniEMBGh7rRWz'
```

#### **步骤2：Deployment 引用 Secret**
```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: fg-marketmaker-mis
spec:
  template:
    spec:
      containers:
      - name: app
        image: your-registry/fg-marketmaker-mis:latest
        env:
          # 环境类型
          - name: APP_ENV
            value: "develop"  # 可选值: local, develop, prod
          # 数据库连接（从 Secret 读取）
          - name: DB_HOST
            valueFrom:
              secretKeyRef:
                name: mysql-credentials
                key: host
          - name: DB_PORT
            valueFrom:
              secretKeyRef:
                name: mysql-credentials
                key: port
          - name: DB_DATABASE
            valueFrom:
              secretKeyRef:
                name: mysql-credentials
                key: database
          - name: DB_USERNAME
            valueFrom:
              secretKeyRef:
                name: mysql-credentials
                key: username
          - name: DB_PASSWORD
            valueFrom:
              secretKeyRef:
                name: mysql-credentials
                key: password
        ports:
        - containerPort: 80
        volumeMounts:
        - name: runtime
          mountPath: /var/www/html/runtime
      volumes:
      - name: runtime
        emptyDir: {}
```

---

## 📋 环境类型说明

| APP_ENV 值 | 读取的配置文件 | 适用场景 |
|-----------|--------------|---------|
| `local` | `conf/database-local.yaml` | 本地开发 |
| `develop` | `conf/database-develop.yaml` | 开发/测试环境 |
| `prod` | `conf/database-prod.yaml` | 生产环境 |

---

## 🚀 不同环境的部署示例

### **开发/测试环境**
```yaml
env:
  - name: APP_ENV
    value: "develop"
```

### **生产环境**
```yaml
env:
  - name: APP_ENV
    value: "prod"
```

---

## 🔍 调试日志

应用启动时会自动记录数据库配置信息：

### **查看配置日志**
```bash
# 方式1：查看文件日志
kubectl exec -it <pod-name> -- cat runtime/log/database_config_$(date +%Y%m%d).log

# 方式2：查看容器日志
kubectl logs <pod-name> | grep "DB-Config"

# 方式3：实时监控
kubectl exec -it <pod-name> -- tail -f runtime/log/database_config_*.log
```

### **日志内容示例**
```json
{
    "timestamp": "2025-10-30 18:00:00",
    "env_var_APP_ENV": "develop",
    "git_branch": "local",
    "final_environment": "develop",
    "yaml_file": "/var/www/html/conf/database-develop.yaml",
    "hostname": "172.16.38.233",
    "hostport": "3306",
    "database": "mm",
    "username": "mm",
    "password": "2kHjniEMBGh7rRWz",
    "dsn": "mysql:host=172.16.38.233;port=3306;dbname=mm;charset=utf8mb4"
}
```

---

## ⚠️ 常见问题

### **1. 未设置 APP_ENV 会怎样？**

- 会尝试读取 Git 分支
- 如果 .git 目录不存在，默认使用 `local` 环境
- 读取 `database-local.yaml`（可能导致配置错误）

### **2. 数据库连接失败**

检查步骤：
1. 确认已设置 `APP_ENV` 环境变量
2. 查看日志确认读取的配置文件
3. 检查对应 YAML 文件中的数据库地址
4. 确认 K8s Pod 能访问该数据库地址

### **3. 环境变量优先级**

```
APP_ENV 环境变量 > Git 分支检测 > 默认值(local)
```

---

## 📝 完整的 Deployment 配置示例

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: fg-marketmaker-mis
  namespace: default
spec:
  replicas: 2
  selector:
    matchLabels:
      app: fg-marketmaker-mis
  template:
    metadata:
      labels:
        app: fg-marketmaker-mis
    spec:
      containers:
      - name: app
        image: your-registry/fg-marketmaker-mis:latest
        imagePullPolicy: Always
        env:
          - name: APP_ENV
            value: "develop"  # 👈 根据实际环境修改
        ports:
        - containerPort: 80
          name: http
        livenessProbe:
          httpGet:
            path: /
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /
            port: 80
          initialDelaySeconds: 10
          periodSeconds: 5
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        volumeMounts:
        - name: runtime
          mountPath: /var/www/html/runtime
      volumes:
      - name: runtime
        emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: fg-marketmaker-mis
  namespace: default
spec:
  selector:
    app: fg-marketmaker-mis
  ports:
  - port: 80
    targetPort: 80
    name: http
  type: ClusterIP
```

---

## ✅ 部署检查清单

- [ ] 设置 `APP_ENV` 环境变量
- [ ] 确认对应的 YAML 配置文件存在
- [ ] 检查数据库地址是否可访问
- [ ] 查看部署日志确认配置正确
- [ ] 测试应用是否正常运行

