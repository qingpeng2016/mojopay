package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type PaymentAttemptImpl struct {
	modelRepo[entity.PaymentAttempt]
}

func NewPaymentAttemptImpl(db *gorm.DB) repository.PaymentAttemptRepo {
	return &PaymentAttemptImpl{modelRepo: modelRepo[entity.PaymentAttempt]{db: db}}
}
