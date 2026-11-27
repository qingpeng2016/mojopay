package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// SettlementItemRepo 提供 settlement_item 的持久化操作。
type SettlementItemRepo interface {
	ModelRepo[entity.SettlementItem]
}
