package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// OpenAPIRequestNonceRepo 提供 openapi_request_nonce 的持久化操作。
type OpenAPIRequestNonceRepo interface {
	AppendRepo[entity.OpenAPIRequestNonce]
	CleanupRepo
}
