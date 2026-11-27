package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// BeneficiaryRepo 提供 beneficiary 的持久化操作。
type BeneficiaryRepo interface {
	ModelRepo[entity.Beneficiary]
}
