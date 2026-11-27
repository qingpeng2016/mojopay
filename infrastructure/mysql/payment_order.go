package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type PaymentOrderImpl struct {
	modelRepo[entity.PaymentOrder]
}

func NewPaymentOrderImpl(db *gorm.DB) repository.PaymentOrderRepo {
	return &PaymentOrderImpl{modelRepo: modelRepo[entity.PaymentOrder]{db: db}}
}
