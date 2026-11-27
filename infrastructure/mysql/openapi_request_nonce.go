package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type OpenAPIRequestNonceImpl struct {
	modelRepo[entity.OpenAPIRequestNonce]
}

func NewOpenAPIRequestNonceImpl(db *gorm.DB) repository.OpenAPIRequestNonceRepo {
	return &OpenAPIRequestNonceImpl{modelRepo: modelRepo[entity.OpenAPIRequestNonce]{db: db}}
}
