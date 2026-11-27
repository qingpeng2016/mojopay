import sys
import base64
import json
import threading
import time
import requests
import logging
from dateutil import parser
from urllib.parse import urlparse
from typing import List, Optional, Any
from datetime import datetime, timezone

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
BASE_URL = "https://gamma-api.polymarket.com"

class RateLimiter:
    def __init__(self, capacity: float, refill_rate: float):
        """
        :param capacity: Max tokens the bucket can hold (Burst size)
        :param refill_rate: How many tokens added per second
        """
        self.capacity = capacity
        self.refill_rate = refill_rate
        self.tokens = capacity
        self.last_refill = time.monotonic()
        self.lock = threading.Lock()

    def _refill(self):
        """Internal method to add tokens based on elapsed time."""
        now = time.monotonic()
        elapsed = now - self.last_refill
        new_tokens = elapsed * self.refill_rate
        
        if new_tokens > 0:
            self.tokens = min(self.capacity, self.tokens + new_tokens)
            self.last_refill = now

    def check_and_wait(self, endpoint: str, ctx: Optional[dict] = None):
        """
        Mimics Go's CheckAndWait. 
        Blocks the thread until a token is available.
        """
        while True:
            with self.lock:
                self._refill()
                if self.tokens >= 1:
                    self.tokens -= 1
                    return # Successfully acquired token
                
                # Calculate how long we need to wait for 1 token
                wait_time = (1 - self.tokens) / self.refill_rate
            
            # Sleep outside the lock so other threads can check the bucket
            logging.debug(f"Rate limit hit for {endpoint}. Waiting {wait_time:.2f}s")
            time.sleep(wait_time)

    def record_request(self, endpoint: str):
        """Optional: Log that a request was successfully sent."""
        logging.debug(f"Request recorded for {endpoint}")

class RateLimitError(Exception):
    """Custom error for rate limit failures with context."""
    def __init__(self, message: str, original_error: Exception = None):
        super().__init__(message)
        self.original_error = original_error

class SportsResolver:
    """
    Client to interact with Polymarket API for sports markets.
    Methods:
    - get_markets_by_condition_ids: Batch query markets by condition_ids
    returns list of market data.
    eg:
    [   market1,
        market2,
        ...
    ]
    where market is like:
        {
            "id": "0x...",
            "name": "Team A vs Team B - Winner",
            "condition_id": "0x...",
            ...
        },
        ... 
    ]   
    """
    def __init__(self, base_url: str, rate_limiter: Any = None, timeout_threshold: float = 5.0):
        self.base_url = base_url
        self.rate_limiter = rate_limiter
        self.timeout_threshold = timeout_threshold  # seconds (custom threshold for alerts)

    def _check_timeout_and_alert(self, start_time: float, func_name: str):
        duration = time.time() - start_time
        if duration > 5.0:  # Example 5s threshold
            print(f"ALERT: {func_name} took {duration:.2f}s!")

    def resolve_status(self, data: dict) -> str:
        if data.get("archived") is True:
            return "ARCHIVED"
        if data.get("closed") is True or data.get("closedTime") is not None:
            return "CLOSED"
        if data.get("active") is True:
            return "ACTIVE"
        return "PENDING"
    
    def _format_to_standard(self, date_str: str) -> str:
        if not date_str or not isinstance(date_str, str):
            return ""
        try:
            dt = parser.parse(date_str)
            return dt.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        except (ValueError, TypeError, OverflowError):
            return ""

    def _extract_market_data(self, market_data: dict):
        """Helper to map API market object to your standard metadata format."""
        return {
            "market_name": market_data.get("question"),
            "source_market_id": market_data.get("id"),
            "source_market_address": market_data.get("conditionId"),
            "source_status": self.resolve_status(market_data),
            "clob_token_ids": market_data.get("clobTokenIds"),
            "outcomes": market_data.get("outcomes"),
            "description": market_data.get("description"),
            "source_start_time": self.resolve_start_time(market_data),
            "source_end_time": self.resolve_end_time(market_data),
            "negRisk": market_data.get("negRisk"),
            "groupItemTitle": market_data.get("groupItemTitle"),      
        }

    def resolve_start_time(self, data: dict) -> str:
        for key in ["gameStartTime", "startDate", "startDateIso"]:
            res = self._format_to_standard(data.get(key))
            if res: return res
        return data.get("startDate") or ""

    def resolve_end_time(self, data: dict) -> str:
        for key in ["endDate", "endDateIso"]:
            res = self._format_to_standard(data.get(key))
            if res: return res
        return ""

    def get_markets_by_condition_ids(self, condition_ids: list) -> list:
        """
        Batch query markets by condition_ids
        """
        start_time = time.time()
        api_url = f"{self.base_url}/markets"

        # 1. Validation & Logging Request
        if not condition_ids:
            raise ValueError("conditionIds cannot be empty")

        logger.info(f"polymarket-GetMarketsByConditionIds-Req | "
                    f"count: {len(condition_ids)} | "
                    f"endpoint: {api_url}")

        # 2. Build Query Parameters 
        params = {"condition_ids": condition_ids}

        # --- 3. Rate Limit Check (Mimicking Go pattern) ---
        if self.rate_limiter is not None:
            try:
                # CheckAndWait logic
                self.rate_limiter.check_and_wait(api_url)
            except Exception as e:
                # Mimic fmt.Errorf("...: %w", err)
                raise RateLimitError(
                    f"rate limit check failed for GetMarketsByConditionIds (endpoint: {api_url})", 
                    original_error=e
                )

        try:
            # 4. Send Request
            response = requests.get(api_url, params=params, timeout=10)
            
            # Record request for rate limiting
            if self.rate_limiter:
                self.rate_limiter.record_request(api_url)

            # Check for HTTP errors
            response.raise_for_status()

            # 5. Parse Response
            markets = response.json()
        
            # 6. Logging Response & Performance
            duration = time.time() - start_time
            raw_response = json.dumps(markets, indent=4)           

            logger.info(f"polymarket-GetMarketsByConditionIds-Resp | "
                        f"marketsFound: {len(markets)} | "
                        f"duration: {duration:.2f}s | "
                        f"raw response: {raw_response}")

            return [self._extract_market_data(m) for m in markets]
        except requests.exceptions.RequestException as e:
            # 7. Error Handling (mimics notification.SendErrorLog)
            logger.error(f"polymarket-GetMarketsByConditionIds-RequestFailed | "
                         f"ids: {condition_ids} | "
                         f"error: {str(e)}")
            raise e
        except json.JSONDecodeError as e:
            logger.error(f"polymarket-GetMarketsByConditionIds-ParseError | "
                         f"raw_body: {response.text[:500]} | "
                         f"error: {str(e)}")
            raise e
        
        finally:
            # --- 3. Check Timeout and Alert (Mimicking Go pattern) ---
            # Explicitly called after the request attempt (successful or failed)
            self._check_timeout_and_alert(start_time, "GetMarketsByConditionIds")
        
        return []     # Return empty list on failure (should not reach here due to raises)



# condition_ids = [
#     "0x435dbb7bde05ec37c4b419bbeeeae3c3353faaacb03ecf0e9515a6c477f48fb7",
#     "0x8e9b6ca72a4439c8112e452e891a6b86c39ead11091726a4cee91a6b86c39ead"
# ]
    
# --- Entry Point ---
if __name__ == "__main__":
    # 1. Check if argument exists to avoid IndexError
    if len(sys.argv) < 2:
        logger.error(json.dumps({"status": "error", "message": "No input provided"}))
        sys.exit(1)

    try:
        # 2. Decode the Base64 input from ThinkPHP
        encoded_input = sys.argv[1]
        decoded_input = base64.b64decode(encoded_input).decode('utf-8')

        # 3. Parse the String into a Dictionary (JSON)
        payload = json.loads(decoded_input)

        # 4. Extract the actual ID
        # Use .get() to avoid KeyErrors
        raw_id = payload.get("conditionID") 
        
        if not raw_id:
            logger.error(json.dumps({"status": "error", "message": "conditionID missing in JSON payload"}))
            sys.exit(1)

        condition_ids = [raw_id]

        # 5. Execute Pipeline
        #RateLimiter_instance = RateLimiter(capacity=5, refill_rate=1)  # Example: 5 requests burst, 1 req/sec
        resolver = SportsResolver(BASE_URL, rate_limiter=None, timeout_threshold=10.0)
        poly_data = resolver.get_markets_by_condition_ids(condition_ids)
        
        # 6. Return FINAL JSON to PHP
        print(json.dumps(poly_data, indent=4))

    except Exception as e:
        logger.error(json.dumps({"status": "error", "message": f"Python Bridge Error: {str(e)}"}))
