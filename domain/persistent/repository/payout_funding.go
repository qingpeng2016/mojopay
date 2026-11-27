package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// PayoutFundingRepo 提供 payout_funding 的持久化操作。
type PayoutFundingRepo interface {
	ModelRepo[entity.PayoutFunding]
}
