package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// APIIdempotencyRepo 提供 api_idempotency 的持久化操作。
type APIIdempotencyRepo interface {
	ModelRepo[entity.APIIdempotency]
	CleanupRepo
}
