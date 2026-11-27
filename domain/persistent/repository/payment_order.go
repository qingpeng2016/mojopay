package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// PaymentOrderRepo 提供 payment_order 的持久化操作。
type PaymentOrderRepo interface {
	ModelRepo[entity.PaymentOrder]
}
