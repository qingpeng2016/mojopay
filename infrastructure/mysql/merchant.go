package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantImpl struct {
	modelRepo[entity.Merchant]
}

func NewMerchantImpl(db *gorm.DB) repository.MerchantRepo {
	return &MerchantImpl{modelRepo: modelRepo[entity.Merchant]{db: db}}
}
