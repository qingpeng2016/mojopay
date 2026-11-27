import os, sys, json, requests

API_KEY = os.environ.get("ACCUWEATHER_API_KEY", "")
BASE_URL = os.environ.get("ACCUWEATHER_BASE_URL", "https://dataservice.accuweather.com")

def main():
    if len(sys.argv) < 3:
        print(json.dumps({"code": 400, "message": "Usage: lat lng"}))
        sys.exit(1)
    if not API_KEY:
        print(json.dumps({"code": 500, "message": "ACCUWEATHER_API_KEY env var not set"}))
        sys.exit(1)
    lat, lng = sys.argv[1], sys.argv[2]
    try:
        resp = requests.get(
            f"{BASE_URL}/locations/v1/cities/geoposition/search",
            params={"apikey": API_KEY, "q": f"{lat},{lng}"},
            timeout=10,
        )
        data = resp.json()
        if resp.status_code != 200:
            print(json.dumps({"code": resp.status_code, "message": data.get("Message", f"HTTP {resp.status_code}")}))
            sys.exit(1)
        parts = [
            data.get("LocalizedName", ""),
            data.get("ParentCity", {}).get("EnglishName", ""),
            data.get("AdministrativeArea", {}).get("EnglishName", ""),
            data.get("Country", {}).get("EnglishName", ""),
        ]
        print(json.dumps({
            "code": 200,
            "location_key": data.get("Key", ""),
            "location_name": ", ".join(filter(None, parts)),
        }))
    except Exception as e:
        print(json.dumps({"code": 500, "message": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
