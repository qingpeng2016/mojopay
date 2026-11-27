package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type AssetNetworkImpl struct {
	modelRepo[entity.AssetNetwork]
}

func NewAssetNetworkImpl(db *gorm.DB) repository.AssetNetworkRepo {
	return &AssetNetworkImpl{modelRepo: modelRepo[entity.AssetNetwork]{db: db}}
}
