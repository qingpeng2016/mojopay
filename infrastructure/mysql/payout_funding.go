package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type PayoutFundingImpl struct {
	modelRepo[entity.PayoutFunding]
}

func NewPayoutFundingImpl(db *gorm.DB) repository.PayoutFundingRepo {
	return &PayoutFundingImpl{modelRepo: modelRepo[entity.PayoutFunding]{db: db}}
}
