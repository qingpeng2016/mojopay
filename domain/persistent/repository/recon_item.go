package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ReconItemRepo 提供 recon_item 的持久化操作。
type ReconItemRepo interface {
	ModelRepo[entity.ReconItem]
}
