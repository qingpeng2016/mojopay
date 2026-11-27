package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ProviderImpl struct {
	modelRepo[entity.Provider]
}

func NewProviderImpl(db *gorm.DB) repository.ProviderRepo {
	return &ProviderImpl{modelRepo: modelRepo[entity.Provider]{db: db}}
}
