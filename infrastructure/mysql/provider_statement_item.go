package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ProviderStatementItemImpl struct {
	modelRepo[entity.ProviderStatementItem]
}

func NewProviderStatementItemImpl(db *gorm.DB) repository.ProviderStatementItemRepo {
	return &ProviderStatementItemImpl{modelRepo: modelRepo[entity.ProviderStatementItem]{db: db}}
}
