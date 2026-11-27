package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// RefundOrderRepo 提供 refund_order 的持久化操作。
type RefundOrderRepo interface {
	ModelRepo[entity.RefundOrder]
}
