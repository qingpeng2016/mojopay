# Docker 部署说明

## 📦 构建镜像

### **开发环境镜像**
```bash
docker build -f docker/Dockerfile \
  --build-arg APP_ENV=develop \
  --build-arg DB_HOST=172.16.38.233 \
  --build-arg DB_PORT=3306 \
  --build-arg DB_DATABASE=mm \
  --build-arg DB_USERNAME=mm \
  --build-arg DB_PASSWORD=2kHjniEMBGh7rRWz \
  -t fg-marketmaker-mis:develop .
```

### **生产环境镜像**
```bash
docker build -f docker/Dockerfile \
  --build-arg APP_ENV=prod \
  --build-arg DB_HOST=172.16.38.233 \
  --build-arg DB_PORT=3306 \
  --build-arg DB_DATABASE=mm \
  --build-arg DB_USERNAME=mm \
  --build-arg DB_PASSWORD=your_prod_password \
  -t fg-marketmaker-mis:prod .
```

### **本地环境镜像（默认值）**
```bash
# 使用默认值构建
docker build -f docker/Dockerfile -t fg-marketmaker-mis:local .

# 或指定参数
docker build -f docker/Dockerfile \
  --build-arg APP_ENV=local \
  --build-arg DB_HOST=127.0.0.1 \
  --build-arg DB_PASSWORD=your_local_password \
  -t fg-marketmaker-mis:local .
```

---

## 🚀 运行容器

### **开发环境**
```bash
docker run -d \
  --name fg-marketmaker-dev \
  -p 8080:80 \
  -v $(pwd)/runtime:/var/www/html/runtime \
  fg-marketmaker-mis:develop
```

### **生产环境**
```bash
docker run -d \
  --name fg-marketmaker \
  -p 80:80 \
  -v $(pwd)/runtime:/var/www/html/runtime \
  fg-marketmaker-mis:prod
```

---

## 🎯 环境说明

| APP_ENV 值 | 读取配置文件 | 适用场景 | 密码来源 |
|-----------|------------|---------|---------|
| `local` | database-local.yaml | 本地开发 | YAML 文件 |
| `develop` | database-develop.yaml | 开发/测试 | 环境变量 |
| `prod` | database-prod.yaml | 生产环境 | 环境变量 |

---

## 🔐 环境变量配置（重要！）

### **开发/生产环境必须设置的环境变量**

```bash
# 在容器运行时设置
docker run -d \
  --name fg-marketmaker \
  -p 80:80 \
  -e DB_HOST=172.16.38.233 \
  -e DB_PORT=3306 \
  -e DB_DATABASE=mm \
  -e DB_USERNAME=mm \
  -e DB_PASSWORD=2kHjniEMBGh7rRWz \
  fg-marketmaker-mis:develop
```

### **K8s 部署（推荐使用 Secret）**

**创建 Secret（命令行方式）：**
```bash
kubectl create secret generic mysql-credentials \
  --from-literal=host='172.16.38.233' \
  --from-literal=port='3306' \
  --from-literal=database='mm' \
  --from-literal=username='mm' \
  --from-literal=password='2kHjniEMBGh7rRWz'
```

**或使用 YAML 方式：**
```yaml
apiVersion: v1
kind: Secret
metadata:
  name: mysql-credentials
type: Opaque
stringData:
  host: "172.16.38.233"
  port: "3306"
  database: "mm"
  username: "mm"
  password: "2kHjniEMBGh7rRWz"
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: fg-marketmaker-mis
spec:
  template:
    spec:
      containers:
      - name: app
        image: fg-marketmaker-mis:develop
        env:
          - name: APP_ENV
            value: "develop"
          # 数据库连接信息（从 Secret 读取）
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
```

---

## 常用命令

```bash
# 查看日志
docker logs -f fg-marketmaker

# 进入容器
docker exec -it fg-marketmaker bash

# 停止容器
docker stop fg-marketmaker

# 删除容器
docker rm fg-marketmaker

# 重启容器
docker restart fg-marketmaker
```

## 注意事项

- runtime 目录已设置 777 权限
- 生产环境不安装开发依赖（--no-dev）
- 自动优化自动加载（--optimize-autoloader）
- 容器内同时运行 Nginx + PHP-FPM

