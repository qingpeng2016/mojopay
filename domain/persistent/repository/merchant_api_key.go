package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantAPIKeyRepo 提供 merchant_api_key 的持久化操作。
type MerchantAPIKeyRepo interface {
	ModelRepo[entity.MerchantAPIKey]
}
