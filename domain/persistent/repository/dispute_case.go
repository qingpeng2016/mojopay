package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// DisputeCaseRepo 提供 dispute_case 的持久化操作。
type DisputeCaseRepo interface {
	ModelRepo[entity.DisputeCase]
}
