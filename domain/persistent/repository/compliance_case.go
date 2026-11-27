package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ComplianceCaseRepo 提供 compliance_case 的持久化操作。
type ComplianceCaseRepo interface {
	ModelRepo[entity.ComplianceCase]
}
