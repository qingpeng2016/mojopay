package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type JournalEntryImpl struct {
	modelRepo[entity.JournalEntry]
}

func NewJournalEntryImpl(db *gorm.DB) repository.JournalEntryRepo {
	return &JournalEntryImpl{modelRepo: modelRepo[entity.JournalEntry]{db: db}}
}
