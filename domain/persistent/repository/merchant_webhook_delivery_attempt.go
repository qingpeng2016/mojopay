package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantWebhookDeliveryAttemptRepo 提供 merchant_webhook_delivery_attempt 的持久化操作。
type MerchantWebhookDeliveryAttemptRepo interface {
	ModelRepo[entity.MerchantWebhookDeliveryAttempt]
}
