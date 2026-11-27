package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// PaymentReceipt 对应：一笔上游真实到账和确认记录。
type PaymentReceipt struct {
	ID                 uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID           string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	PaymentAttemptID   uint64          `gorm:"column:payment_attempt_id;type:bigint unsigned;not null"`                                       // 支付尝试主键
	MerchantAccountID  uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	ProviderAccountID  uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	ReceivedAssetID    uint64          `gorm:"column:received_asset_id;type:bigint unsigned;not null"`                                        // 实际到账资产主键
	NetworkID          *uint64         `gorm:"column:network_id;type:bigint unsigned"`                                                        // 资产网络主键
	SourceReceiptKey   string          `gorm:"column:source_receipt_key;type:varchar(200);not null"`                                          // 上游到账去重标识
	TxHash             *string         `gorm:"column:tx_hash;type:varchar(160)"`                                                              // 链上交易哈希
	TxOutputOrLogIndex *uint64         `gorm:"column:tx_output_or_log_index;type:bigint unsigned"`                                            // 链上输出序号或事件日志索引
	Amount             decimal.Decimal `gorm:"column:amount;type:decimal(36,0);not null"`                                                     // 实际到账金额
	Fee                decimal.Decimal `gorm:"column:fee;type:decimal(36,0);not null;default:0"`                                              // 收款通道手续费
	Status             string          `gorm:"column:status;type:varchar(24);not null;default:PROCESSING"`                                    // 到账确认状态
	ConfirmedAt        *time.Time      `gorm:"column:confirmed_at;type:datetime(6)"`                                                          // 到账确认时间
	CreatedAt          time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt          time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (PaymentReceipt) TableName() string { return "payment_receipt" }
