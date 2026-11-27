###add support for both /market and /event api endpoints
import requests
import json
from urllib.parse import urlparse
import sys
import base64
import logging
import re
from dateutil import parser
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


class PolyResolver:
    def __init__(self):
        self.base_url = "https://gamma-api.polymarket.com"

    def parse_url_info(self, url: str):
        """
        Detects the slug and determines the likely API endpoint.
        Handles:
        - /event/slug
        - /market/slug
        - /sports/.../slug
        """
        path = urlparse(url).path
        parts = [p for p in path.split('/') if p]
        
        if not parts:
            raise ValueError("Invalid Polymarket URL")

        # The slug is always the last part of the URL path
        slug = parts[-1]

        # Determine if we should use the /events/ or /markets/ endpoint.
        # Most sports and event URLs contain multiple markets (e.g., Winner, Spread, Total), 
        # so the /events/ endpoint is usually the most robust choice.
        if "market" in parts:
            return "market", slug
        else:
            return "event", slug

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
            "groupItemTitle": market_data.get("groupItemTitle"),      #added fields for non-sports markets
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

    def get_market_metadata(self, url: str):
        try:
            url_type, slug = self.parse_url_info(url)
            
            # Route to the correct Gamma API endpoint
            if url_type == "event":
                endpoint = f"{self.base_url}/events/slug/{slug}"
            else:
                endpoint = f"{self.base_url}/markets/slug/{slug}"
            
            response = requests.get(endpoint, timeout=10)
            response.raise_for_status()
            data = response.json()
            #print(json.dumps(data, indent=4))

            # Handling Events (which contain a list of markets)
            if url_type == "event":
                markets = data.get("markets", [])
                if not markets:
                    # Sometimes the API returns a single market even if we call /events/
                    # but usually it's a list. We'll handle both.
                    return self._extract_market_data(data)
                
                return [self._extract_market_data(m) for m in markets]
            
            # Handling single Markets
            return self._extract_market_data(data)

        except Exception as e:
            return {"status": "error", "message": str(e)}
        
# --- Entry Point ---
if __name__ == "__main__":
    # 1. Check if argument exists to avoid IndexError
    if len(sys.argv) < 2:
        logger.error(json.dumps({"status": "error", "message": "No input provided"}))
        sys.exit(1)

    try:
        # 2. Decode the Base64 input from ThinkPHP
        #encoded_input = sys.argv[1]
        #decoded_input = base64.b64decode(encoded_input).decode('utf-8')
        
        ##cmd testing
        #decoded_input = sys.argv[1]
        decoded_json_str = base64.b64decode(sys.argv[1]).decode('utf-8')

        # 3. Parse JSON
        params = json.loads(decoded_json_str)
        
        # 4. Extract the URL from the dictionary
        # In your PHP: json_encode(['url' => $payload])
        target_url = params.get('url')

        if not target_url:
            logger.error(json.dumps({"status": "error", "message": "URL key missing in payload"}))
            sys.exit(1)

        # 5. Execute Pipeline
        resolver = PolyResolver()
        poly_data = resolver.get_market_metadata(target_url)
        
        # 6. Return FINAL JSON to PHP
        print(json.dumps(poly_data,indent=4))

    except Exception as e:
        logger.error(json.dumps({"status": "error", "message": f"Python Bridge Error: {str(e)}"}))
