package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ProviderAccountRepo 提供 provider_account 的持久化操作。
type ProviderAccountRepo interface {
	ModelRepo[entity.ProviderAccount]
}
