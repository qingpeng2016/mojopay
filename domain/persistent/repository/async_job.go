package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// AsyncJobRepo 提供 async_job 的持久化操作。
type AsyncJobRepo interface {
	ModelRepo[entity.AsyncJob]
	CleanupRepo
}
