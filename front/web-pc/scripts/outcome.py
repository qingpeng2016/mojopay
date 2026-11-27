####scripts to query fgmarketid for outcomes
import sys
import time
import json
import logging
import requests
import base64
from typing import Optional, List, Dict, Any
import os


# Setup Logging
# Create a file handler so you can still debug
# 日志目录基于脚本位置解析(=<root>/runtime/log/python，与 FetchOutcomes.php 的 runtime_path() 一致)，
# 并自建目录；避免被调用时 CWD 不同导致相对路径 '../runtime/...' 解析错 → FileNotFoundError → sync 整个失败。
_log_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'runtime', 'log', 'python')
os.makedirs(_log_dir, exist_ok=True)
file_handler = logging.FileHandler(os.path.join(_log_dir, 'python_internal.log'))
file_handler.setLevel(logging.INFO)

# Set the stream handler to something PHP won't see, or just remove it
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[file_handler] # Removing StreamHandler stops console output
)
logger = logging.getLogger(__name__)


class FgClient:
    def __init__(self, api_host: str, kms_token: str):
        self.api_host = api_host
        self.kms_token = kms_token

    def resolve_target_status(self, market_data: Dict[str, Any]) -> str:
        """Maps Foregate numeric or string status to unified labels."""
        # API uses 'status' (e.g., 'settled'). Convert to uppercase for mapping.
        status = str(market_data.get("status", "")).upper()
        
        status_map = {
            "1": "ACTIVE", "OPEN": "ACTIVE", "ACTIVE": "ACTIVE",
            "2": "CLOSED", "CLOSED": "CLOSED",
            "3": "ARCHIVED", "SETTLED": "ARCHIVED", "ARCHIVED": "ARCHIVED"
        }
        return status_map.get(status, "PENDING")

    def resolve_foregate_data(self, market_id: str) -> Dict[str, Any]:
        if not market_id:
            raise ValueError("marketID is required")

        endpoint = f"{self.api_host.rstrip('/')}/api/market/query/{market_id}/outcome"
        headers = {"token": self.kms_token} if self.kms_token else {}

        logger.info(f"Fetching ForeGate Market: {market_id}")
        
        try:
            response = requests.get(endpoint, headers=headers, timeout=10)
            response.raise_for_status()
            res = response.json()
            #print(json.dumps(res, indent=4))  # Debug output of raw response

            if res.get("code") != 200:
                raise RuntimeError(f"ForeGate Business Error: {res.get('msg')}")

            data = res.get("data", {})
            
            # 1. FIX: API uses 'coinId', not 'coin_id'
            coin_id = data.get("coinId")

            # 2. FIX: Options are nested inside 'outcomes' -> 'options'
            # extracted_options = []
            # outcomes = data.get("outcomes", [])
            # for outcome in outcomes:
            #     for opt in outcome.get("options", []):
            #         extracted_options.append({
            #             "option_id": str(opt.get("optionId")), # API uses 'optionId'
            #             "name": opt.get("title")               # API uses 'title' for name
            #         })
            
            return {
                "start_time": data.get("startTime"),
                "end_time": data.get("endTime"),
                "title": data.get("title"),
                "target_market_id": market_id,
                "target_status": self.resolve_target_status(data),
                "coin_id": coin_id,
                "outcomes": data.get("outcomes", [])  # Keep full outcomes structure for now
            }

        except Exception as e:
            logger.info(f"Resolution failed for {market_id}: {e}")
            raise

class FgOpenApiTester:
    def __init__(self, api_host: str):
        self.api_host = api_host.rstrip('/')
        self.session = requests.Session()
        self.session.headers.update({
            "Content-Type": "application/json",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        })

    def login(self, email: str, password: str) -> Dict[str, Any]:
        #login_url = f"{self.api_host}/api/user/auth/login"
        login_url = f"{self.api_host}/api/user/maker/auth/login"
        encoded_password = base64.b64encode(password.encode('utf-8')).decode('utf-8')
        payload = {"email": email, "password": encoded_password}

        logger.info(f"--- Login Attempt for {email} ---")
        result = {"token": None, "raw_response": None}

        try:
            resp = self.session.post(login_url, json=payload, timeout=10)
            resp.raise_for_status()
            data = resp.json()
            result["raw_response"] = data 

            #print(json.dumps(data, indent=4))  # Debug output of raw response

            user_data = data.get("data", {}) if isinstance(data.get("data"), dict) else {}
            token_from_body = user_data.get("token") or data.get("token")
            authorization = token_from_body or self._extract_token(resp)
            
            if authorization:
                logger.info(f"✓ Authorization Token extracted.")
                result["token"] = authorization
                self.session.headers.update({
                    "Authorization": authorization, 
                    "token": authorization
                })
        except Exception as e:
            logger.info(f"x Login error: {e}")
            result["error"] = str(e)
        return result

    def _extract_token(self, resp: requests.Response) -> str:
        if "Authorization" in resp.cookies:
            return resp.cookies["Authorization"]
        set_cookie = resp.headers.get("Set-Cookie", "")
        if "Authorization=" in set_cookie:
            return set_cookie.split("Authorization=")[1].split(";")[0]
        return ""

    def get_market_init_share(self, market_id: int, option_ids: List[int], token: str):
        """
        Mirroring Go's processMatchedRelation Step 4:
        Extracts MarketID, UserID, UserAccountID, UserName, UserType, OptionID, Share, CreatedAt
        """
        all_results = {}
        logger.info(f"\n--- Querying Market Init via POST (Market ID: {market_id}) ---")

        url = f"{self.api_host}/api/order/orderbook-maker/init-share"

        for opt_id in option_ids:
            headers = {
                "token": token,
                "Authorization": token,
                "Content-Type": "application/json"
            }
            
            json_payload = {
                "marketId": market_id,
                "optionId": str(opt_id),
                "userId": "" 
            }

            try:
                resp = self.session.post(url, json=json_payload, headers=headers, timeout=15)
                if resp.status_code != 200:
                    continue

                data = resp.json()
                #print(json.dumps(data, indent=4))  # Debug output of raw response
                if data.get("code") == 200:
                    all_results[opt_id] = data.get("data", {})
                else:
                    logger.info(f"x API Error for Option {opt_id}: {data.get('msg')}")
                

            except Exception as e:
                logger.info(f"x Request Exception for Option {opt_id}: {str(e)}")
        return all_results


# --- 2. RUN CODE ---
# --- CONFIGURATION (Adjust these or move to environment variables) ---
API_HOST = os.getenv("API_HOST")   # The base URL for both clients

#KMS_TOKEN = os.getenv("KMS_TOKEN")
#KMS_TOKEN = "e66b23de-22f8-3445-8f3e-7solwe2023sdd"       # Token for FgClient
AUTH_EMAIL_DEV = "users005@gmail.com"          # Login for OpenApiTester
AUTH_PASS_DEV = "cadasdadasd211rd"               # Password for OpenApiTester
AUTH_EMAIL_UAT = "trademarket1@foregate.com"
AUTH_PASS_UAT = "CoU20c"
if os.getenv("API_HOST") == "https://devtssapis.foregate.com":
    AUTH_EMAIL = AUTH_EMAIL_DEV
    AUTH_PASS = AUTH_PASS_DEV
    KMS_TOKEN = "e66b23de-22f8-3445-8f3e-7solwe2023sdd"
else:
    AUTH_EMAIL = AUTH_EMAIL_UAT
    AUTH_PASS = AUTH_PASS_UAT
    KMS_TOKEN = os.getenv("KMS_TOKEN")
logger.info(f"API_HOST set to: {API_HOST}")
logger.info(f"Using AUTH_EMAIL: {AUTH_EMAIL}")
logger.info(f"KMS_TOKEN set to: {KMS_TOKEN}")

# --- 2. RUN CODE ---
def main():
    # 1. Input Processing
    if len(sys.argv) < 2:
        logger.info(json.dumps({"status": "error", "message": "No Base64 input provided"}))
        return

    try:
        raw_input = sys.argv[1]
        decoded_input = json.loads(base64.b64decode(raw_input).decode('utf-8'))
        market_id = decoded_input.get("market_id") or decoded_input.get("FgMarketId")
        if not market_id:
            raise ValueError("market_id missing from input")
    except Exception as e:
        logger.info(json.dumps({"status": "error", "message": f"Input Decode Failed: {str(e)}"}))
        return


    try:
        #2. Market Data Resolution
        logger.info(f"\n>>> STEP 1: Calling FgClient.resolve_foregate_data for Market {market_id}")
        client = FgClient(api_host=API_HOST, kms_token=KMS_TOKEN)
        outcome_data = client.resolve_foregate_data(market_id) 
        raw_outcomes = outcome_data.get("outcomes", [])

        #3. Authentication
        logger.info(f"\n>>> STEP 2: Authenticating as {AUTH_EMAIL}")
        api_tester = FgOpenApiTester(api_host=API_HOST)
        login_result = api_tester.login(AUTH_EMAIL, AUTH_PASS)

        logger.info("--- RAW LOGIN RESPONSE ---")
        logger.info(json.dumps(login_result.get("raw_response"), indent=2))

        token = login_result.get("token")
        #token="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJmb3JlZ2F0ZSIsInN1YiI6InVzZXJzMDA1QGdtYWlsLmNvbSIsImlhdCI6MTc3MzQ3NjYyOSwiZXhwIjoxNzczNTYzMDI5fQ.5Zixui-mKXpnLLDdWbcIpezGGSZlyAXidvmogoDqn2g"
        if not token:
            logger.info(json.dumps({"status": "error", "message": "Authentication Failed"}))
            return
        
        # --- QUERY MARKET INIT SHARE ---
        for outcome in raw_outcomes:
            # 1. Get option ids for the current outcome
            options_data = outcome.get('options', [])
            option_ids = [int(opt['optionId']) for opt in options_data]
            
            logger.info(f"Extracted Option IDs for Outcome {outcome.get('outcomeId')}: {option_ids}")
        
            # 2. Query Shares
            logger.info(f"\n>>> STEP 3: Querying Init Shares for Options {option_ids}")
            share_results = api_tester.get_market_init_share(
                market_id=int(market_id), 
                option_ids=option_ids, 
                token=token
                )
            #print(json.dumps(share_results, indent=4))

            #3.extract share for each option and append to outcome data
            for opt in options_data:
                opt_id = int(opt['optionId'])
                opt['share'] = share_results.get(opt_id, {})[0].get('share', 0)  # Default to 0 if not found

                #debug print
                #print(f"Updated Option {opt_id} with share: {opt['share']}")
            #print(f"Updated Outcome with shares: {json.dumps(outcome, indent=4)}")
        #print(f"\nFinal Outcome Data with Shares: {json.dumps(outcome_data, indent=4)}")

        #4. construct final payload for market mapping config
        final_payload = {
                "status": "success",
                "market_mapping_preview": {
                    "startTime": outcome_data.get("start_time"),
                    "endTime": outcome_data.get("end_time"),
                    "title": outcome_data.get("title"),
                    "TargetGroupId": int(market_id),
                    "TargetCoinId": outcome_data.get("coin_id"),
                    "TargetStatus": outcome_data.get("target_status"),
                },
                "outcomes": raw_outcomes,
            }   
        
        logger.info("\n" + "="*50)
        logger.info("FINAL PIPELINE JSON OUTPUT")
        logger.info("="*50)
        logger.info(f"\nFinal Payload for Market Mapping Config:\n{json.dumps(final_payload, indent=4)}") 
        
        #php return payload
        print(json.dumps(final_payload, indent=4))  
    except Exception as e:
        logger.info(json.dumps({"status": "error", "message": f"Unexpected Error: {str(e)}"}))
        
if __name__ == "__main__":
    main()
