package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantAPIKeyImpl struct {
	modelRepo[entity.MerchantAPIKey]
}

func NewMerchantAPIKeyImpl(db *gorm.DB) repository.MerchantAPIKeyRepo {
	return &MerchantAPIKeyImpl{modelRepo: modelRepo[entity.MerchantAPIKey]{db: db}}
}
