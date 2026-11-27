package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// ConversionRepo 提供 conversion 的持久化操作。
type ConversionRepo interface {
	ModelRepo[entity.Conversion]
}
