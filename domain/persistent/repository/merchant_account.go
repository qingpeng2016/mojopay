package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantAccountRepo 提供 merchant_account 的持久化操作。
type MerchantAccountRepo interface {
	ModelRepo[entity.MerchantAccount]
}
