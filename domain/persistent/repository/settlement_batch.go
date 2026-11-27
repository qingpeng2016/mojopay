package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// SettlementBatchRepo 提供 settlement_batch 的持久化操作。
type SettlementBatchRepo interface {
	ModelRepo[entity.SettlementBatch]
}
