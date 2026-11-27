package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type APIIdempotencyImpl struct {
	modelRepo[entity.APIIdempotency]
}

func NewAPIIdempotencyImpl(db *gorm.DB) repository.APIIdempotencyRepo {
	return &APIIdempotencyImpl{modelRepo: modelRepo[entity.APIIdempotency]{db: db}}
}
