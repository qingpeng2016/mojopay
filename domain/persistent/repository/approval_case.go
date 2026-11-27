package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ApprovalCaseRepo 提供 approval_case 的持久化操作。
type ApprovalCaseRepo interface {
	ModelRepo[entity.ApprovalCase]
}
