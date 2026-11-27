package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// OpenAPIOperationRepo 提供 openapi_operation 的持久化操作。
type OpenAPIOperationRepo interface {
	ModelRepo[entity.OpenAPIOperation]
}
