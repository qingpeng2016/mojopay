package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type RefundOrderImpl struct {
	modelRepo[entity.RefundOrder]
}

func NewRefundOrderImpl(db *gorm.DB) repository.RefundOrderRepo {
	return &RefundOrderImpl{modelRepo: modelRepo[entity.RefundOrder]{db: db}}
}
