package mysql

import (
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type MerchantWebhookDeliveryAttemptImpl struct {
	modelRepo[entity.MerchantWebhookDeliveryAttempt]
}

func NewMerchantWebhookDeliveryAttemptImpl(db *gorm.DB) repository.MerchantWebhookDeliveryAttemptRepo {
	return &MerchantWebhookDeliveryAttemptImpl{modelRepo: modelRepo[entity.MerchantWebhookDeliveryAttempt]{db: db}}
}
