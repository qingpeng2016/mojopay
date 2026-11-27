package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type PaymentReceiptImpl struct {
	modelRepo[entity.PaymentReceipt]
}

func NewPaymentReceiptImpl(db *gorm.DB) repository.PaymentReceiptRepo {
	return &PaymentReceiptImpl{modelRepo: modelRepo[entity.PaymentReceipt]{db: db}}
}
