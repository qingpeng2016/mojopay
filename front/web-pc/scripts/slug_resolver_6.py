import requests
import json
from urllib.parse import urlparse
import sys
import base64
import logging
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

    def extract_slug(self, url: str) -> str:
        path = urlparse(url).path
        parts = [p for p in path.split('/') if p]
        if not parts:
            raise ValueError("Invalid Polymarket URL")
        return parts[-1]

    def resolve_status(self, data: dict) -> str:
        if data.get("archived") is True:
            return "ARCHIVED"
        if data.get("closed") is True or data.get("closedTime") is not None:
            return "CLOSED"
        if data.get("active") is True:
            return "ACTIVE"
        return "PENDING"
    
    def _format_to_standard(self, date_str: str) -> str:
        """Replicates Go's parsePolyDateTime and UTC format logic."""
        if not date_str or not isinstance(date_str, str):
            return ""
        try:
            # Parses ISO formats, strings with offsets, etc.
            dt = parser.parse(date_str)
            
            # Use the directly imported timezone.utc
            return dt.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        except (ValueError, TypeError, OverflowError):
            return ""
        
    def resolve_start_time(self, data: dict) -> str:
        # 1. Try GameStartTime
        res = self._format_to_standard(data.get("gameStartTime"))
        if res: return res
        
        # 2. Try StartDate
        res = self._format_to_standard(data.get("startDate"))
        if res: return res
        
        # 3. Try StartDateIso
        res = self._format_to_standard(data.get("startDateIso"))
        if res: return res
        
        # Fallback to raw values if parsing failed but strings exist
        return data.get("startDate") or data.get("startDateIso") or ""

    def resolve_end_time(self, data: dict) -> str:
        # Following Go logic: EndDate then EndDateIso
        res = self._format_to_standard(data.get("endDate"))
        if res: return res
        
        res = self._format_to_standard(data.get("endDateIso"))
        if res: return res
        
        return ""

    def get_market_metadata(self, url: str):
        try:
            slug = self.extract_slug(url)
            endpoint = f"{self.base_url}/markets/slug/{slug}"
            
            response = requests.get(endpoint, timeout=10)
            response.raise_for_status()
            data = response.json()

            #print(json.dumps(data))

            metadata = {
                "market_name": data.get("question"),
                "source_market_id": data.get("id"),
                "source_market_address": data.get("conditionId"),
                "source_status": self.resolve_status(data),
                "clob_token_ids": data.get("clobTokenIds"),
                "outcomes": data.get("outcomes"),
                "description": data.get("description"),
                "source_start_time": self.resolve_start_time(data),
                "source_end_time": self.resolve_end_time(data),
                "negRisk": data.get("negRisk"),
            }
            return metadata

        except Exception as e:
            # We return a JSON error so PHP can still decode it
            return {"status": "error", "message": str(e)}

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
        
        # 3. Parse JSON
        params = json.loads(decoded_input)
        
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
        print(json.dumps(poly_data))

    except Exception as e:
        logger.error(json.dumps({"status": "error", "message": f"Python Bridge Error: {str(e)}"}))
