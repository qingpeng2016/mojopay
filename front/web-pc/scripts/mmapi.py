####script for mm api request
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
# 日志目录基于脚本位置解析(=<root>/runtime/log/python，与 PHP runtime_path() 一致)并自建，
# 避免被调用时 CWD 不同导致相对路径解析错 → FileNotFoundError。
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


class MMAPI:
    def __init__(self, api_host: str, auth_key: str):
        self.api_host = api_host
        self.auth_key = auth_key

    def fetch_mm_data(self, data):
        url = self.api_host 
        # Headers should only contain metadata/auth
        headers = {
            "Content-Type": "application/json",
            "auth_key": self.auth_key
        }
        
        try:
            # 1. Changed to .post()
            # 2. Use json= parameter to send the list properly
            response = requests.post(url, headers=headers, json=data)
            
            response.raise_for_status() 
            return response.json() 
        except requests.RequestException as e:
            # Better debugging: print the server response body if available
            error_msg = e.response.text if e.response else str(e)
            logger.info(f"Error fetching market data: {error_msg}")
            return {"status": "error", "message": str(e)}

# --- Configuration ---
#url = 'https://devmm.foregate.com/self-mm/calc-layer-orders' 
if os.getenv("MM_HOST_PROD"):
    base_url=os.getenv("MM_HOST_PROD")
else:
    base_url="https://devmm.foregate.com"

# 兜底协议头：MM_HOST_PROD 可能配成集群内地址(host:port)而漏了 scheme，
# requests 无 scheme 会报 "No connection adapters were found"。集群内服务补 http://。
if base_url and not base_url.startswith(("http://", "https://")):
    base_url = "http://" + base_url

url=base_url+'/self-mm/calc-layer-orders'
auth_key = 'qlfz6P3gNgFEclOFBvV0bpGTs9ffMZia'

# data=[
#     {

#         "outcome_name": "aa",
#         "yes_mid_price": "0.60",
#         "base_spread": "0.04",
#         "layer_increment": "0.02",
#         "layer_multiplier": "2.0",
#         "layer_count": 5,
#         "total_share_budget": 500
#     },
#     {
#         "outcome_name": "bb",
#         "yes_mid_price": "0.60",
#         "base_spread": "0.04",
#         "layer_increment": "0.02",
#         "layer_multiplier": "2.0",
#         "layer_count": 5,
#         "total_share_budget": 1000
#     }
# ]

# --- Execution ---
def main():
    # 1. Input Processing
    if len(sys.argv) < 2:
        logger.info(json.dumps({"status": "error", "message": "No Base64 input provided"}))
        return

    try:
        raw_input = sys.argv[1]
        decoded_input = json.loads(base64.b64decode(raw_input).decode('utf-8'))
        send_data = decoded_input
        if not send_data:
            raise ValueError("send data missing from input")
    except Exception as e:
        logger.info(json.dumps({"status": "error", "message": f"Input Decode Failed: {str(e)}"}))
        return

    try:
        mm_api = MMAPI(api_host=url, auth_key=auth_key)
        res = mm_api.fetch_mm_data(send_data)
   

        if res:
            print(json.dumps(res, indent=2, ensure_ascii=False))

    except Exception as e:
        logger.info(json.dumps({"status": "error", "message": f"MM API Fetch Failed: {str(e)}"}))
        return
    
if __name__ == "__main__":
    main()

