package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ChannelCapabilityRepo 提供 channel_capability 的持久化操作。
type ChannelCapabilityRepo interface {
	ModelRepo[entity.ChannelCapability]
}
