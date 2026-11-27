package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ChannelCapabilityImpl struct {
	modelRepo[entity.ChannelCapability]
}

func NewChannelCapabilityImpl(db *gorm.DB) repository.ChannelCapabilityRepo {
	return &ChannelCapabilityImpl{modelRepo: modelRepo[entity.ChannelCapability]{db: db}}
}
