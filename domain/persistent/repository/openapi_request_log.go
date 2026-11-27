package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// OpenAPIRequestLogRepo 提供 openapi_request_log 的持久化操作。
type OpenAPIRequestLogRepo interface {
	AppendRepo[entity.OpenAPIRequestLog]
	CleanupRepo
}
