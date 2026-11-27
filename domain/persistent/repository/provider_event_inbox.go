package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ProviderEventInboxRepo 提供 provider_event_inbox 的持久化操作。
type ProviderEventInboxRepo interface {
	ModelRepo[entity.ProviderEventInbox]
}
