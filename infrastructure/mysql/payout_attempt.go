package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type PayoutAttemptImpl struct {
	modelRepo[entity.PayoutAttempt]
}

func NewPayoutAttemptImpl(db *gorm.DB) repository.PayoutAttemptRepo {
	return &PayoutAttemptImpl{modelRepo: modelRepo[entity.PayoutAttempt]{db: db}}
}
