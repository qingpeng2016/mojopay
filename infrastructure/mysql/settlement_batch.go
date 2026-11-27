package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type SettlementBatchImpl struct {
	modelRepo[entity.SettlementBatch]
}

func NewSettlementBatchImpl(db *gorm.DB) repository.SettlementBatchRepo {
	return &SettlementBatchImpl{modelRepo: modelRepo[entity.SettlementBatch]{db: db}}
}
