package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ProviderEventInboxImpl struct {
	modelRepo[entity.ProviderEventInbox]
}

func NewProviderEventInboxImpl(db *gorm.DB) repository.ProviderEventInboxRepo {
	return &ProviderEventInboxImpl{modelRepo: modelRepo[entity.ProviderEventInbox]{db: db}}
}
