package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantEventOutboxRepo 提供 merchant_event_outbox 的持久化操作。
type MerchantEventOutboxRepo interface {
	ModelRepo[entity.MerchantEventOutbox]
}
