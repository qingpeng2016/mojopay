package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantUserRepo 提供 merchant_user 的持久化操作。
type MerchantUserRepo interface {
	ModelRepo[entity.MerchantUser]
}
