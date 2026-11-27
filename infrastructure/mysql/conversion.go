package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type ConversionImpl struct {
	modelRepo[entity.Conversion]
}

func NewConversionImpl(db *gorm.DB) repository.ConversionRepo {
	return &ConversionImpl{modelRepo: modelRepo[entity.Conversion]{db: db}}
}
