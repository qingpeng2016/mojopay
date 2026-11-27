# mojopay

## HTTP

```bash
go run .
curl -fsS http://127.0.0.1:8886/api/v1/test
```

HTTP 只注册 `GET /api/v1/test`。本地没有 `conf/local.conf.yaml` 时，默认监听 `127.0.0.1:8886`，启动 HTTP 无需 MySQL 或 Redis。可通过 `RUN_CONF` 或 `--run_conf` 选择 `conf/<环境>.conf.yaml`，监听地址取 `server.port`。

## Bot

在 `conf/local.conf.yaml` 配好 `server` 和 `db`，再启动 Bot：

```bash
go run . --run_conf=local --run_bot=true
```

调度器从 MySQL 的 `bot_schedule_config` 读取启用的 `ai_agent_paper / vip_level_sync` 配置，按 `interval_seconds` 调用现有脚本。脚本当前只输出完成日志。

```sql
INSERT INTO bot_schedule_config (module, task_name, interval_seconds, is_enabled, is_strategy_enabled)
VALUES ('ai_agent_paper', 'vip_level_sync', 60, 1, 1);
```
