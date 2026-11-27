package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// JournalRepo 提供 journal 的持久化操作。
type JournalRepo interface {
	AppendRepo[entity.Journal]
}
