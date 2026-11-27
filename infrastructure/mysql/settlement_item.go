package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type SettlementItemImpl struct {
	modelRepo[entity.SettlementItem]
}

func NewSettlementItemImpl(db *gorm.DB) repository.SettlementItemRepo {
	return &SettlementItemImpl{modelRepo: modelRepo[entity.SettlementItem]{db: db}}
}
