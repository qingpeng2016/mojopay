package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ProviderRepo 提供 provider 的持久化操作。
type ProviderRepo interface {
	ModelRepo[entity.Provider]
}
