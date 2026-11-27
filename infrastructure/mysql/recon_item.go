package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ReconItemImpl struct {
	modelRepo[entity.ReconItem]
}

func NewReconItemImpl(db *gorm.DB) repository.ReconItemRepo {
	return &ReconItemImpl{modelRepo: modelRepo[entity.ReconItem]{db: db}}
}
