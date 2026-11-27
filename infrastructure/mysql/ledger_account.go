package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type LedgerAccountImpl struct {
	modelRepo[entity.LedgerAccount]
}

func NewLedgerAccountImpl(db *gorm.DB) repository.LedgerAccountRepo {
	return &LedgerAccountImpl{modelRepo: modelRepo[entity.LedgerAccount]{db: db}}
}
