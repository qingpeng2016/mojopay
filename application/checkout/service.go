package checkout

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/qingpeng2016/ai-agent-paper/common/errorx"
	"github.com/qingpeng2016/ai-agent-paper/conf"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/shopspring/decimal"
	"gorm.io/datatypes"
	"gorm.io/gorm"
)

type Service struct {
	db  *gorm.DB
	cfg *conf.Config
}

func NewService(db *gorm.DB, cfg *conf.Config) *Service {
	return &Service{db: db, cfg: cfg}
}

// DB 供中间件查询账号归属。
func (s *Service) DB() *gorm.DB {
	return s.db
}

type AuthMerchant struct {
	APIKeyID          uint64
	MerchantAccountID uint64
	MerchantID        uint64
	KeyID             string
	Environment       string
}

func (s *Service) VerifyMerchantEligibility(ctx context.Context, merchantID, accountID uint64) error {
	var merchant entity.Merchant
	if err := s.db.WithContext(ctx).Where("id = ?", merchantID).First(&merchant).Error; err != nil {
		return errorx.NewRespErr(104001, "商户不存在", "", "merchant not found")
	}
	if strings.ToUpper(merchant.Status) != "ACTIVE" {
		return errorx.NewRespErr(104002, "商户未激活", "", "merchant not active")
	}

	var account entity.MerchantAccount
	if err := s.db.WithContext(ctx).Where("id = ? AND merchant_id = ?", accountID, merchantID).First(&account).Error; err != nil {
		return errorx.NewRespErr(104003, "商户账号无效", "", "invalid merchant account")
	}
	if strings.ToUpper(account.Status) != "ACTIVE" {
		return errorx.NewRespErr(104004, "商户账号未启用", "", "merchant account inactive")
	}

	var approved int64
	if err := s.db.WithContext(ctx).Model(&entity.ComplianceCase{}).
		Where("merchant_id = ? AND subject_type = ? AND status = ?", merchantID, "MERCHANT", "APPROVED").
		Count(&approved).Error; err != nil {
		return err
	}
	if approved == 0 {
		return errorx.NewRespErr(104005, "商户 KYB 未通过", "", "merchant KYB not approved")
	}

	return nil
}

func (s *Service) CreateSession(ctx context.Context, auth AuthMerchant, req CreateSessionRequest) (*CreateSessionResponse, error) {
	if err := s.VerifyMerchantEligibility(ctx, auth.MerchantID, auth.MerchantAccountID); err != nil {
		return nil, err
	}

	currency := strings.ToUpper(strings.TrimSpace(req.Currency))
	var asset entity.Asset
	if err := s.db.WithContext(ctx).Where("code = ?", currency).First(&asset).Error; err != nil {
		return nil, errorx.NewRespErr(104010, "不支持的币种", "", "unsupported currency")
	}

	minor, err := parseAmountToMinor(req.Amount, asset.Decimals)
	if err != nil {
		return nil, errorx.NewRespErr(104011, err.Error(), "", err.Error())
	}

	mono := strings.TrimSpace(req.MerchantOrderNo)
	var dup int64
	if err := s.db.WithContext(ctx).Model(&entity.PaymentOrder{}).
		Where("merchant_account_id = ? AND merchant_order_no = ?", auth.MerchantAccountID, mono).
		Count(&dup).Error; err != nil {
		return nil, err
	}
	if dup > 0 {
		return nil, errorx.NewRespErr(104012, "商户单号已存在", "", "duplicate merchant_order_no")
	}

	methods, err := s.listPayMethods(ctx, auth.MerchantAccountID)
	if err != nil {
		return nil, err
	}
	if len(methods) == 0 {
		return nil, errorx.NewRespErr(104013, "暂无可用通道路由", "", "no active channel bindings")
	}

	expiresAt := time.Now().Add(24 * time.Hour)
	sessionID := "cs_" + randomHex(16)
	accessToken := randomHex(24)
	tokenHash := sha256.Sum256([]byte(accessToken))
	publicID := "pay_" + randomHex(16)

	meta := map[string]interface{}{
		"success_url": req.SuccessURL,
		"cancel_url":  req.CancelURL,
		"customer_country": strings.ToUpper(strings.TrimSpace(req.CustomerCountry)),
	}
	if req.Metadata != nil {
		meta["client_metadata"] = req.Metadata
	}
	metaBytes, _ := json.Marshal(meta)
	metaJSON := datatypes.JSON(metaBytes)

	brandSnap := datatypes.JSON([]byte(`{}`))
	var acc entity.MerchantAccount
	if err := s.db.WithContext(ctx).Select("brand_config").Where("id = ?", auth.MerchantAccountID).First(&acc).Error; err == nil && acc.BrandConfig != nil && len(*acc.BrandConfig) > 0 {
		brandSnap = *acc.BrandConfig
	}

	checkoutURL := s.buildCheckoutURL(sessionID, accessToken)
	now := time.Now()
	checkoutExp := expiresAt
	hashSlice := make([]byte, 32)
	copy(hashSlice, tokenHash[:])

	order := entity.PaymentOrder{
		PublicID:                publicID,
		MerchantAccountID:       auth.MerchantAccountID,
		MerchantID:              auth.MerchantID,
		OrderAssetID:            asset.ID,
		RequestedPaymentAssetID: &asset.ID,
		SettlementAssetID:       asset.ID,
		MerchantOrderNo:         mono,
		OrderAmount:             minor,
		Status:                  "CREATED",
		Origin:                  "API",
		Metadata:                &metaJSON,
		ExpiresAt:               expiresAt,
		PaymentURL:              &checkoutURL,
		CheckoutSessionID:       &sessionID,
		CheckoutTokenHash:       &hashSlice,
		CheckoutBrandSnapshot:   &brandSnap,
		CheckoutIssuedAt:        &now,
		CheckoutExpiresAt:       &checkoutExp,
	}

	err = s.db.WithContext(ctx).Transaction(func(tx *gorm.DB) error {
		if err := tx.Create(&order).Error; err != nil {
			return err
		}
		bind := methods[0]
		snap, _ := json.Marshal(map[string]interface{}{
			"channel_binding_id": bind.BindingID,
			"capability_key":     bind.CapabilityKey,
			"source":             "checkout_session",
		})
		var cap entity.ChannelCapability
		if err := tx.Where("id = ?", bind.capabilityID).First(&cap).Error; err != nil {
			return err
		}
		reqNo := fmt.Sprintf("ck_%d_%s", order.ID, randomHex(4))
		attempt := entity.PaymentAttempt{
			PaymentOrderID:    order.ID,
			MerchantAccountID: auth.MerchantAccountID,
			CapabilityID:      cap.ID,
			ChannelBindingID:  bind.BindingID,
			ProviderAccountID: cap.ProviderAccountID,
			PaymentAssetID:    asset.ID,
			ExpectedAmount:    minor,
			ProviderRequestNo: reqNo,
			Status:            "CREATED",
			RouteSnapshot:     datatypes.JSON(snap),
		}
		return tx.Create(&attempt).Error
	})
	if err != nil {
		return nil, err
	}

	return &CreateSessionResponse{
		CheckoutSessionID: sessionID,
		PaymentOrderID:    order.ID,
		PublicID:          publicID,
		MerchantOrderNo:   mono,
		CheckoutURL:       checkoutURL,
		ExpiresAt:         expiresAt.Format(time.RFC3339),
		Status:            "CREATED",
	}, nil
}

func (s *Service) LoadCheckoutPage(ctx context.Context, sessionID, accessToken string) (*PageView, error) {
	sessionID = strings.TrimSpace(sessionID)
	if sessionID == "" || accessToken == "" {
		return nil, errorx.NewRespErr(104020, "收银台链接无效", "", "invalid checkout link")
	}
	hash := sha256.Sum256([]byte(accessToken))

	var order entity.PaymentOrder
	if err := s.db.WithContext(ctx).
		Where("checkout_session_id = ?", sessionID).
		First(&order).Error; err != nil {
		return nil, errorx.NewRespErr(104021, "收银台会话不存在", "", "checkout session not found")
	}
	if order.CheckoutTokenHash == nil || len(*order.CheckoutTokenHash) != 32 {
		return nil, errorx.NewRespErr(104022, "收银台未就绪", "", "checkout not ready")
	}
	if !constantTimeEqual(*order.CheckoutTokenHash, hash[:]) {
		return nil, errorx.NewRespErr(104023, "访问令牌无效", "", "invalid access token")
	}
	if time.Now().After(order.ExpiresAt) {
		return nil, errorx.NewRespErr(104024, "订单已过期", "", "order expired")
	}

	var asset entity.Asset
	_ = s.db.WithContext(ctx).Where("id = ?", order.OrderAssetID).First(&asset).Error

	var merchant entity.Merchant
	_ = s.db.WithContext(ctx).Where("id = ?", order.MerchantID).First(&merchant).Error
	var account entity.MerchantAccount
	_ = s.db.WithContext(ctx).Where("id = ?", order.MerchantAccountID).First(&account).Error

	methods, err := s.listPayMethods(ctx, order.MerchantAccountID)
	if err != nil {
		return nil, err
	}

	fiat, crypto := splitMethods(methods)
	display := formatAmountDisplay(order.OrderAmount, asset.Decimals, asset.Code)

	var meta map[string]interface{}
	if order.Metadata != nil {
		_ = json.Unmarshal(*order.Metadata, &meta)
	}
	successURL, _ := meta["success_url"].(string)
	cancelURL, _ := meta["cancel_url"].(string)

	return &PageView{
		SessionID:     sessionID,
		MerchantName:  merchant.LegalName,
		AccountCode:   account.Code,
		OrderAmount:   order.OrderAmount.String(),
		Currency:      asset.Code,
		AmountDisplay: display,
		Status:        order.Status,
		FiatMethods:   fiat,
		CryptoMethods: crypto,
		SuccessURL:    successURL,
		CancelURL:     cancelURL,
	}, nil
}

type payMethodRow struct {
	PayMethodOption
	capabilityID uint64
}

func (s *Service) listPayMethods(ctx context.Context, accountID uint64) ([]payMethodRow, error) {
	type row struct {
		BindingID      uint64
		CapabilityID   uint64
		Priority       int
		Operation      string
		Region         string
		CapabilityKey  string
		AssetCode      string
		AssetKind      string
	}
	var rows []row
	err := s.db.WithContext(ctx).Table("channel_binding b").
		Select("b.id as binding_id, b.capability_id, b.priority, c.operation, c.region, c.capability_key, a.code as asset_code, a.kind as asset_kind").
		Joins("JOIN channel_capability c ON c.id = b.capability_id").
		Joins("JOIN asset a ON a.id = c.asset_id").
		Where("b.merchant_account_id = ? AND b.status = ? AND c.operation = ?", accountID, "ACTIVE", "PAYIN").
		Order("b.priority asc, b.id asc").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	out := make([]payMethodRow, 0, len(rows))
	for _, r := range rows {
		out = append(out, payMethodRow{
			PayMethodOption: PayMethodOption{
				BindingID:     r.BindingID,
				AssetCode:     r.AssetCode,
				AssetKind:     r.AssetKind,
				Operation:     r.Operation,
				Region:        r.Region,
				CapabilityKey: r.CapabilityKey,
				Priority:      r.Priority,
			},
			capabilityID: r.CapabilityID,
		})
	}
	return out, nil
}

func splitMethods(rows []payMethodRow) (fiat, crypto []PayMethodOption) {
	seenFiat := map[string]struct{}{}
	seenCrypto := map[string]struct{}{}
	for _, r := range rows {
		key := r.AssetCode + "|" + r.Region
		if strings.ToUpper(r.AssetKind) == "FIAT" {
			if _, ok := seenFiat[key]; ok {
				continue
			}
			seenFiat[key] = struct{}{}
			fiat = append(fiat, r.PayMethodOption)
		} else {
			if _, ok := seenCrypto[key]; ok {
				continue
			}
			seenCrypto[key] = struct{}{}
			crypto = append(crypto, r.PayMethodOption)
		}
	}
	return fiat, crypto
}

func (s *Service) buildCheckoutURL(sessionID, token string) string {
	base := "http://127.0.0.1:8886"
	if s.cfg != nil && s.cfg.ServerConf != nil && strings.TrimSpace(s.cfg.ServerConf.CheckoutBaseURL) != "" {
		base = strings.TrimRight(strings.TrimSpace(s.cfg.ServerConf.CheckoutBaseURL), "/")
	}
	return fmt.Sprintf("%s/checkout/%s?t=%s", base, sessionID, token)
}

func parseAmountToMinor(amount string, decimals uint8) (decimal.Decimal, error) {
	amount = strings.TrimSpace(strings.ReplaceAll(amount, ",", ""))
	if amount == "" {
		return decimal.Zero, fmt.Errorf("金额不能为空")
	}
	d, err := decimal.NewFromString(amount)
	if err != nil || d.LessThanOrEqual(decimal.Zero) {
		return decimal.Zero, fmt.Errorf("金额格式无效")
	}
	scale := decimal.New(1, int32(decimals))
	return d.Mul(scale).Round(0), nil
}

func formatAmountDisplay(minor decimal.Decimal, decimals uint8, code string) string {
	if decimals == 0 {
		return minor.String() + " " + code
	}
	scale := decimal.New(1, int32(decimals))
	return minor.Div(scale).StringFixed(int32(decimals)) + " " + code
}

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func constantTimeEqual(a, b []byte) bool {
	if len(a) != len(b) {
		return false
	}
	var v byte
	for i := range a {
		v |= a[i] ^ b[i]
	}
	return v == 0
}

// ResolveAPISecret 沙盒：secret_plain；否则从 secret_ref 解析演示密钥。
func ResolveAPISecret(key entity.MerchantAPIKey) (string, error) {
	if key.SecretPlain != nil && strings.TrimSpace(*key.SecretPlain) != "" {
		return strings.TrimSpace(*key.SecretPlain), nil
	}
	ref := strings.TrimSpace(key.SecretRef)
	if strings.HasPrefix(ref, "vault://seed/") || strings.HasPrefix(ref, "sk_test_") {
		return "sk_test_" + key.KeyID, nil
	}
	return "", fmt.Errorf("api secret not available")
}

// LookupAPIKey 按 key_id 查询 ACTIVE 密钥。
func (s *Service) LookupAPIKey(ctx context.Context, keyID string) (entity.MerchantAPIKey, error) {
	var row entity.MerchantAPIKey
	if err := s.db.WithContext(ctx).Where("key_id = ? AND status = ?", keyID, "ACTIVE").First(&row).Error; err != nil {
		return row, errorx.NewRespErr(104030, "API 密钥无效", "", "invalid api key")
	}
	if row.ExpiresAt != nil && time.Now().After(*row.ExpiresAt) {
		return row, errorx.NewRespErr(104031, "API 密钥已过期", "", "api key expired")
	}
	return row, nil
}

func HasScope(scopes datatypes.JSON, want string) bool {
	if len(scopes) == 0 {
		return false
	}
	var list []string
	if err := json.Unmarshal(scopes, &list); err != nil {
		return false
	}
	for _, s := range list {
		if s == want || s == "*" {
			return true
		}
	}
	return false
}
