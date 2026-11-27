package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// MerchantWebhookEndpointRepo 提供 merchant_webhook_endpoint 的持久化操作。
type MerchantWebhookEndpointRepo interface {
	ModelRepo[entity.MerchantWebhookEndpoint]
}
