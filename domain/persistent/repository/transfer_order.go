package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// TransferOrderRepo 提供 transfer_order 的持久化操作。
type TransferOrderRepo interface {
	ModelRepo[entity.TransferOrder]
}
