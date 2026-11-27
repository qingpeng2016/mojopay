package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ChannelBindingImpl struct {
	modelRepo[entity.ChannelBinding]
}

func NewChannelBindingImpl(db *gorm.DB) repository.ChannelBindingRepo {
	return &ChannelBindingImpl{modelRepo: modelRepo[entity.ChannelBinding]{db: db}}
}
