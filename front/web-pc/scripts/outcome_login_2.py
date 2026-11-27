###script to first get outcome with marketID as input, then login and request market/init/share to get account ids
import sys
import time
import json
import logging
import requests
import base64
from typing import Optional, List, Dict, Any
import os

# 1. Setup Logging
# Create a file handler so you can still debug
file_handler = logging.FileHandler('../runtime/log/python/python_internal.log')
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
            extracted_options = []
            outcomes = data.get("outcomes", [])
            for outcome in outcomes:
                for opt in outcome.get("options", []):
                    extracted_options.append({
                        "optionId": str(opt.get("optionId")), # API uses 'optionId'
                        "name": opt.get("title")               # API uses 'title' for name
                    })
            
            return {
                "start_time": data.get("startTime"),
                "target_market_id": market_id,
                "target_status": self.resolve_target_status(data),
                "coin_id": coin_id,
                "options": extracted_options,
            }

        except Exception as e:
            logger.error(f"Resolution failed for {market_id}: {e}")
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
            logger.error(f"x Login error: {e}")
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
                if data.get("code") == 200:
                    raw_records = data.get("data", [])
                    
                    extracted_records = []
                    for item in raw_records:
                        # Exactly matching the fields in your Go grpcEntity.MarketInitShareResult
                        extracted_records.append({
                            "market_id": item.get("marketId"),
                            "user_id": item.get("userId"),
                            "user_account_id": item.get("userAccountId"), # Added for Go alignment
                            "user_name": item.get("userName"),
                            "user_type": item.get("userType"),
                            "optionId": item.get("optionId"),
                            "share": item.get("share"),
                            "created_at": item.get("createdAt")           # Added for Go alignment
                        })
                    
                    all_results[opt_id] = {
                        "full_response": data,
                        "users": extracted_records
                    }
                    logger.info(f"✓ Option {opt_id}: Found {len(extracted_records)} records.")

            except Exception as e:
                logger.error(f"x Request Exception for Option {opt_id}: {str(e)}")

        return all_results

# --- 2. RUN CODE ---
# --- CONFIGURATION (Adjust these or move to environment variables) ---
#API_HOST = "https://devtssapis.foregate.com"   # The base URL for both clients
API_HOST = os.getenv("FG_API_HOST") 
KMS_TOKEN = "e66b23de-22f8-3445-8f3e-7solwe2023sdd"       # Token for FgClient
AUTH_EMAIL = "users005@gmail.com"          # Login for OpenApiTester
AUTH_PASS = "cadasdadasd211rd"               # Password for OpenApiTester

def main():
    # 1. Input Processing
    if len(sys.argv) < 2:
        logger.error(json.dumps({"status": "error", "message": "No Base64 input provided"}))
        return

    try:
        raw_input = sys.argv[1]
        decoded_input = json.loads(base64.b64decode(raw_input).decode('utf-8'))
        market_id = decoded_input.get("market_id") or decoded_input.get("FgMarketId")
        if not market_id:
            raise ValueError("market_id missing from input")
    except Exception as e:
        logger.error(json.dumps({"status": "error", "message": f"Input Decode Failed: {str(e)}"}))
        return

    try:
        # 2. Market Data Resolution (Mirroring Go Outcome Fetch)
        logger.info(f"\n>>> STEP 1: Calling FgClient.resolve_foregate_data for Market {market_id}")
        fg_client = FgClient(api_host=API_HOST, kms_token=KMS_TOKEN)
        market_data = fg_client.resolve_foregate_data(market_id)
        
        # Verbose output for debugging
        logger.info("--- EXTRACTED MARKET INFO ---")
        logger.info(json.dumps(market_data, indent=2))

        # 3. Authentication (Mirroring Go s.fgOpenAPIRepo.Login)
        logger.info(f"\n>>> STEP 2: Authenticating as {AUTH_EMAIL}")
        api_tester = FgOpenApiTester(api_host=API_HOST)
        login_result = api_tester.login(AUTH_EMAIL, AUTH_PASS)
        
        logger.info("--- RAW LOGIN RESPONSE ---")
        logger.info(json.dumps(login_result.get("raw_response"), indent=2))

        token = login_result.get("token")
        if not token:
            logger.error(json.dumps({"status": "error", "message": "Authentication Failed"}))
            return

        # 4. Market Init Share (Mirroring Go processMatchedRelation Steps 4 & 5)
        # Extract option IDs from the market data we just fetched
        option_ids = [int(opt['optionId']) for opt in market_data.get('options', [])]
        
        logger.info(f"\n>>> STEP 3: Querying Init Shares for Options {option_ids}")
        share_results = api_tester.get_market_init_share(
            market_id=int(market_id), 
            option_ids=option_ids, 
            token=token
        )
        logger.info("--- EXTRACTED SHARE RESULTS ---")
        logger.info(json.dumps(share_results, indent=2))

        # 5. Build Final Output for PHP Integration
        volume_user_ids = set()     #use set to avoid duplicates
        normal_user_id = ""

        result= share_results[option_ids[0]]   #first option users
        users = result.get("users")
        for user in users:
            user_type = user.get("user_type", "").upper()
            if user_type == "NORMAL":          #only 1 normal user as maker
                normal_user_id = user.get("user_id")
            elif user_type == "VOLUME":    #multiple volume users 
                volume_user_ids.add(user.get("user_id"))
            else :
                logger.error(f"Unknown user type: {user_type} for user {user.get('user_id')}")
        
        # Construct the final consolidated response
        final_payload = {
            "status": "success",
            "market_mapping_preview": {
                "startTime": market_data.get("start_time"),
                "TargetGroupId": int(market_id),
                "TargetCoinId": market_data.get("coin_id"),
                "TargetStatus": market_data.get("target_status"),
                "normal_user_id": normal_user_id,
                "volume_user_ids": list(volume_user_ids)
            },
            "raw_data_dump": {
                "market_query": market_data,
                "login_response": login_result.get("raw_response"),
                "share_init_responses": share_results
            }
        }

        logger.info("\n" + "="*50)
        logger.info("FINAL PIPELINE JSON OUTPUT")
        logger.info("="*50)
        logger.info(json.dumps(final_payload))

        #php return payload
        return_payload = {
            "options": market_data.get("options"),
            "market_mapping_preview": final_payload["market_mapping_preview"],
        }
        print(json.dumps(return_payload))

    except Exception as e:
        logger.error(json.dumps({"status": "error", "message": str(e)}))

if __name__ == "__main__":
    main()
