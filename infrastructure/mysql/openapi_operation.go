package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type OpenAPIOperationImpl struct {
	modelRepo[entity.OpenAPIOperation]
}

func NewOpenAPIOperationImpl(db *gorm.DB) repository.OpenAPIOperationRepo {
	return &OpenAPIOperationImpl{modelRepo: modelRepo[entity.OpenAPIOperation]{db: db}}
}
