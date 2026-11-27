package boot

import (
	"github.com/qingpeng2016/ai-agent-paper/infrastructure/mysql"
	"go.uber.org/dig"
)

func provideModels(c *dig.Container) {
	for _, constructor := range []any{
		mysql.NewAssetImpl,
		mysql.NewAssetNetworkImpl,
		mysql.NewProviderImpl,
		mysql.NewProviderAccountImpl,
		mysql.NewChannelCapabilityImpl,
		mysql.NewMerchantImpl,
		mysql.NewMerchantAccountImpl,
		mysql.NewMerchantUserImpl,
		mysql.NewMerchantAPIKeyImpl,
		mysql.NewMerchantWebhookEndpointImpl,
		mysql.NewChannelBindingImpl,
		mysql.NewComplianceCaseImpl,
		mysql.NewBeneficiaryImpl,
		mysql.NewQuoteImpl,
		mysql.NewPaymentOrderImpl,
		mysql.NewPaymentAttemptImpl,
		mysql.NewPaymentReceiptImpl,
		mysql.NewConversionImpl,
		mysql.NewRefundOrderImpl,
		mysql.NewDisputeCaseImpl,
		mysql.NewLedgerAccountImpl,
		mysql.NewJournalImpl,
		mysql.NewJournalEntryImpl,
		mysql.NewSettlementBatchImpl,
		mysql.NewSettlementItemImpl,
		mysql.NewPayoutOrderImpl,
		mysql.NewPayoutFundingImpl,
		mysql.NewPayoutAttemptImpl,
		mysql.NewTransferOrderImpl,
		mysql.NewAPIIdempotencyImpl,
		mysql.NewProviderEventInboxImpl,
		mysql.NewMerchantEventOutboxImpl,
		mysql.NewAsyncJobImpl,
		mysql.NewProviderStatementItemImpl,
		mysql.NewReconItemImpl,
		mysql.NewApprovalCaseImpl,
		mysql.NewBotScheduleConfigImpl,
		mysql.NewOpenAPIOperationImpl,
		mysql.NewOpenAPIRequestNonceImpl,
		mysql.NewOpenAPIRequestLogImpl,
		mysql.NewMerchantWebhookDeliveryAttemptImpl,
		mysql.NewTransactorImpl,
	} {
		if err := c.Provide(constructor); err != nil {
			panic(err)
		}
	}
}
