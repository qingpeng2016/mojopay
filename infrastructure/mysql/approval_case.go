package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ApprovalCaseImpl struct {
	modelRepo[entity.ApprovalCase]
}

func NewApprovalCaseImpl(db *gorm.DB) repository.ApprovalCaseRepo {
	return &ApprovalCaseImpl{modelRepo: modelRepo[entity.ApprovalCase]{db: db}}
}
