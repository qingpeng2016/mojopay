package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantUserImpl struct {
	modelRepo[entity.MerchantUser]
}

func NewMerchantUserImpl(db *gorm.DB) repository.MerchantUserRepo {
	return &MerchantUserImpl{modelRepo: modelRepo[entity.MerchantUser]{db: db}}
}
