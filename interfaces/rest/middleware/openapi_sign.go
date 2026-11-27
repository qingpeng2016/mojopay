package middleware

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"io"
	"strconv"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/qingpeng2016/ai-agent-paper/application/checkout"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/gin/response"
	"github.com/qingpeng2016/ai-agent-paper/common/errorx"
)

const (
	headerKeyID     = "X-MojoPay-Key-Id"
	headerTimestamp = "X-MojoPay-Timestamp"
	headerSignature = "X-MojoPay-Signature"
)

const ctxAuthKey = "mojopay_merchant_auth"

// MerchantOpenAPIAuth HMAC-SHA256 v1 签名校验。
func MerchantOpenAPIAuth(svc *checkout.Service) gin.HandlerFunc {
	return func(c *gin.Context) {
		keyID := strings.TrimSpace(c.GetHeader(headerKeyID))
		tsStr := strings.TrimSpace(c.GetHeader(headerTimestamp))
		sigHex := strings.TrimSpace(c.GetHeader(headerSignature))
		if keyID == "" || tsStr == "" || sigHex == "" {
			response.ResponseErr(c, errorx.NewRespErr(104040, "缺少鉴权头", "", "missing auth headers"))
			return
		}
		ts, err := strconv.ParseInt(tsStr, 10, 64)
		if err != nil {
			response.ResponseErr(c, errorx.NewRespErr(104041, "时间戳无效", "", "invalid timestamp"))
			return
		}
		now := time.Now().Unix()
		if now-ts > 300 || ts-now > 300 {
			response.ResponseErr(c, errorx.NewRespErr(104042, "请求已过期", "", "timestamp skew"))
			return
		}

		body, err := io.ReadAll(c.Request.Body)
		if err != nil {
			response.ResponseErr(c, err)
			return
		}
		c.Request.Body = io.NopCloser(bytes.NewReader(body))

		key, err := svc.LookupAPIKey(c.Request.Context(), keyID)
		if err != nil {
			response.ResponseErr(c, err)
			return
		}
		if !checkout.HasScope(key.Scopes, "payments:write") {
			response.ResponseErr(c, errorx.NewRespErr(104043, "无权调用 payments:write", "", "scope denied"))
			return
		}

		secret, err := checkout.ResolveAPISecret(key)
		if err != nil {
			response.ResponseErr(c, errorx.NewRespErr(104044, "密钥不可用", "", "secret unavailable"))
			return
		}

		payload := buildSignPayload(tsStr, c.Request.Method, c.Request.URL.Path, body)
		expect := hmacSHA256Hex(secret, payload)
		got, err := hex.DecodeString(strings.ToLower(sigHex))
		if err != nil {
			response.ResponseErr(c, errorx.NewRespErr(104045, "签名校验失败", "", "invalid signature"))
			return
		}
		exp, _ := hex.DecodeString(expect)
		if subtle.ConstantTimeCompare(got, exp) != 1 {
			response.ResponseErr(c, errorx.NewRespErr(104045, "签名校验失败", "", "invalid signature"))
			return
		}

		var account struct {
			MerchantID uint64
		}
		if err := svc.DB().WithContext(c.Request.Context()).
			Table("merchant_account").
			Select("merchant_id").
			Where("id = ?", key.MerchantAccountID).
			Scan(&account).Error; err != nil || account.MerchantID == 0 {
			response.ResponseErr(c, errorx.NewRespErr(104046, "账号无效", "", "invalid account"))
			return
		}

		auth := checkout.AuthMerchant{
			APIKeyID:          key.ID,
			MerchantAccountID: key.MerchantAccountID,
			MerchantID:        account.MerchantID,
			KeyID:             key.KeyID,
			Environment:       key.Environment,
		}
		c.Set(ctxAuthKey, auth)
		c.Next()
	}
}

// GetAuthMerchant 从上下文读取商户身份。
func GetAuthMerchant(c *gin.Context) (checkout.AuthMerchant, bool) {
	v, ok := c.Get(ctxAuthKey)
	if !ok {
		return checkout.AuthMerchant{}, false
	}
	auth, ok := v.(checkout.AuthMerchant)
	return auth, ok
}

func buildSignPayload(ts, method, path string, body []byte) string {
	sum := sha256.Sum256(body)
	return ts + "\n" + strings.ToUpper(method) + "\n" + path + "\n" + hex.EncodeToString(sum[:])
}

func hmacSHA256Hex(secret, payload string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(payload))
	return hex.EncodeToString(mac.Sum(nil))
}