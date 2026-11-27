package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type OpenAPIRequestLogImpl struct {
	modelRepo[entity.OpenAPIRequestLog]
}

func NewOpenAPIRequestLogImpl(db *gorm.DB) repository.OpenAPIRequestLogRepo {
	return &OpenAPIRequestLogImpl{modelRepo: modelRepo[entity.OpenAPIRequestLog]{db: db}}
}
