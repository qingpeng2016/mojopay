package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantWebhookEndpointImpl struct {
	modelRepo[entity.MerchantWebhookEndpoint]
}

func NewMerchantWebhookEndpointImpl(db *gorm.DB) repository.MerchantWebhookEndpointRepo {
	return &MerchantWebhookEndpointImpl{modelRepo: modelRepo[entity.MerchantWebhookEndpoint]{db: db}}
}
