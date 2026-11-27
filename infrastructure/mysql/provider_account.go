package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ProviderAccountImpl struct {
	modelRepo[entity.ProviderAccount]
}

func NewProviderAccountImpl(db *gorm.DB) repository.ProviderAccountRepo {
	return &ProviderAccountImpl{modelRepo: modelRepo[entity.ProviderAccount]{db: db}}
}
