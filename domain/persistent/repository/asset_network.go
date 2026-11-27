package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// AssetNetworkRepo 提供 asset_network 的持久化操作。
type AssetNetworkRepo interface {
	ModelRepo[entity.AssetNetwork]
}
