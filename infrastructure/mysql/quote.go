package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type QuoteImpl struct {
	modelRepo[entity.Quote]
}

func NewQuoteImpl(db *gorm.DB) repository.QuoteRepo {
	return &QuoteImpl{modelRepo: modelRepo[entity.Quote]{db: db}}
}
