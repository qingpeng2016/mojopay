package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantEventOutboxImpl struct {
	modelRepo[entity.MerchantEventOutbox]
}

func NewMerchantEventOutboxImpl(db *gorm.DB) repository.MerchantEventOutboxRepo {
	return &MerchantEventOutboxImpl{modelRepo: modelRepo[entity.MerchantEventOutbox]{db: db}}
}
