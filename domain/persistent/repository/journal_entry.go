package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// JournalEntryRepo 提供 journal_entry 的持久化操作。
type JournalEntryRepo interface {
	AppendRepo[entity.JournalEntry]
}
