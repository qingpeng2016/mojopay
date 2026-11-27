package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type BeneficiaryImpl struct {
	modelRepo[entity.Beneficiary]
}

func NewBeneficiaryImpl(db *gorm.DB) repository.BeneficiaryRepo {
	return &BeneficiaryImpl{modelRepo: modelRepo[entity.Beneficiary]{db: db}}
}
