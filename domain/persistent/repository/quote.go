package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// QuoteRepo 提供 quote 的持久化操作。
type QuoteRepo interface {
	ModelRepo[entity.Quote]
}
