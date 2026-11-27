package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// AssetRepo 提供 asset 的持久化操作。
type AssetRepo interface {
	ModelRepo[entity.Asset]
}
