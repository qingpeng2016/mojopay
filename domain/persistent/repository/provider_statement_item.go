package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ProviderStatementItemRepo 提供 provider_statement_item 的持久化操作。
type ProviderStatementItemRepo interface {
	AppendRepo[entity.ProviderStatementItem]
}
