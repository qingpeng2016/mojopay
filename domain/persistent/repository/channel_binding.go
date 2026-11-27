package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ChannelBindingRepo 提供 channel_binding 的持久化操作。
type ChannelBindingRepo interface {
	ModelRepo[entity.ChannelBinding]
}
