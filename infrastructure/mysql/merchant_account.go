package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantAccountImpl struct {
	modelRepo[entity.MerchantAccount]
}

func NewMerchantAccountImpl(db *gorm.DB) repository.MerchantAccountRepo {
	return &MerchantAccountImpl{modelRepo: modelRepo[entity.MerchantAccount]{db: db}}
}
