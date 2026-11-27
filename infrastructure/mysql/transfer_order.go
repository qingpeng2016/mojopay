package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type TransferOrderImpl struct {
	modelRepo[entity.TransferOrder]
}

func NewTransferOrderImpl(db *gorm.DB) repository.TransferOrderRepo {
	return &TransferOrderImpl{modelRepo: modelRepo[entity.TransferOrder]{db: db}}
}
