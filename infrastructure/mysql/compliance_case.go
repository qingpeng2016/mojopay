package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ComplianceCaseImpl struct {
	modelRepo[entity.ComplianceCase]
}

func NewComplianceCaseImpl(db *gorm.DB) repository.ComplianceCaseRepo {
	return &ComplianceCaseImpl{modelRepo: modelRepo[entity.ComplianceCase]{db: db}}
}
