package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type DisputeCaseImpl struct {
	modelRepo[entity.DisputeCase]
}

func NewDisputeCaseImpl(db *gorm.DB) repository.DisputeCaseRepo {
	return &DisputeCaseImpl{modelRepo: modelRepo[entity.DisputeCase]{db: db}}
}
