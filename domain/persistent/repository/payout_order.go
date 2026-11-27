package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// PayoutOrderRepo 提供 payout_order 的持久化操作。
type PayoutOrderRepo interface {
	ModelRepo[entity.PayoutOrder]
}
