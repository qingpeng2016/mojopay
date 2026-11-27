package entity

import (
	"time"
)

// Beneficiary 对应：审核通过的银行账户或链上钱包。
type Beneficiary struct {
	ID                uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string    `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID uint64    `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	MerchantID        uint64    `gorm:"column:merchant_id;type:bigint unsigned;not null"`                                              // 所属商户主键
	MerchantUserID    *uint64   `gorm:"column:merchant_user_id;type:bigint unsigned"`                                                  // 商户最终用户主键
	AssetID           uint64    `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	NetworkID         *uint64   `gorm:"column:network_id;type:bigint unsigned"`                                                        // 资产网络主键
	Type              string    `gorm:"column:type;type:varchar(16);not null"`                                                         // 受益账户类型（银行或钱包）
	Country           string    `gorm:"column:country;type:char(2);not null"`                                                          // 受益账户所在国家或地区代码
	DestinationRef    string    `gorm:"column:destination_ref;type:varchar(255);not null"`                                             // 银行账户或钱包地址引用
	Fingerprint       []byte    `gorm:"column:fingerprint;type:binary(32);not null"`                                                   // 受益账户去重指纹
	Status            string    `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 受益账户审核状态
	CreatedAt         time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Beneficiary) TableName() string { return "beneficiary" }
