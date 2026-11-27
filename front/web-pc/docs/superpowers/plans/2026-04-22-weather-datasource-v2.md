# 天气数据源定价 — 完整开发文档

> 涵盖 fgmm-common / fgmm-data / fgmm-runtime / fg-marketmaker-mis 四个服务。

**目标：** 将 AccuWeather 降水概率接入自做市系统，通过 fgmm-data 统一代理访问，fgmm-runtime 调度写入 `dynamic_config`，驱动铺单中间价动态定价。

---

## 一、整体架构

```
fg-marketmaker-mis (cool_pay3 MarketMappingConfig/add)
  │  运营在「新增关联」表单配置5个数据源字段
  │  经纬度输入后即时查询 GeoPosition → 自动填入 location_key
  ▼
fgmm-runtime (Scheduler → DataSourcePricing → Strategy Registry)
  │  3个调度任务（见定价逻辑）
  │  读 market_mapping_config.data_source_location_key（AccuWeather Key）
  │  读写 datasource_pricing_state（运行时状态：p_base + last_type_id）
  │  写 dynamic_config → PlacementOrder.GetEffectiveMidPrice() 读取
  ▼
fgmm-data (POST /datasource/weather/precip-prob)
  │  WeatherAdapter → AccuWeather HTTP Client
  │  支持4种 fetch_type：daily / hourly_120 / hourly_1 / minutecast
  ▼
AccuWeather API
  ├── GeoPosition Search（经纬度 → locationKey，仅 MIS 前端查询用）
  ├── Daily 15-day Forecast（details=true）
  ├── Hourly 1hour / 120hour Forecast（details=true）
  └── MinuteCast（经纬度直传，返回 Summaries 分段预报）
```

---

## 二、AccuWeather API 参考

### 2.1 GeoPosition Search

```
GET https://dataservice.accuweather.com/locations/v1/cities/geoposition/search
    ?apikey={key}&q={lat},{lng}&language=en-us
```

**返回关键字段：**

```json
{
  "Key": "332196",
  "LocalizedName": "Ruzhou",
  "ParentCity": { "EnglishName": "Pingdingshan" },
  "AdministrativeArea": { "EnglishName": "Henan" },
  "Country": { "EnglishName": "China" }
}
```

- `Key` = locationKey，存入 `data_source_location_key` 字段
- 仅 MIS「新增关联」时调用一次，结果存入数据库，fgmm-runtime 直接使用

---

### 2.2 Daily Forecast（daily）

```
GET https://dataservice.accuweather.com/forecasts/v1/daily/15day/{locationKey}
    ?apikey={key}&details=true&language=en-us
```

> ⚠️ `details=true` 必须携带，否则 `PrecipitationProbability` 不返回。

取 `DailyForecasts[0].Day.PrecipitationProbability`（当天白天，0-100）。

---

### 2.3 Hourly 120-hour Forecast（hourly_120）

```
GET https://dataservice.accuweather.com/forecasts/v1/hourly/120hour/{locationKey}
    ?apikey={key}&details=true&language=en-us
```

取 `[0].PrecipitationProbability`。

---

### 2.4 Hourly 1-hour Forecast（hourly_1）

```
GET https://dataservice.accuweather.com/forecasts/v1/hourly/1hour/{locationKey}
    ?apikey={key}&details=true&language=en-us
```

取 `[0].PrecipitationProbability`，记为 **P_base 来源**（TRemain ≤ 90 时使用）。

---

### 2.5 MinuteCast（minutecast）

```
GET https://dataservice.accuweather.com/forecasts/v1/minute
    ?apikey={key}&q={lat},{lng}&language=en-us
```

> ⚠️ 直接传经纬度，**不使用 locationKey**。

**返回结构：**

```json
{
  "Summary": { "TypeId": 1 },
  "Summaries": [
    { "StartMinute": 0,  "EndMinute": 27, "CountMinute": 27, "TypeId": 0 },
    { "StartMinute": 27, "EndMinute": 80, "CountMinute": 53, "TypeId": 1 }
  ]
}
```

**TypeId：** `0`=无降水，`1`=雨，`2`=雪，`3`=冻雨，`4`=混合

**TRemain 匹配逻辑（核心）：**

遍历 `Summaries`，找出满足 `StartMinute ≤ TRemain ≤ EndMinute` 的分段（可能有多个）：
- 匹配分段中**只要有一个 TypeId ≠ 0（有降水）**，最终视为 TypeId = 1
- 匹配分段全部 TypeId = 0 → TypeId = 0
- **无任何匹配分段（异常）→ 维持当前中间价，跳过本次更新**

---

### 2.6 调度任务与 fetch_type 对应关系

| 调度任务 | 间隔 | 触发条件 | fetch_type | 作用 |
|---------|------|---------|------------|------|
| slow | 30min | TRemain > 90 min | TRemain > 7200 → `daily`；否则 → `hourly_120` | 直接写 dynamic_config |
| fast | 15min | TRemain ≤ 90 min | `hourly_1` | 更新 P_base（写状态表） |
| minutecast | 1min | TRemain ≤ 90 min | `minutecast` | 按 TRemain 匹配 TypeId → 更新 dynamic_config |

---

## 三、定价逻辑

### 3.1 slow 任务逻辑（TRemain > 90 min）

```
P = hourly_120[0].PrecipitationProbability（或 daily，按 TRemain > 7200 决定）
YES = P / 100（夹紧 [0.001, 0.999]）
NO  = 1 - YES
写入 dynamic_config
```

### 3.2 fast 任务逻辑（TRemain ≤ 90 min，每 15 分钟）

```
P = hourly_1[0].PrecipitationProbability
P_base = P / 100
写入 datasource_pricing_state.p_base
同时以 P_base 更新 dynamic_config（YES = P_base，NO = 1 - P_base）
```

### 3.3 minutecast 任务逻辑（TRemain ≤ 90 min，每 1 分钟）

```
调用 MinuteCast → 遍历 Summaries 找满足 StartMinute ≤ TRemain ≤ EndMinute 的分段

若无匹配分段（异常）→ 跳过，维持当前 dynamic_config

从匹配分段中推断 TypeId：
  if 任意匹配分段.TypeId ≠ 0 → TypeId = 1
  else → TypeId = 0

读取 last_type_id（来自 datasource_pricing_state，初始值 -1）
if TypeId == last_type_id → 跳过（无变化）
else:
  if TypeId = 1（有降水）:
    YES = max(0.95, P_base)  // P_base > 0.95 则用 P_base，否则取 0.95
  if TypeId = 0（无降水）:
    YES = P_base
  NO = 1 - YES
  写入 dynamic_config
  更新 datasource_pricing_state.last_type_id = TypeId
```

**last_type_id 初始值为 -1**，确保第一次 MinuteCast 必然触发定价更新。

**P_base 初始值为 -1**（未初始化）。若 minutecast 运行时 P_base = -1（fast 任务尚未运行），则跳过本次更新，等待 fast 任务先建立基准。

---

## 四、fgmm-common 新增

### 4.1 新增文件结构

```
fgmm-common/
├── datasource/
│   ├── enum/
│   │   └── datasource.go   # DataSourceType / WeatherProvider 枚举
│   └── weather/
│       ├── request.go      # PrecipProbReq（fgmm-data ↔ fgmm-runtime 共享）
│       └── response.go     # PrecipProbResp
```

### 4.2 datasource/enum/datasource.go

```go
package enum

// DataSourceType 数据源类型
type DataSourceType string

const (
    DataSourceTypeWeather DataSourceType = "weather"
)

// WeatherProvider 天气数据源提供商（对应 data_source_sub_type）
type WeatherProvider string

const (
    WeatherProviderAccuWeather WeatherProvider = "accuweather"
)
```

### 4.3 datasource/weather/request.go

```go
package weather

// PrecipProbReq fgmm-runtime 调用 fgmm-data POST /datasource/weather/precip-prob 的请求
type PrecipProbReq struct {
    Lat         string `json:"lat"`          // 纬度，MinuteCast 必填
    Lng         string `json:"lng"`          // 经度，MinuteCast 必填
    LocationKey string `json:"location_key"` // AccuWeather Key，daily/hourly 必填
    FetchType   string `json:"fetch_type"`   // daily | hourly_120 | hourly_1 | minutecast
}
```

### 4.4 datasource/weather/response.go

```go
package weather

// PrecipProbResp fgmm-data 返回的降水概率
type PrecipProbResp struct {
    PrecipProb   int    `json:"precip_prob"`   // 降水概率 0-100
    TypeId       int    `json:"type_id"`       // MinuteCast TypeId；其余端点返回 -1
    EndpointUsed string `json:"endpoint_used"` // 实际调用的端点标识
}
```

---

## 五、fgmm-data 改动

### 5.1 文件结构

| 文件 | 操作 | 说明 |
|------|------|------|
| `conf/setting.go` | 修改 | 新增 `Get_ACCUWEATHER_API_KEY()` / `Get_ACCUWEATHER_BASE_URL()` 环境变量 getter |
| `infrastructure/http/datasource/accuweather/model.go` | 新建 | AccuWeather API 原始响应 DTO |
| `infrastructure/http/datasource/accuweather/client.go` | 新建 | HTTP 客户端，调用 AccuWeather 各端点 |
| `application/adapter/datasource/weather/dto/request.go` | 新建 | 适配器层内部请求 DTO |
| `application/adapter/datasource/weather/dto/response.go` | 新建 | 适配器层内部响应 DTO |
| `application/adapter/datasource/weather/adapter.go` | 新建 | WeatherAdapter 接口 |
| `application/adapter/datasource/weather/base.go` | 新建 | 默认实现基类 |
| `application/adapter/datasource/weather/accuweather.go` | 新建 | AccuWeather 具体实现 |
| `application/adapter/datasource/datasource_factory.go` | 新建 | 数据源适配器工厂 |
| `interfaces/handler/datasource/handler.go` | 新建 | gin handler，`POST /datasource/weather/precip-prob` |
| `interfaces/rest/router.go` | 修改 | 新增 `/datasource/` 路由组 |
| `boot/boot.go` | 修改 | DI 注册新增组件 |

---

### 5.2 conf/setting.go 新增内容

在文件末尾 `GetAccuWeatherConf()` 之后追加：

```go
// Get_ACCUWEATHER_API_KEY 优先读环境变量，fallback 读 YAML 配置
func Get_ACCUWEATHER_API_KEY() string {
    if key := GetEnvVar("ACCUWEATHER_API_KEY"); key != "" {
        return key
    }
    if cfg := GetAccuWeatherConf(); cfg != nil {
        return cfg.APIKey
    }
    return ""
}

// Get_ACCUWEATHER_BASE_URL 优先读环境变量，fallback 读 YAML 配置，默认为官方地址
func Get_ACCUWEATHER_BASE_URL() string {
    if u := GetEnvVar("ACCUWEATHER_BASE_URL"); u != "" {
        return u
    }
    if cfg := GetAccuWeatherConf(); cfg != nil && cfg.BaseURL != "" {
        return cfg.BaseURL
    }
    return "https://dataservice.accuweather.com"
}
```

> `AccuWeatherConf` struct 及 `GetAccuWeatherConf()` 已存在于 setting.go，无需重复定义。

---

### 5.3 infrastructure/http/datasource/accuweather/model.go

```go
package accuweather

type GeoPositionResp struct {
    Key                string `json:"Key"`
    LocalizedName      string `json:"LocalizedName"`
    ParentCity         struct{ EnglishName string `json:"EnglishName"` } `json:"ParentCity"`
    AdministrativeArea struct{ EnglishName string `json:"EnglishName"` } `json:"AdministrativeArea"`
    Country            struct{ EnglishName string `json:"EnglishName"` } `json:"Country"`
}

type DailyForecastResp struct {
    DailyForecasts []struct {
        Day struct {
            PrecipitationProbability int `json:"PrecipitationProbability"`
        } `json:"Day"`
    } `json:"DailyForecasts"`
}

type HourlyForecastItem struct {
    PrecipitationProbability int  `json:"PrecipitationProbability"`
    HasPrecipitation         bool `json:"HasPrecipitation"`
}

// MinuteCastResp MinuteCast 响应（直传经纬度，不需要 locationKey）
type MinuteCastResp struct {
    Summary struct {
        TypeId int `json:"TypeId"`
    } `json:"Summary"`
    Summaries []MinuteCastSummary `json:"Summaries"`
}

type MinuteCastSummary struct {
    StartMinute int `json:"StartMinute"`
    EndMinute   int `json:"EndMinute"`
    CountMinute int `json:"CountMinute"`
    TypeId      int `json:"TypeId"` // 0=无降水, 1=雨, 2=雪, 3=冻雨, 4=混合
}
```

---

### 5.4 infrastructure/http/datasource/accuweather/client.go（关键逻辑）

```go
package accuweather

import (
    "context"
    "encoding/json"
    "fmt"
    infraHttp "github.com/gph-tech/fgmm-data/infrastructure/http"
)

type Client struct {
    httpClient *infraHttp.Client
    apiKey     string
    baseURL    string
}

func NewClient(httpClient *infraHttp.Client, apiKey, baseURL string) *Client {
    return &Client{httpClient: httpClient, apiKey: apiKey, baseURL: baseURL}
}

// GetPrecipProb 根据 fetchType 调用对应端点，返回降水概率和 TypeId
// MinuteCast 由调用方自行处理 TRemain 匹配逻辑，这里只返回原始 Summaries
func (c *Client) GetPrecipProb(ctx context.Context, locationKey, lat, lng, fetchType string) (precipProb, typeId int, endpointUsed string, summaries []MinuteCastSummary, err error) {
    switch fetchType {
    case "daily":
        p, e := c.fetchDaily(ctx, locationKey)
        return p, -1, "daily_15day", nil, e
    case "hourly_120":
        p, e := c.fetchHourly(ctx, locationKey, "120hour")
        return p, -1, "hourly_120hour", nil, e
    case "hourly_1":
        p, e := c.fetchHourly(ctx, locationKey, "1hour")
        return p, -1, "hourly_1hour", nil, e
    case "minutecast":
        s, tid, e := c.fetchMinuteCast(ctx, lat, lng)
        return -1, tid, "minutecast", s, e
    default:
        return 0, -1, "", nil, fmt.Errorf("unknown fetch_type: %s", fetchType)
    }
}

func (c *Client) fetchDaily(ctx context.Context, locationKey string) (int, error) {
    api := fmt.Sprintf("%s/forecasts/v1/daily/15day/%s", c.baseURL, locationKey)
    resp, err := c.httpClient.Get(ctx, api, map[string][]string{
        "apikey": {c.apiKey}, "details": {"true"}, "language": {"en-us"},
    }, nil)
    if err != nil {
        return 0, err
    }
    var result DailyForecastResp
    if err := json.Unmarshal(resp.Body(), &result); err != nil {
        return 0, err
    }
    if len(result.DailyForecasts) == 0 {
        return 0, fmt.Errorf("daily forecast: empty response")
    }
    return result.DailyForecasts[0].Day.PrecipitationProbability, nil
}

func (c *Client) fetchHourly(ctx context.Context, locationKey, hourType string) (int, error) {
    api := fmt.Sprintf("%s/forecasts/v1/hourly/%s/%s", c.baseURL, hourType, locationKey)
    resp, err := c.httpClient.Get(ctx, api, map[string][]string{
        "apikey": {c.apiKey}, "details": {"true"}, "language": {"en-us"},
    }, nil)
    if err != nil {
        return 0, err
    }
    var result []HourlyForecastItem
    if err := json.Unmarshal(resp.Body(), &result); err != nil {
        return 0, err
    }
    if len(result) == 0 {
        return 0, fmt.Errorf("hourly_%s: empty response", hourType)
    }
    return result[0].PrecipitationProbability, nil
}

// fetchMinuteCast 返回原始 Summaries 数组和当前 Summary.TypeId
// 调用方负责按 TRemain 做分段匹配
func (c *Client) fetchMinuteCast(ctx context.Context, lat, lng string) ([]MinuteCastSummary, int, error) {
    api := fmt.Sprintf("%s/forecasts/v1/minute", c.baseURL)
    resp, err := c.httpClient.Get(ctx, api, map[string][]string{
        "apikey": {c.apiKey}, "q": {lat + "," + lng}, "language": {"en-us"},
    }, nil)
    if err != nil {
        return nil, -1, err
    }
    var result MinuteCastResp
    if err := json.Unmarshal(resp.Body(), &result); err != nil {
        return nil, -1, err
    }
    return result.Summaries, result.Summary.TypeId, nil
}
```

---

### 5.5 Adapter 层（参考 application/adapter/crypto/prediction 模式）

#### application/adapter/datasource/weather/adapter.go

```go
package weather

import (
    "context"
    "github.com/gph-tech/fgmm-data/application/adapter/datasource/weather/dto"
)

// Adapter 天气数据源适配器接口
// 新增天气数据提供商只需实现此接口并在 DatasourceFactory 注册
type Adapter interface {
    GetProvider() string
    GetPrecipProb(ctx context.Context, req *dto.GetPrecipProbReq) (*dto.GetPrecipProbResp, error)
}
```

#### application/adapter/datasource/weather/dto/request.go

```go
package dto

type GetPrecipProbReq struct {
    Lat         string
    Lng         string
    LocationKey string
    FetchType   string // daily | hourly_120 | hourly_1 | minutecast
}
```

#### application/adapter/datasource/weather/dto/response.go

```go
package dto

import accuweather "github.com/gph-tech/fgmm-data/infrastructure/http/datasource/accuweather"

type GetPrecipProbResp struct {
    PrecipProb   int    // -1 表示 minutecast（调用方自行处理 Summaries）
    TypeId       int    // MinuteCast Summary.TypeId；其余为 -1
    EndpointUsed string
    // MinuteCast 原始分段，供调用方按 TRemain 匹配
    Summaries []accuweather.MinuteCastSummary
}
```

#### application/adapter/datasource/weather/base.go

```go
package weather

import (
    "context"
    "fmt"
    "github.com/gph-tech/fgmm-data/application/adapter/datasource/weather/dto"
)

type Base struct{ provider string }

func NewBase(provider string) *Base { return &Base{provider: provider} }
func (b *Base) GetProvider() string { return b.provider }
func (b *Base) GetPrecipProb(_ context.Context, _ *dto.GetPrecipProbReq) (*dto.GetPrecipProbResp, error) {
    return nil, fmt.Errorf("GetPrecipProb not implemented for provider %s", b.provider)
}
```

#### application/adapter/datasource/weather/accuweather.go

```go
package weather

import (
    "context"
    accuweatherClient "github.com/gph-tech/fgmm-data/infrastructure/http/datasource/accuweather"
    "github.com/gph-tech/fgmm-data/application/adapter/datasource/weather/dto"
)

const ProviderAccuWeather = "accuweather"

type AccuWeather struct {
    *Base
    client *accuweatherClient.Client
}

func NewAccuWeather(client *accuweatherClient.Client) *AccuWeather {
    return &AccuWeather{Base: NewBase(ProviderAccuWeather), client: client}
}

func (a *AccuWeather) GetPrecipProb(ctx context.Context, req *dto.GetPrecipProbReq) (*dto.GetPrecipProbResp, error) {
    precipProb, typeId, endpointUsed, summaries, err := a.client.GetPrecipProb(
        ctx, req.LocationKey, req.Lat, req.Lng, req.FetchType,
    )
    if err != nil {
        return nil, err
    }
    return &dto.GetPrecipProbResp{
        PrecipProb:   precipProb,
        TypeId:       typeId,
        EndpointUsed: endpointUsed,
        Summaries:    summaries,
    }, nil
}
```

#### application/adapter/datasource/datasource_factory.go

```go
package datasource

import (
    "fmt"
    weather "github.com/gph-tech/fgmm-data/application/adapter/datasource/weather"
)

type DatasourceFactory struct {
    weatherAdapters map[string]weather.Adapter
}

func NewDatasourceFactory(accuWeather *weather.AccuWeather) *DatasourceFactory {
    f := &DatasourceFactory{weatherAdapters: make(map[string]weather.Adapter)}
    f.weatherAdapters[weather.ProviderAccuWeather] = accuWeather
    return f
}

func (f *DatasourceFactory) GetWeatherAdapter(provider string) (weather.Adapter, error) {
    a, ok := f.weatherAdapters[provider]
    if !ok {
        return nil, fmt.Errorf("unsupported weather provider: %s", provider)
    }
    return a, nil
}
```

---

### 5.6 对外接口

> Handler 的请求/响应使用 `fgmm-common/datasource/weather.PrecipProbReq/Resp`（与 fgmm-runtime 侧共享）。

```
POST /datasource/weather/precip-prob
Authorization: Bearer {auth_key}
Content-Type: application/json

{
  "lat": "34.164",
  "lng": "112.838",
  "location_key": "332196",
  "fetch_type": "hourly_1"
}

Response 200:
{
  "code": 200,
  "data": {
    "precip_prob": 45,
    "type_id": -1,
    "endpoint_used": "hourly_1hour"
  }
}
```

> MinuteCast 的 `Summaries` 原始分段**不通过 HTTP 返回给 fgmm-runtime**，由 fgmm-data handler 在内部完成 TRemain 匹配并对外只返回 `precip_prob` 和匹配后的 `type_id`。
>
> 因此：handler 需要接收额外的 `t_remain` 参数，在内部完成匹配逻辑后返回最终 TypeId。

**修正后的请求结构（fgmm-common/datasource/weather/request.go）：**

```go
type PrecipProbReq struct {
    Lat         string `json:"lat"`
    Lng         string `json:"lng"`
    LocationKey string `json:"location_key"`
    FetchType   string `json:"fetch_type"`
    TRemain     int    `json:"t_remain"` // 市场剩余分钟数，MinuteCast 必填
}
```

**MinuteCast TRemain 匹配逻辑（在 fgmm-data handler/service 层实现）：**

```go
// matchTypeIdByTRemain 遍历 Summaries，找 StartMinute ≤ tRemain ≤ EndMinute 的分段
// 任一匹配分段 TypeId ≠ 0 → 返回 1（有降水）；全为 0 → 返回 0；无匹配 → 返回 -2（异常）
func matchTypeIdByTRemain(summaries []accuweather.MinuteCastSummary, tRemain int) int {
    var matched []int
    for _, s := range summaries {
        if s.StartMinute <= tRemain && tRemain <= s.EndMinute {
            matched = append(matched, s.TypeId)
        }
    }
    if len(matched) == 0 {
        return -2 // 无匹配，调用方跳过本次更新
    }
    for _, id := range matched {
        if id != 0 {
            return 1 // 有降水
        }
    }
    return 0 // 无降水
}
```

---

### 5.7 boot/boot.go 新增注册

```go
_ = c.Provide(func(httpClient *http.Client) *accuweatherClient.Client {
    return accuweatherClient.NewClient(
        httpClient,
        conf.Get_ACCUWEATHER_API_KEY(),
        conf.Get_ACCUWEATHER_BASE_URL(),
    )
})
_ = c.Provide(weatherAdapter.NewAccuWeather)
_ = c.Provide(datasourceAdapter.NewDatasourceFactory)
_ = c.Provide(datasourceHandler.NewHandler)
```

---

## 六、fgmm-runtime 改动

### 6.1 数据库变更

#### migration 001：market_mapping_config 新增5字段

```sql
ALTER TABLE `market_mapping_config`
    ADD COLUMN `is_data_source_pricing_enabled` tinyint(1) NOT NULL DEFAULT 0
        COMMENT '是否启用数据源定价 0=否 1=是' AFTER `is_spread_dynamic_enabled`,
    ADD COLUMN `data_source_type` varchar(50) NOT NULL DEFAULT ''
        COMMENT '数据源类型，参考 fgmm-common datasource enum，如 weather' AFTER `is_data_source_pricing_enabled`,
    ADD COLUMN `data_source_sub_type` varchar(50) NOT NULL DEFAULT ''
        COMMENT '数据源子类型，参考 fgmm-common datasource enum，如 accuweather' AFTER `data_source_type`,
    ADD COLUMN `data_source_location` varchar(100) NOT NULL DEFAULT ''
        COMMENT '经纬度 格式 lat,lng，如 34.164,112.838' AFTER `data_source_sub_type`,
    ADD COLUMN `data_source_location_key` varchar(50) NOT NULL DEFAULT ''
        COMMENT 'AccuWeather locationKey（由 MIS GeoPosition 查询后写入）' AFTER `data_source_location`;
```

> `data_source_sub_type` 存值 `accuweather`，与策略注册表 key `"weather:accuweather"` 对应。

#### migration 002：bot_schedule_config 插入3个调度任务

```sql
INSERT INTO `bot_schedule_config`
    (`module`, `task_name`, `interval_seconds`, `is_enabled`, `is_strategy_enabled`, `description`, `created_at`, `updated_at`)
VALUES
    ('self_mm', 'datasource_slow',        1800, 0, 0, '数据源慢速刷新（每30分钟，TRemain>90，Daily/Hourly120）', NOW(), NOW()),
    ('self_mm', 'datasource_fast',         900, 0, 0, '数据源快速刷新（每15分钟，TRemain≤90，Hourly1，更新P_base）', NOW(), NOW()),
    ('self_mm', 'datasource_minutecast',    60, 0, 0, '数据源分钟级刷新（每60秒，TRemain≤90，MinuteCast定价）', NOW(), NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
```

> `is_enabled=0`：默认关闭，上线后通过 MIS 开启。

#### migration 003：定价运行时状态表

> 此表为**运行时状态表**，存储每个 config 的 `p_base` 和 `last_type_id`，供 MinuteCast 定价逻辑读取。
> 每个 `config_id` 唯一一条记录，所有写操作均为 UPSERT。

```sql
CREATE TABLE IF NOT EXISTS `datasource_pricing_state` (
    `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
    `config_id`     bigint unsigned NOT NULL             COMMENT 'market_mapping_config.id',
    `p_base`        decimal(10,6)   NOT NULL DEFAULT -1  COMMENT 'Hourly1 基准降水概率（-1=未初始化）',
    `last_type_id`  int             NOT NULL DEFAULT -1  COMMENT 'MinuteCast 上次 TypeId（-1=未初始化，确保首次必触发）',
    `mid_price_yes` varchar(20)     NOT NULL DEFAULT '',
    `mid_price_no`  varchar(20)     NOT NULL DEFAULT '',
    `updated_at`    datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_id` (`config_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自做市数据源定价运行时状态（每个配置保留当前状态）';
```

写入方式：`INSERT ... ON DUPLICATE KEY UPDATE`（按 `config_id` upsert）。

---

### 6.2 文件结构

| 文件路径 | 操作 | 说明 |
|---------|------|------|
| `scripts/migrations/001_self_mm_datasource_config.sql` | 新建 | migration 001 |
| `scripts/migrations/002_self_mm_datasource_schedules.sql` | 新建 | migration 002 |
| `scripts/migrations/003_datasource_pricing_state.sql` | 新建 | migration 003 |
| `application/strategy/self-mm/model/entity/market_mapping_config.go` | 修改 | 新增5字段 + 3个辅助方法 |
| `application/strategy/self-mm/model/entity/datasource_pricing_state.go` | 新建 | 运行时状态实体 |
| `application/strategy/self-mm/model/repository/datasource_pricing_state.go` | 新建 | repo interface |
| `application/strategy/self-mm/model/mysql/datasource_pricing_state_impl.go` | 新建 | MySQL upsert 实现 |
| `infrastructure/datacenter/datasource/client.go` | 新建 | 封装 prediction.Client.PostJSON 调用 fgmm-data |
| `application/strategy/self-mm/scene/trade/datasource_strategy_test.go` | 新建 | TDD 纯函数测试 |
| `application/strategy/self-mm/scene/trade/datasource_strategy.go` | 新建 | Strategy 接口 + Registry + WeatherAccuWeatherPrecipStrategy |
| `application/strategy/self-mm/scene/trade/datasource_pricing.go` | 新建 | 编排器 HandleSlow/HandleFast/HandleMinuteCast |
| `application/strategy/self-mm/scheduler.go` | 修改 | 新增 dataSourcePricing 字段 + 3 case |
| `application/strategy/self-mm/register/boot.go` | 修改 | DI 注册新增组件 |

---

### 6.3 entity/market_mapping_config.go 新增内容

在 `IsSpreadDynamicEnabled` 字段之后插入：

```go
IsDataSourcePricingEnabled int    `gorm:"column:is_data_source_pricing_enabled;type:tinyint(1);not null;default:0"`
DataSourceType             string `gorm:"column:data_source_type;type:varchar(50);not null;default:''"`
DataSourceSubType          string `gorm:"column:data_source_sub_type;type:varchar(50);not null;default:''"`
DataSourceLocation         string `gorm:"column:data_source_location;type:varchar(100);not null;default:''"`
DataSourceLocationKey      string `gorm:"column:data_source_location_key;type:varchar(50);not null;default:''"`
```

文件末尾新增辅助方法：

```go
func (m *MarketMappingConfig) IsWeatherDataSourceEnabled() bool {
    return m.IsDataSourcePricingEnabled == 1 &&
        m.DataSourceType == string(datasourceEnum.DataSourceTypeWeather) &&
        m.DataSourceSubType == string(datasourceEnum.WeatherProviderAccuWeather) &&
        m.DataSourceLocationKey != ""
}

func (m *MarketMappingConfig) GetDataSourceStrategyKey() string {
    if m.DataSourceType == "" || m.DataSourceSubType == "" {
        return ""
    }
    return m.DataSourceType + ":" + m.DataSourceSubType
}

func (m *MarketMappingConfig) ParseDataSourceLocation() (lat, lng string, err error) {
    parts := strings.SplitN(m.DataSourceLocation, ",", 2)
    if len(parts) != 2 || strings.TrimSpace(parts[0]) == "" || strings.TrimSpace(parts[1]) == "" {
        return "", "", fmt.Errorf("invalid data_source_location: %q", m.DataSourceLocation)
    }
    return strings.TrimSpace(parts[0]), strings.TrimSpace(parts[1]), nil
}
```

---

### 6.4 entity/datasource_pricing_state.go

```go
package entity

type DataSourcePricingState struct {
    ID           uint64  `gorm:"column:id;primaryKey;autoIncrement"`
    ConfigID     uint64  `gorm:"column:config_id;uniqueIndex;not null"`
    PBase        float64 `gorm:"column:p_base;type:decimal(10,6);not null;default:-1"` // -1=未初始化
    LastTypeId   int     `gorm:"column:last_type_id;not null;default:-1"`               // -1=未初始化
    MidPriceYes  string  `gorm:"column:mid_price_yes;type:varchar(20);not null;default:''"`
    MidPriceNo   string  `gorm:"column:mid_price_no;type:varchar(20);not null;default:''"`
}
```

---

### 6.5 datasource_strategy.go 关键逻辑

**selectFetchType（纯函数，可单测）：**

```go
func selectFetchType(endTime, fetchTask string) string {
    tRemain := calcTRemainMinutes(endTime)
    switch fetchTask {
    case "slow":
        if tRemain <= 90 {
            return "" // TRemain≤90 由 fast/minutecast 任务处理
        }
        if tRemain > 7200 {
            return "daily"
        }
        return "hourly_120"
    case "fast":
        if tRemain > 90 {
            return "" // TRemain>90 由 slow 任务处理
        }
        return "hourly_1"
    case "minutecast":
        if tRemain > 90 {
            return "" // 不在 MinuteCast 阶段
        }
        return "minutecast"
    }
    return ""
}
```

**computeMidPriceYes（纯函数，可单测）：**

```go
// precipProb 0-100 → YES 中间价，夹紧到 [0.001, 0.999]
func computeMidPriceYes(precipProb int) decimal.Decimal {
    raw := decimal.NewFromInt(int64(precipProb)).Div(decimal.NewFromInt(100))
    min := decimal.NewFromFloat(0.001)
    max := decimal.NewFromFloat(0.999)
    if raw.LessThan(min) { return min }
    if raw.GreaterThan(max) { return max }
    return raw
}
```

**MinuteCast 定价逻辑（HandleMinuteCast 核心）：**

```go
// typeId: matchTypeIdByTRemain 返回值（由 fgmm-data 完成匹配后通过 type_id 字段返回）
// typeId = -2 → 无匹配分段（异常），跳过
// typeId == state.LastTypeId → 无变化，跳过
// typeId ≠ state.LastTypeId → 更新
func applyMinuteCastPricing(typeId int, state *entity.DataSourcePricingState) (yes, no decimal.Decimal, updated bool) {
    if typeId == -2 {
        return decimal.Zero, decimal.Zero, false // 异常，跳过
    }
    if typeId == state.LastTypeId {
        return decimal.Zero, decimal.Zero, false // 无变化，跳过
    }
    pBase := decimal.NewFromFloat(state.PBase)
    if state.PBase < 0 {
        return decimal.Zero, decimal.Zero, false // P_base 未初始化，等待 fast 任务
    }
    if typeId == 1 { // 有降水：YES = max(0.95, P_base)
        threshold := decimal.NewFromFloat(0.95)
        if pBase.GreaterThan(threshold) {
            yes = pBase
        } else {
            yes = threshold
        }
    } else { // 无降水：YES = P_base
        yes = pBase
    }
    // 夹紧
    if yes.GreaterThan(decimal.NewFromFloat(0.999)) { yes = decimal.NewFromFloat(0.999) }
    if yes.LessThan(decimal.NewFromFloat(0.001))    { yes = decimal.NewFromFloat(0.001) }
    no = decimal.NewFromInt(1).Sub(yes)
    return yes, no, true
}
```

---

### 6.6 infrastructure/datacenter/datasource/client.go

```go
// 调用 fgmm-data POST /datasource/weather/precip-prob
// 请求/响应使用 fgmm-common/datasource/weather 中的共享 DTO

import (
    weatherDto "github.com/gph-tech/fgmm-common/datasource/weather"
)

const pathWeatherPrecipProb = "/datasource/weather/precip-prob"

func (c *Client) GetWeatherPrecipProb(ctx context.Context, req *weatherDto.PrecipProbReq) (*weatherDto.PrecipProbResp, error) {
    var resp weatherDto.PrecipProbResp
    if err := c.predictionClient.PostJSON(ctx, pathWeatherPrecipProb, req, &resp); err != nil {
        return nil, err
    }
    return &resp, nil
}
```

---

## 七、fg-marketmaker-mis 改动（cool_pay3）

> **范围：仅修改「新增关联」表单**（`add.html`）。编辑页不在本次需求范围内。

### 7.1 文件结构

| 文件 | 操作 | 说明 |
|------|------|------|
| `app/cool_pay3/view/market_mapping_config/index.html` | 修改 | 列表页新增「数据源」状态列 |
| `app/cool_pay3/view/market_mapping_config/add.html` | 修改 | 「做市参数」区块内新增数据源配置字段 |
| `app/cool_pay3/controller/MarketMappingConfig.php` | 修改 | `add()` POST 保存5个数据源字段；新增 `geoposition()` AJAX 端点 |
| `scripts/accuweather_geoposition.py` | 新建 | Python 脚本，调用 AccuWeather GeoPosition API |
| `app/cool_pay3/command/FetchGeoposition.php` | 新建 | ThinkPHP Command，调用上述 Python 脚本 |

---

### 7.2 add.html 数据源字段说明

| 字段名 | 控件 | 说明 |
|--------|------|------|
| `is_data_source_pricing_enabled` | checkbox（switch 样式） | 勾选后显示数据源字段，中间价变 readonly |
| `data_source_type` | select | 选项：`天气`（值：`weather`） |
| `data_source_sub_type` | select | type=weather 时选项：`AccuWeather`（值：`accuweather`） |
| `data_source_location` | text | 格式 `纬度,经度`；blur 时触发 GeoPosition 查询 |
| `data_source_location_key` | hidden input | 由 GeoPosition 返回的 AccuWeather Key 自动填入；旁边只读文字展示地点名称（不存库） |

**JS 联动：**
1. switch 勾选 → 展示数据源字段区块，中间价 `readonly`
2. switch 取消 → 隐藏，中间价恢复可编辑
3. `data_source_location` blur → 校验格式+范围 → AJAX `geoposition` → 填入 location_key（hidden）+ 展示地点名称（只读文字）

---

### 7.3 GeoPosition 查询流程

```
用户输入经纬度（blur）
  ↓ 前端校验：纬度 [-90,90]，经度 [-180,180]
  ↓ fetch('/cool_pay3/market_mapping_config/geoposition?lat=xx&lng=xx')
  ↓ PHP geoposition() → Console::call('fetch:geoposition', [$lat, $lng])
  ↓ FetchGeoposition Command → python3 scripts/accuweather_geoposition.py {lat} {lng}
  ↓ Python → AccuWeather GeoPosition API
  ↓ 返回 {"code":1,"data":{"location_key":"332196","location_name":"Ruzhou, Pingdingshan, Henan, China"}}
  ↓ 前端：data_source_location_key hidden = "332196"，展示文字 = "Ruzhou, Pingdingshan, Henan, China"
```

地点名称拼接格式（仅前端展示，不存库）：
`{LocalizedName}, {ParentCity.EnglishName}, {AdministrativeArea.EnglishName}, {Country.EnglishName}`

---

### 7.4 scripts/accuweather_geoposition.py

```python
import os, sys, json, requests

API_KEY = os.environ["ACCUWEATHER_API_KEY"]  # 勿将真实 key 写入仓库
BASE_URL = "https://dataservice.accuweather.com"

def main():
    if len(sys.argv) < 3:
        print(json.dumps({"code": 400, "message": "Usage: lat lng"}))
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
```

---

### 7.5 MarketMappingConfig.php geoposition() 端点

```php
public function geoposition() {
    $lat = input('get.lat', '');
    $lng = input('get.lng', '');
    if (!$lat || !$lng) {
        return json(['code' => 0, 'msg' => '经纬度不能为空']);
    }
    ob_start();
    Console::call('fetch:geoposition', [$lat, $lng]);
    $output = ob_get_clean();
    $result = json_decode($output, true);
    if (!$result || ($result['code'] ?? 0) !== 200) {
        return json(['code' => 0, 'msg' => $result['message'] ?? '查询失败']);
    }
    return json(['code' => 1, 'data' => [
        'location_key'  => $result['location_key'],
        'location_name' => $result['location_name'],
    ]]);
}
```

---

### 7.6 MarketMappingConfig.php add() POST 保存逻辑

```php
$isEnabled = (isset($data['is_data_source_pricing_enabled'])
    && $data['is_data_source_pricing_enabled'] === 'on') ? 1 : 0;

$model->is_data_source_pricing_enabled = $isEnabled;
$model->data_source_type               = $isEnabled ? trim($data['data_source_type'] ?? '') : '';
$model->data_source_sub_type           = $isEnabled ? trim($data['data_source_sub_type'] ?? '') : '';
$model->data_source_location           = $isEnabled ? trim($data['data_source_location'] ?? '') : '';
$model->data_source_location_key       = $isEnabled ? trim($data['data_source_location_key'] ?? '') : '';
// dynamic_config 为空，由 fgmm-runtime 调度器写入
```

---

## 八、待确认事项

1. **AccuWeather 套餐** — 是否包含 15-day Daily、120-hour Hourly、MinuteCast 端点？
2. **MinuteCast TypeId 处理** — 当匹配分段中出现 TypeId=2（雪）/TypeId=3（冻雨）时，是否也视为「有降水」（TypeId=1）？当前方案按 `TypeId ≠ 0` 统一视为有降水。

---

## 九、集成测试清单

### fgmm-data
```bash
# hourly_1
curl -X POST http://localhost:PORT/datasource/weather/precip-prob \
  -H "Authorization: Bearer YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"lat":"34.164","lng":"112.838","location_key":"332196","fetch_type":"hourly_1","t_remain":60}'
# 预期：{"code":200,"data":{"precip_prob":45,"type_id":-1,"endpoint_used":"hourly_1hour"}}

# minutecast（t_remain=30，应匹配 StartMinute≤30≤EndMinute 的分段）
curl -X POST http://localhost:PORT/datasource/weather/precip-prob \
  -H "Authorization: Bearer YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"lat":"34.164","lng":"112.838","location_key":"","fetch_type":"minutecast","t_remain":30}'
# 预期：{"code":200,"data":{"precip_prob":-1,"type_id":0,"endpoint_used":"minutecast"}}
```

### fgmm-runtime
- 测试 DB 启用一个 config（填入有效 location_key + 坐标）
- 手动触发 `HandleFast()` → 验证 `datasource_pricing_state.p_base` 更新
- 手动触发 `HandleMinuteCast()` → 验证 `last_type_id` 变化时 `dynamic_config` 更新
- 手动触发 `HandleMinuteCast()`（相同 TypeId）→ 验证跳过，dynamic_config 不变
- `p_base=-1` 时触发 minutecast → 验证跳过

### fg-marketmaker-mis
- 「新增关联」输入经纬度 → 自动回填 location_key + 展示地点名称
- 勾选/取消 switch 联动正确
- 保存后5个字段正确写入数据库
