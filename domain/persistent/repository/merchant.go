package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantRepo 提供 merchant 的持久化操作。
type MerchantRepo interface {
	ModelRepo[entity.Merchant]
}
