package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type JournalImpl struct {
	modelRepo[entity.Journal]
}

func NewJournalImpl(db *gorm.DB) repository.JournalRepo {
	return &JournalImpl{modelRepo: modelRepo[entity.Journal]{db: db}}
}
