package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// PaymentReceiptRepo 提供 payment_receipt 的持久化操作。
type PaymentReceiptRepo interface {
	ModelRepo[entity.PaymentReceipt]
}
