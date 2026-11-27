package entity

import (
	"time"

	"github.com/shopspring/decimal"
	"gorm.io/datatypes"
)

// PaymentOrder 对应：商户支付订单及固定结算资产。
type PaymentOrder struct {
	ID                      uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID                string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID       uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	MerchantID              uint64          `gorm:"column:merchant_id;type:bigint unsigned;not null"`                                              // 所属商户主键
	MerchantUserID          *uint64         `gorm:"column:merchant_user_id;type:bigint unsigned"`                                                  // 商户最终用户主键
	OrderAssetID            uint64          `gorm:"column:order_asset_id;type:bigint unsigned;not null"`                                           // 订单标价资产主键
	RequestedPaymentAssetID *uint64         `gorm:"column:requested_payment_asset_id;type:bigint unsigned"`                                        // 商户指定的付款资产主键
	SettlementAssetID       uint64          `gorm:"column:settlement_asset_id;type:bigint unsigned;not null"`                                      // 商户结算资产主键
	QuoteID                 *uint64         `gorm:"column:quote_id;type:bigint unsigned"`                                                          // 报价记录主键
	MerchantOrderNo         string          `gorm:"column:merchant_order_no;type:varchar(128);not null"`                                           // 商户订单号
	OrderAmount             decimal.Decimal `gorm:"column:order_amount;type:decimal(36,0);not null"`                                               // 订单标价金额
	Status                  string          `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 支付订单状态
	Origin                  string          `gorm:"column:origin;type:varchar(16);not null;default:API"`                                           // 建单方式（接口或人工）
	Metadata                *datatypes.JSON `gorm:"column:metadata;type:json"`                                                                     // 订单扩展信息
	ExpiresAt               time.Time       `gorm:"column:expires_at;type:datetime(6);not null"`                                                   // 支付订单到期时间
	PaymentURL              *string         `gorm:"column:payment_url;type:varchar(512)"`                                                            // 付款页 URL（收银台链接）
	CheckoutSessionID       *string         `gorm:"column:checkout_session_id;type:varchar(64)"`                                                     // 收银台会话 ID
	CheckoutTokenHash       *[]byte         `gorm:"column:checkout_token_hash;type:binary(32)"`                                                    // 当前收银台令牌摘要
	CheckoutBrandSnapshot   *datatypes.JSON `gorm:"column:checkout_brand_snapshot;type:json"`                                                      // 生成支付链接时的品牌配置
	CheckoutIssuedAt        *time.Time      `gorm:"column:checkout_issued_at;type:datetime(6)"`                                                    // 当前支付链接生成时间
	CheckoutExpiresAt       *time.Time      `gorm:"column:checkout_expires_at;type:datetime(6)"`                                                   // 当前支付链接到期时间
	CheckoutRevokedAt       *time.Time      `gorm:"column:checkout_revoked_at;type:datetime(6)"`                                                   // 当前支付链接吊销时间
	CreatedAt               time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt               time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (PaymentOrder) TableName() string { return "payment_order" }
