package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type PayoutOrderImpl struct {
	modelRepo[entity.PayoutOrder]
}

func NewPayoutOrderImpl(db *gorm.DB) repository.PayoutOrderRepo {
	return &PayoutOrderImpl{modelRepo: modelRepo[entity.PayoutOrder]{db: db}}
}
