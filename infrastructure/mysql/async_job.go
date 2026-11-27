package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type AsyncJobImpl struct {
	modelRepo[entity.AsyncJob]
}

func NewAsyncJobImpl(db *gorm.DB) repository.AsyncJobRepo {
	return &AsyncJobImpl{modelRepo: modelRepo[entity.AsyncJob]{db: db}}
}
