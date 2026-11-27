<?php
declare (strict_types=1);

namespace app\data\controller;

use think\admin\Controller;
use think\facade\Validate;

class ConfigCenter extends Controller
{
    private array $tableMetas = [
        'platform_config' => [
            'title' => '平台配置',
            'pk' => ['business', 'platform'],
            'order' => 'updated_at desc, business asc, platform asc',
            'json' => ['url', 'info'],
            'search' => ['business', 'platform', 'category', 'enabled'],
            'search_options' => [
                'business' => ['sports', 'crypto', 'predict'],
                'platform' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'BINANCE', 'CHAINLINK', 'DERIBIT'],
                'category' => ['market', 'exchange', 'bookmaker', 'aggregator', 'oracle'],
            ],
            'fields' => [
                'business' => ['label' => '业务', 'type' => 'text', 'required' => true],
                'platform' => ['label' => '平台', 'type' => 'text', 'required' => true],
                'category' => ['label' => '源类型', 'type' => 'select', 'required' => true, 'options' => ['market', 'exchange', 'bookmaker', 'aggregator', 'oracle']],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
                'error_alert_threshold' => ['label' => '告警阈值', 'type' => 'number'],
                'url' => ['label' => 'URL JSON', 'type' => 'json'],
                'info' => ['label' => 'Info JSON', 'type' => 'json'],
                'remark' => ['label' => '备注', 'type' => 'textarea'],
            ],
        ],
        'collect_config' => [
            'title' => '采集任务',
            'pk' => ['collector_id'],
            'order' => 'updated_at desc, collector_id asc',
            'json' => ['config_json'],
            'search' => ['collector_id', 'collector_name', 'collect_type', 'transport', 'publish_enabled', 'enabled'],
            'search_options' => [
                'collect_type' => ['sports_config_collect', 'sports_data_collect', 'sports_score_collect', 'crypto_config_collect', 'crypto_data_collect', 'crypto_order_book_collect', 'crypto_trade_collect', 'crypto_chainlink_ws_collect', 'polymarket_market_collect', 'predict_market_open_check', 'predict_market_update_check', 'predict_order_book_collect', 'predict_trade_collect'],
                'transport' => ['rest', 'ws'],
            ],
            'fields' => [
                'collector_id' => ['label' => '采集器ID', 'type' => 'text', 'required' => true],
                'collector_name' => ['label' => '名称', 'type' => 'text'],
                'collect_type' => ['label' => '采集类型', 'type' => 'select', 'required' => true, 'options' => ['sports_config_collect', 'sports_data_collect', 'sports_score_collect', 'crypto_config_collect', 'crypto_data_collect', 'crypto_order_book_collect', 'crypto_trade_collect', 'crypto_chainlink_ws_collect', 'polymarket_market_collect', 'predict_market_open_check', 'predict_market_update_check', 'predict_order_book_collect', 'predict_trade_collect']],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
                'strategy_count' => ['label' => '策略数', 'type' => 'number'],
                'schedule_interval' => ['label' => '调度间隔秒', 'type' => 'number'],
                'rest_interval_ms' => ['label' => '请求限流ms', 'type' => 'number'],
                'transport' => ['label' => '通道', 'type' => 'select', 'options' => ['rest', 'ws']],
                'config_json' => ['label' => '配置 JSON', 'type' => 'json'],
                'publish_enabled' => ['label' => 'Kafka', 'type' => 'switch'],
                'publish_topic' => ['label' => 'Kafka Topic', 'type' => 'text'],
                'publish_key_template' => ['label' => 'Kafka Key', 'type' => 'text'],
                'publish_payload' => ['label' => 'Payload', 'type' => 'select', 'options' => ['raw', 'normalized']],
                'publish_after_write' => ['label' => 'After Write', 'type' => 'switch'],
                'description' => ['label' => '描述', 'type' => 'text'],
                'priority' => ['label' => '优先级', 'type' => 'number'],
                'retry_policy' => ['label' => '重试策略', 'type' => 'text'],
            ],
        ],
        'machine_collect_mapping' => [
            'title' => '采集机器分配',
            'pk' => ['machine_name', 'collector_id'],
            'order' => 'updated_at desc, machine_name asc, collector_id asc',
            'json' => [],
            'search' => ['machine_name', 'collector_id', 'collect_type', 'strategy_index', 'total_strategies', 'enabled', 'status'],
            'search_options' => [
                'status' => ['active', 'idle', 'error', 'inactive'],
            ],
            'fields' => [
                'machine_name' => ['label' => '机器名', 'type' => 'text', 'required' => true],
                'collector_id' => ['label' => '采集器ID', 'type' => 'text', 'required' => true],
                'collect_type' => ['label' => '采集类型', 'type' => 'text'],
                'strategy_index' => ['label' => '分片序号', 'type' => 'number'],
                'total_strategies' => ['label' => '总分片数', 'type' => 'number'],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'idle', 'error', 'inactive']],
            ],
        ],
        'mapping_config' => [
            'title' => '映射任务',
            'pk' => ['mapping_id'],
            'order' => 'updated_at desc, mapping_id asc',
            'json' => ['source_platforms', 'aggregation'],
            'search' => ['mapping_id', 'asset_type', 'mapping_type', 'source_platforms', 'output_normalized_table', 'enabled'],
            'search_options' => [
                'asset_type' => ['football', 'basketball', 'esports', 'crypto', 'predict'],
                'mapping_type' => ['football_mapping', 'basketball_mapping', 'esports_mapping', 'crypto_mapping'],
                'source_platforms' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'BINANCE', 'CHAINLINK', 'DERIBIT'],
            ],
            'fields' => [
                'mapping_id' => ['label' => '映射ID', 'type' => 'text', 'required' => true],
                'asset_type' => ['label' => '资产类型', 'type' => 'select', 'required' => true, 'options' => ['football', 'basketball', 'esports', 'crypto', 'predict']],
                'mapping_type' => ['label' => '映射类型', 'type' => 'text'],
                'source_platforms' => ['label' => '源平台 JSON 数组', 'type' => 'json'],
                'time_range' => ['label' => '时间范围', 'type' => 'text'],
                'aggregation' => ['label' => '归集配置 JSON', 'type' => 'json'],
                'output_normalized_table' => ['label' => '输出宽表', 'type' => 'text'],
                'schedule_interval' => ['label' => '调度间隔秒', 'type' => 'number'],
                'publish_enabled' => ['label' => 'Kafka', 'type' => 'switch'],
                'publish_topic' => ['label' => 'Kafka Topic', 'type' => 'text'],
                'publish_key_template' => ['label' => 'Kafka Key', 'type' => 'text'],
                'publish_payload' => ['label' => 'Payload', 'type' => 'select', 'options' => ['raw', 'normalized']],
                'publish_after_write' => ['label' => 'After Write', 'type' => 'switch'],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
            ],
        ],
        'machine_mapping_mapping' => [
            'title' => '映射机器分配',
            'pk' => ['machine_name', 'mapping_id'],
            'order' => 'updated_at desc, machine_name asc, mapping_id asc',
            'json' => [],
            'search' => ['machine_name', 'mapping_id', 'mapping_type', 'strategy_index', 'total_strategies', 'enabled', 'status'],
            'search_options' => [
                'mapping_type' => ['sports', 'crypto'],
                'status' => ['active', 'idle', 'error', 'inactive'],
            ],
            'fields' => [
                'machine_name' => ['label' => '机器名', 'type' => 'text', 'required' => true],
                'mapping_id' => ['label' => '映射ID', 'type' => 'text', 'required' => true],
                'mapping_type' => ['label' => '映射类型', 'type' => 'text'],
                'strategy_index' => ['label' => '分片序号', 'type' => 'number'],
                'total_strategies' => ['label' => '总分片数', 'type' => 'number'],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'idle', 'error', 'inactive']],
            ],
        ],
        'entity_mapping' => [
            'title' => '实体映射',
            'pk' => ['business', 'asset_type', 'entity_type', 'source_platform', 'source_id'],
            'order' => 'updated_at desc, business asc, asset_type asc, entity_type asc, source_platform asc, source_id asc',
            'json' => ['extra_json'],
            'search' => ['business', 'asset_type', 'entity_type', 'source_platform', 'source_id', 'internal_id', 'match_key', 'mapping_method', 'status'],
            'search_options' => [
                'business' => ['sports', 'crypto', 'predict'],
                'asset_type' => ['football', 'basketball', 'esports', 'crypto'],
                'entity_type' => ['event', 'market', 'team', 'selection', 'instrument'],
                'source_platform' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'BINANCE', 'CHAINLINK', 'DERIBIT'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'business' => ['label' => '业务', 'type' => 'text', 'required' => true, 'list_width' => 80],
                'asset_type' => ['label' => '资产类型', 'type' => 'text', 'list_width' => 90],
                'entity_type' => ['label' => '实体类型', 'type' => 'select', 'required' => true, 'options' => ['event', 'market', 'team', 'selection', 'instrument'], 'list_width' => 90],
                'source_platform' => ['label' => '源平台', 'type' => 'text', 'required' => true, 'list_width' => 100],
                'source_id' => ['label' => '源ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 210],
                'internal_id' => ['label' => '内部ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 240],
                'match_key' => ['label' => '匹配键', 'type' => 'text', 'mono' => true, 'list_width' => 360],
                'mapping_method' => ['label' => '映射方式', 'type' => 'select', 'options' => ['rule', 'manual', 'synonym', 'model'], 'list_width' => 90],
                'confidence' => ['label' => '置信度', 'type' => 'text', 'list_width' => 80],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged'], 'list_width' => 90],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json', 'list_width' => 320],
            ],
        ],
        'sports_entity' => [
            'title' => '标准实体',
            'pk' => ['sport', 'entity_type', 'canonical_id'],
            'order' => 'updated_at desc, sport asc, entity_type asc, canonical_id asc',
            'json' => ['extra_json'],
            'search' => ['sport', 'entity_type', 'canonical_id', 'canonical_name', 'country_code', 'tier', 'active', 'status'],
            'search_options' => [
                'sport' => ['football', 'basketball', 'esports'],
                'entity_type' => ['team', 'league', 'player', 'venue', 'bookmaker'],
                'country_code' => ['ENG', 'ESP', 'ITA', 'GER', 'FRA', 'USA'],
                'tier' => ['sharp', 'asia', 'retail'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'sport' => ['label' => '运动类型', 'type' => 'select', 'required' => true, 'options' => ['football', 'basketball', 'esports'], 'list_width' => 90],
                'entity_type' => ['label' => '实体类型', 'type' => 'select', 'required' => true, 'options' => ['team', 'league', 'player', 'venue', 'bookmaker'], 'list_width' => 90],
                'canonical_id' => ['label' => '标准ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 260],
                'canonical_name' => ['label' => '标准名称', 'type' => 'text', 'required' => true, 'list_width' => 180],
                'country_code' => ['label' => '国家/地区码', 'type' => 'text', 'list_width' => 100],
                'region' => ['label' => '区域', 'type' => 'text', 'list_width' => 100],
                'gender' => ['label' => '性别', 'type' => 'select', 'options' => ['', 'men', 'women', 'mixed'], 'list_width' => 80],
                'age_group' => ['label' => '年龄组', 'type' => 'text', 'list_width' => 80],
                'active' => ['label' => '启用', 'type' => 'switch', 'list_width' => 70],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged'], 'list_width' => 90],
                // 以下三项仅 entity_type='bookmaker' 使用：聚合数据商公允价与点差合成的配置源。
                // tier 为空或 weight 为 0 即未审核公司，不参与合成。
                'tier' => ['label' => '梯队', 'type' => 'select', 'options' => ['sharp', 'asia', 'retail'], 'list_width' => 80],
                'weight' => ['label' => '权重', 'type' => 'number', 'step' => '0.0001', 'list_width' => 80],
                'decay' => ['label' => '时间衰减系数', 'type' => 'number', 'step' => '0.0001', 'list_width' => 120],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json', 'list_width' => 320],
            ],
        ],
        'sports_entity_source' => [
            'title' => '来源实体映射',
            'pk' => ['sport', 'entity_type', 'source_platform', 'source_entity_key', 'league_scope', 'country_scope'],
            'order' => 'updated_at desc, sport asc, entity_type asc, source_platform asc, source_entity_key asc, league_scope asc, country_scope asc',
            'json' => ['extra_json'],
            'search' => ['sport', 'entity_type', 'source_platform', 'source_entity_id', 'source_entity_name', 'source_entity_key', 'canonical_id', 'league_scope', 'country_scope', 'mapping_method', 'active', 'status'],
            'search_options' => [
                'sport' => ['football', 'basketball', 'esports'],
                'entity_type' => ['team', 'league', 'player', 'venue', 'bookmaker'],
                'source_platform' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'PRODUCT'],
                'mapping_method' => ['manual', 'import', 'rule', 'model'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'sport' => ['label' => '运动类型', 'type' => 'select', 'required' => true, 'options' => ['football', 'basketball', 'esports'], 'list_width' => 90],
                'entity_type' => ['label' => '实体类型', 'type' => 'select', 'required' => true, 'options' => ['team', 'league', 'player', 'venue', 'bookmaker'], 'list_width' => 90],
                'canonical_id' => ['label' => '标准ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 260],
                'source_platform' => ['label' => '来源平台', 'type' => 'select', 'required' => true, 'options' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'PRODUCT'], 'list_width' => 110],
                'source_entity_id' => ['label' => '来源实体ID', 'type' => 'text', 'mono' => true, 'list_width' => 180],
                'source_entity_name' => ['label' => '来源实体名', 'type' => 'text', 'required' => true, 'list_width' => 180],
                'source_entity_key' => ['label' => '来源清洗Key', 'type' => 'text', 'mono' => true, 'list_width' => 180],
                'league_scope' => ['label' => '联赛范围', 'type' => 'text', 'list_width' => 120],
                'country_scope' => ['label' => '国家范围', 'type' => 'text', 'list_width' => 90],
                'priority' => ['label' => '优先级', 'type' => 'number', 'list_width' => 80],
                'confidence' => ['label' => '置信度', 'type' => 'text', 'list_width' => 80],
                'mapping_method' => ['label' => '映射方式', 'type' => 'select', 'options' => ['manual', 'import', 'rule', 'model'], 'list_width' => 90],
                'active' => ['label' => '启用', 'type' => 'switch', 'list_width' => 70],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged'], 'list_width' => 90],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json', 'list_width' => 320],
            ],
        ],
        'sports_market_type_source' => [
            'title' => '玩法映射',
            'pk' => ['sport', 'source_platform', 'source_market_type', 'source_market_type_code'],
            'order' => 'updated_at desc, sport asc, source_platform asc, source_market_type asc, source_market_type_code asc',
            'json' => ['extra_json'],
            'search' => ['sport', 'source_platform', 'source_market_type', 'source_market_type_code', 'canonical_market_type', 'canonical_period', 'active', 'status'],
            'search_options' => [
                'sport' => ['football', 'basketball', 'esports'],
                'source_platform' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'PRODUCT'],
                // 电竞标准玩法沿用源数据中文（独赢/大小），不要"规范化"成英文。
                'canonical_market_type' => ['1X2', 'MONEYLINE', 'SPREAD', 'TOTAL', '独赢', '大小'],
                'canonical_period' => ['full', 'half', 'half_1', 'half_2', 'q1', 'q2', 'q3', 'q4', 'map', 'map1', 'map2', 'map3', 'map4', 'map5'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'sport' => ['label' => '运动类型', 'type' => 'select', 'required' => true, 'options' => ['football', 'basketball', 'esports'], 'list_width' => 90],
                'source_platform' => ['label' => '来源平台', 'type' => 'select', 'required' => true, 'options' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'PRODUCT'], 'list_width' => 110],
                'source_market_type' => ['label' => '来源玩法名', 'type' => 'text', 'list_width' => 150],
                'source_market_type_code' => ['label' => '来源玩法码', 'type' => 'text', 'list_width' => 130],
                'canonical_market_type' => ['label' => '标准玩法', 'type' => 'select', 'required' => true, 'options' => ['1X2', 'MONEYLINE', 'SPREAD', 'TOTAL', '独赢', '大小'], 'list_width' => 120],
                'canonical_period' => ['label' => '标准阶段', 'type' => 'text', 'list_width' => 90],
                'selection_schema' => ['label' => '选项结构', 'type' => 'select', 'options' => ['', 'HOME,DRAW,AWAY', 'HOME,AWAY', 'OVER,UNDER'], 'list_width' => 140],
                'active' => ['label' => '启用', 'type' => 'switch', 'list_width' => 70],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged'], 'list_width' => 90],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json', 'list_width' => 320],
            ],
        ],
        'sports_event' => [
            'title' => '标准赛事',
            'pk' => ['sport', 'canonical_event_id'],
            'order' => 'updated_at desc, sport asc, canonical_event_id asc',
            'json' => ['extra_json'],
            'search' => ['sport', 'canonical_event_id', 'league_id', 'home_team_id', 'away_team_id', 'start_time_utc', 'event_status', 'active', 'status'],
            'search_options' => [
                'sport' => ['football', 'basketball', 'esports'],
                'event_status' => ['scheduled', 'live', 'finished', 'cancelled'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'sport' => ['label' => '运动类型', 'type' => 'select', 'required' => true, 'options' => ['football', 'basketball', 'esports'], 'list_width' => 90],
                'canonical_event_id' => ['label' => '标准赛事ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 280],
                'league_id' => ['label' => '标准联赛ID', 'type' => 'text', 'mono' => true, 'list_width' => 220],
                'season' => ['label' => '赛季', 'type' => 'text', 'list_width' => 90],
                'home_team_id' => ['label' => '主队标准ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 240],
                'away_team_id' => ['label' => '客队标准ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 240],
                'start_time_utc' => ['label' => '开赛时间UTC', 'type' => 'datetime', 'required' => true, 'list_width' => 150],
                'event_status' => ['label' => '赛事状态', 'type' => 'select', 'options' => ['scheduled', 'live', 'finished', 'cancelled'], 'list_width' => 90],
                'active' => ['label' => '启用', 'type' => 'switch', 'list_width' => 70],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged'], 'list_width' => 90],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json', 'list_width' => 320],
            ],
        ],
        'sports_event_source' => [
            'title' => '来源赛事映射',
            'pk' => ['sport', 'source_platform', 'source_event_id'],
            'order' => 'updated_at desc, sport asc, source_platform asc, source_event_id asc',
            'json' => ['extra_json'],
            'search' => ['sport', 'source_platform', 'source_event_id', 'source_event_slug', 'canonical_event_id', 'source_league_name', 'source_home_name', 'source_away_name', 'source_start_time', 'mapping_method', 'active', 'status'],
            'search_options' => [
                'sport' => ['football', 'basketball', 'esports'],
                'source_platform' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'PRODUCT'],
                'mapping_method' => ['manual', 'import', 'rule', 'model'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'sport' => ['label' => '运动类型', 'type' => 'select', 'required' => true, 'options' => ['football', 'basketball', 'esports'], 'list_width' => 90],
                'canonical_event_id' => ['label' => '标准赛事ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 280],
                'source_platform' => ['label' => '来源平台', 'type' => 'select', 'required' => true, 'options' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS', 'PRODUCT'], 'list_width' => 110],
                'source_event_id' => ['label' => '来源赛事ID', 'type' => 'text', 'required' => true, 'mono' => true, 'list_width' => 180],
                'source_event_slug' => ['label' => '来源Slug', 'type' => 'text', 'mono' => true, 'list_width' => 240],
                'source_league_name' => ['label' => '来源联赛', 'type' => 'text', 'list_width' => 150],
                'source_home_name' => ['label' => '来源主队', 'type' => 'text', 'list_width' => 150],
                'source_away_name' => ['label' => '来源客队', 'type' => 'text', 'list_width' => 150],
                'source_start_time' => ['label' => '来源开赛时间', 'type' => 'datetime', 'list_width' => 150],
                'confidence' => ['label' => '置信度', 'type' => 'text', 'list_width' => 80],
                'mapping_method' => ['label' => '映射方式', 'type' => 'select', 'options' => ['manual', 'import', 'rule', 'model'], 'list_width' => 90],
                'active' => ['label' => '启用', 'type' => 'switch', 'list_width' => 70],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged'], 'list_width' => 90],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json', 'list_width' => 320],
            ],
        ],
        'mapping_target_event' => [
            'title' => '目标赛事',
            'pk' => ['business', 'asset_type', 'internal_event_id'],
            'order' => 'updated_at desc, business asc, asset_type asc, internal_event_id asc',
            'json' => ['extra_json'],
            'search' => ['business', 'asset_type', 'internal_event_id', 'league_name', 'home_team_name', 'away_team_name', 'match_key', 'enabled', 'status'],
            'search_options' => [
                'business' => ['sports'],
                'asset_type' => ['football', 'basketball', 'esports'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'business' => ['label' => '业务', 'type' => 'text', 'required' => true],
                'asset_type' => ['label' => '资产类型', 'type' => 'text', 'required' => true],
                'internal_event_id' => ['label' => '内部赛事ID', 'type' => 'text', 'required' => true],
                'league_name' => ['label' => '联赛', 'type' => 'text'],
                'home_team_name' => ['label' => '主队', 'type' => 'text'],
                'away_team_name' => ['label' => '客队', 'type' => 'text'],
                'start_time' => ['label' => '开赛时间', 'type' => 'datetime'],
                'match_key' => ['label' => '匹配键', 'type' => 'text'],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged']],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json'],
            ],
        ],
        'mapping_target_market' => [
            'title' => '目标盘口',
            'pk' => ['business', 'asset_type', 'internal_event_id', 'market_type', 'period'],
            'order' => 'updated_at desc, business asc, asset_type asc, internal_event_id asc, market_type asc, period asc',
            'json' => ['extra_json'],
            'search' => ['business', 'asset_type', 'internal_event_id', 'internal_market_id', 'market_type', 'line', 'period', 'enabled', 'status'],
            'search_options' => [
                'business' => ['sports'],
                'asset_type' => ['football', 'basketball', 'esports'],
                'market_type' => ['1X2', 'MONEYLINE', 'SPREAD', 'TOTAL'],
                'status' => ['active', 'disabled', 'merged'],
            ],
            'fields' => [
                'business' => ['label' => '业务', 'type' => 'text', 'required' => true],
                'asset_type' => ['label' => '资产类型', 'type' => 'text', 'required' => true],
                'internal_event_id' => ['label' => '内部赛事ID', 'type' => 'text', 'required' => true],
                'internal_market_id' => ['label' => '内部盘口ID', 'type' => 'text'],
                'market_type' => ['label' => '盘口类型', 'type' => 'text', 'required' => true],
                'line' => ['label' => '盘口线', 'type' => 'text'],
                'period' => ['label' => '阶段', 'type' => 'text'],
                'enabled' => ['label' => '启用', 'type' => 'switch'],
                'status' => ['label' => '状态', 'type' => 'select', 'options' => ['active', 'disabled', 'merged']],
                'extra_json' => ['label' => '扩展 JSON', 'type' => 'json'],
            ],
        ],
    ];

    /** @auth true @menu true */
    public function index()
    {
        // 兼容旧的通用入口，但切换范围必须限制在当前业务分组内。
        return $this->renderTable($this->tableName(), true);
    }

    /** @auth true @menu true */
    public function platform()
    {
        return $this->renderTable('platform_config');
    }

    /** @auth true @menu true */
    public function collect()
    {
        return $this->renderTable('collect_config');
    }

    /** @auth true @menu true */
    public function collectMachine()
    {
        return $this->renderTable('machine_collect_mapping');
    }

    /** @auth true @menu true */
    public function mapping()
    {
        return $this->renderTable('mapping_config');
    }

    /** @auth true @menu true */
    public function mappingMachine()
    {
        return $this->renderTable('machine_mapping_mapping');
    }

    /** @auth true @menu true */
    public function entity()
    {
        return $this->renderTable('entity_mapping');
    }

    /** @auth true @menu true */
    public function sportsEntity()
    {
        return $this->renderTable('sports_entity');
    }

    /** @auth true @menu true */
    public function sportsEntitySource()
    {
        return $this->renderTable('sports_entity_source');
    }

    /** @auth true @menu true */
    public function sportsMarketType()
    {
        return $this->renderTable('sports_market_type_source');
    }

    /** @auth true @menu true */
    public function sportsEvent()
    {
        return $this->renderTable('sports_event');
    }

    /** @auth true @menu true */
    public function sportsEventSource()
    {
        return $this->renderTable('sports_event_source');
    }

    /** @auth true @menu true */
    public function targetEvent()
    {
        return $this->renderTable('mapping_target_event');
    }

    /** @auth true @menu true */
    public function targetMarket()
    {
        return $this->renderTable('mapping_target_market');
    }

    /** @auth true */
    public function targetEventSelect()
    {
        if ($this->request->isPost()) {
            try {
                $row = $this->request->post();
                $now = date('Y-m-d H:i:s');
                $data = [
                    'business' => 'sports',
                    'asset_type' => $this->sanitizeAssetType((string)($row['asset_type'] ?? '')),
                    'internal_event_id' => trim((string)($row['event_id'] ?? '')),
                    'league_name' => trim((string)($row['competition_name'] ?? '')),
                    'home_team_name' => '',
                    'away_team_name' => '',
                    'start_time' => $this->nullDateTime((string)($row['start_time'] ?? '')),
                    'match_key' => trim((string)($row['event_id'] ?? '')),
                    'enabled' => 1,
                    'status' => 'active',
                    'extra_json' => $this->normalizeJson(json_encode([
                        'source_platforms' => $this->csvPlatformValues((string)($row['platforms'] ?? '')),
                        'source_event_ids' => $this->csvValues((string)($row['source_event_ids'] ?? '')),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'updated_at' => $now,
                ];
                if ($data['asset_type'] === '' || $data['internal_event_id'] === '') {
                    return $this->error('请选择有效赛事');
                }
                $exists = $this->findOneByFields('mapping_target_event', [
                    'business' => $data['business'],
                    'asset_type' => $data['asset_type'],
                    'internal_event_id' => $data['internal_event_id'],
                ]);
                if ($exists) {
                    $this->updateByKey('mapping_target_event', $this->keyFromRow($this->meta('mapping_target_event')['pk'], $exists), $data);
                } else {
                    $data['created_at'] = $now;
                    $this->insertRow('mapping_target_event', $data);
                }
            } catch (\Throwable $e) {
                return $this->error('加入失败: ' . $e->getMessage());
            }
            return $this->success('已加入目标赛事', 'javascript:(parent.$&&parent.$.form)?parent.$.form.reload():parent.location.reload()');
        }

        $get = $this->request->get();
        $assetType = $this->sanitizeAssetType((string)($get['asset_type'] ?? 'football')) ?: 'football';
        $this->title = '选择目标赛事';
        $this->assign([
            'asset_types' => $this->sportsAssetTypes(),
            'asset_type' => $assetType,
            'get' => $get,
            'list' => $this->normalizedEvents($assetType, $get),
        ]);
        return $this->fetch('target_event_select');
    }

    /** @auth true */
    public function targetMarketSelect()
    {
        if ($this->request->isPost()) {
            try {
                $row = $this->request->post();
                $now = date('Y-m-d H:i:s');
                $assetType = $this->sanitizeAssetType((string)($row['asset_type'] ?? ''));
                $eventId = trim((string)($row['event_id'] ?? ''));
                $marketId = trim((string)($row['market_id'] ?? ''));
                $data = [
                    'business' => 'sports',
                    'asset_type' => $assetType,
                    'internal_event_id' => $eventId,
                    'internal_market_id' => $marketId,
                    'market_type' => trim((string)($row['market_type'] ?? '')),
                    'line' => $row['line'] === '' ? null : (string)$row['line'],
                    'period' => trim((string)($row['period'] ?? '')),
                    'enabled' => 1,
                    'status' => 'active',
                    'extra_json' => $this->normalizeJson(json_encode([
                        'source_platforms' => $this->csvPlatformValues((string)($row['platforms'] ?? '')),
                        'source_market_ids' => $this->csvValues((string)($row['source_market_ids'] ?? '')),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'updated_at' => $now,
                ];
                if ($assetType === '' || $eventId === '' || $marketId === '' || $data['market_type'] === '') {
                    return $this->error('请选择有效盘口');
                }
                $exists = $this->findOneByFields('mapping_target_market', [
                    'business' => 'sports',
                    'asset_type' => $assetType,
                    'internal_market_id' => $marketId,
                ]);
                if ($exists) {
                    $this->updateByKey('mapping_target_market', $this->keyFromRow($this->meta('mapping_target_market')['pk'], $exists), $data);
                } else {
                    $data['created_at'] = $now;
                    $this->insertRow('mapping_target_market', $data);
                }
            } catch (\Throwable $e) {
                return $this->error('加入失败: ' . $e->getMessage());
            }
            return $this->success('已加入目标盘口', 'javascript:(parent.$&&parent.$.form)?parent.$.form.reload():parent.location.reload()');
        }

        $get = $this->request->get();
        $assetType = $this->sanitizeAssetType((string)($get['asset_type'] ?? 'football')) ?: 'football';
        $this->title = '选择目标盘口';
        $this->assign([
            'asset_types' => $this->sportsAssetTypes(),
            'asset_type' => $assetType,
            'get' => $get,
            'events' => $this->normalizedEvents($assetType, ['pageSize' => 100]),
            'list' => $this->normalizedMarkets($assetType, $get),
        ]);
        return $this->fetch('target_market_select');
    }

    private function renderTable(string $table, bool $showTableSwitch = false)
    {
        $meta = $this->meta($table);
        $this->title = '数据中心 - ' . $meta['title'];

        $get = $this->request->get();
        $where = [];
        foreach ($meta['search'] as $field) {
            if (!isset($get[$field]) || $get[$field] === '') {
                continue;
            }
            if (in_array($field, ['enabled', 'active'], true)) {
                $where[] = $field . ' = ' . (int)$get[$field];
                continue;
            }
            $where[] = sprintf("%s LIKE '%%%s%%'", $field, $this->escapeClickHouseLike((string)$get[$field]));
        }

        $pageSize = max(10, min(100, (int)($get['pageSize'] ?? 20)));
        $page = max(1, (int)($get['page'] ?? 1));
        $offset = ($page - 1) * $pageSize;
        $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $list = $this->clickhouseSelect(sprintf(
            'SELECT * FROM %s FINAL%s ORDER BY %s LIMIT %d OFFSET %d',
            $table,
            $whereSQL,
            $meta['order'],
            $pageSize,
            $offset
        ));
        $countRows = $this->clickhouseSelect(sprintf('SELECT count() AS cnt FROM %s FINAL%s', $table, $whereSQL));
        $total = (int)($countRows[0]['cnt'] ?? 0);

        foreach ($list as &$row) {
            $row['__key'] = $this->rowKey($meta['pk'], $row);
            $row['__key_label'] = $this->rowKeyLabel($meta['pk'], $row);
            foreach ($meta['json'] as $jsonField) {
                $row[$jsonField . '_short'] = $this->shortJson((string)($row[$jsonField] ?? ''));
            }
            foreach (['created_at', 'updated_at', 'last_heartbeat', 'assigned_at', 'start_time', 'start_time_utc', 'source_start_time'] as $timeField) {
                if (!empty($row[$timeField])) {
                    $row[$timeField] = date('Y-m-d H:i:s', strtotime((string)$row[$timeField]));
                }
            }
        }

        $this->assign([
            'tables' => $this->tableOptions($table),
            'show_table_switch' => $showTableSwitch,
            'table' => $table,
            'meta' => $meta,
            'search_labels' => $this->searchLabels($meta),
            'has_enabled' => isset($meta['fields']['enabled']),
            'has_active' => isset($meta['fields']['active']),
            'list' => $list,
            'get' => $get,
            'export_query' => http_build_query($get),
            'pagehtml' => $this->pageHtml($table, $get, $page, $pageSize, $total),
        ]);
        return $this->fetch('index');
    }

    /**
     * 按当前筛选条件导出整表 CSV（UTF-8 BOM，Excel 直接可读）。
     * 与列表共用同一套 WHERE 拼装，覆盖所有配置表页面。
     * @auth true
     */
    public function export(): void
    {
        @set_time_limit(0);
        $table = $this->tableName();
        $meta = $this->meta($table);
        $get = $this->request->get();

        $where = [];
        foreach ($meta['search'] as $field) {
            if (!isset($get[$field]) || $get[$field] === '') {
                continue;
            }
            if (in_array($field, ['enabled', 'active'], true)) {
                $where[] = $field . ' = ' . (int)$get[$field];
                continue;
            }
            $where[] = sprintf("%s LIKE '%%%s%%'", $field, $this->escapeClickHouseLike((string)$get[$field]));
        }
        $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $wanted = array_keys($meta['fields']);
        foreach (['updated_at', 'created_at'] as $ts) {
            $wanted[] = $ts;
        }
        $cols = $this->validColumns($table, $wanted);
        $headers = [];
        foreach ($cols as $c) {
            $headers[] = $meta['fields'][$c]['label'] ?? $c;
        }

        $sql = sprintf('SELECT %s FROM %s FINAL%s ORDER BY %s', implode(', ', $cols), $table, $whereSQL, $meta['order']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $table . '_' . date('YmdHis') . '.csv"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $headers, ',', '"', '');

        $offset = 0;
        $limit = 1000;
        while (true) {
            $rows = $this->clickhouseSelect($sql . sprintf(' LIMIT %d OFFSET %d', $limit, $offset));
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $this->csvCell($row[$c] ?? '');
                }
                fputcsv($fp, $line, ',', '"', '');
            }
            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        }
        fclose($fp);
        exit;
    }

    /**
     * 取表中真实存在的列（与期望列取交集），避免 SELECT 不存在的列报错。
     */
    private function validColumns(string $table, array $wanted): array
    {
        $rows = $this->clickhouseSelect(sprintf(
            "SELECT name FROM system.columns WHERE database = currentDatabase() AND table = %s",
            $this->clickhouseValue($table)
        ));
        $exists = [];
        foreach ($rows as $r) {
            $exists[] = $r['name'];
        }
        return array_values(array_intersect($wanted, $exists));
    }

    /**
     * CSV 单元格取值。
     * 时间列统一成 yyyy-MM-dd HH:mm:ss 并加 \t 前缀，与 app/backtest/controller/Placement.php
     * 的既有约定保持一致：\t 强制 Excel 按文本显示，否则 Excel 会把 2026-09-12 00:00:00
     * 自动格式化成 2026/9/12 0:00（丢秒）。同时裁掉 DateTime64 的毫秒尾巴，与列表页一致。
     */
    private function csvCell($value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = (string)$value;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?/', $value, $m)) {
            return "\t" . $m[1] . ' ' . $m[2];
        }
        return $value;
    }

    /** @auth true */
    public function add()
    {
        return $this->saveForm($this->tableName(), null);
    }

    /** @auth true */
    public function edit()
    {
        $table = $this->tableName();
        $key = $this->decodeRowKey($table, (string)($this->request->get('key') ?: $this->request->post('key')));
        if (!$key) {
            return $this->error('参数错误');
        }
        return $this->saveForm($table, $key);
    }

    /** @auth true */
    public function toggle()
    {
        $table = $this->tableName();
        $meta = $this->meta($table);
        $field = isset($meta['fields']['enabled']) ? 'enabled' : (isset($meta['fields']['active']) ? 'active' : '');
        if ($field === '') {
            return $this->error('当前表不支持启停');
        }
        $key = $this->decodeRowKey($table, (string)$this->request->post('key'));
        $enabled = (int)$this->request->post($field);
        if (!$key || !in_array($enabled, [0, 1], true)) {
            return $this->error('参数错误');
        }
        $this->updateByKey($table, $key, [$field => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->success($enabled ? '已启用' : '已停用');
    }

    /** @auth true */
    public function remove()
    {
        $table = $this->tableName();
        $key = $this->decodeRowKey($table, (string)$this->request->post('key'));
        if (!$key) {
            return $this->error('参数错误');
        }
        $this->deleteByKey($table, $key);
        return $this->success('删除成功');
    }

    private function saveForm(string $table, ?array $key)
    {
        $meta = $this->meta($table);
        if ($this->request->isPost()) {
            try {
                $post = $this->request->post();
                $data = $this->buildData($meta, $post);
                $this->validateData($meta, $data);
                $now = date('Y-m-d H:i:s');
                $data['updated_at'] = $now;
                if ($key) {
                    $this->updateByKey($table, $key, $data);
                    return $this->success('保存成功', 'javascript:(parent.$&&parent.$.form)?parent.$.form.reload():parent.location.reload()');
                }
                $data['created_at'] = $now;
                $this->insertRow($table, $data);
                return $this->success('新增成功', 'javascript:(parent.$&&parent.$.form)?parent.$.form.reload():parent.location.reload()');
            } catch (\Throwable $e) {
                return $this->error('保存失败: ' . $e->getMessage());
            }
        }

        $vo = [];
        if ($key) {
            $vo = $this->findByKey($table, $key);
            if (!$vo) {
                return $this->error('记录不存在');
            }
            $vo['__key'] = $this->rowKey($meta['pk'], $vo);
        }
        $this->assign([
            'table' => $table,
            'meta' => $meta,
            'locked' => $key !== null ? array_fill_keys($meta['pk'], true) : [],
            'vo' => $vo ?: [],
        ]);
        return $this->fetch('form');
    }

    private function buildData(array $meta, array $post): array
    {
        $data = [];
        foreach ($meta['fields'] as $field => $cfg) {
            $type = $cfg['type'] ?? 'text';
            if ($type === 'switch') {
                $data[$field] = isset($post[$field]) ? 1 : 0;
                continue;
            }
            $value = $post[$field] ?? '';
            if (in_array($field, $meta['json'], true)) {
                $data[$field] = $this->normalizeJson((string)$value, !empty($cfg['required']));
                continue;
            }
            if ($type === 'number') {
                $data[$field] = ($value === '' || $value === null) ? 0 : (int)$value;
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            if (in_array($field, ['platform', 'source_platform'], true) && is_string($value)) {
                $value = strtoupper($value);
            }
            $data[$field] = $value;
        }
        $this->normalizeSportsMappingData($data);
        return $data;
    }

    private function validateData(array $meta, array $data): void
    {
        $rules = [];
        $messages = [];
        foreach ($meta['fields'] as $field => $cfg) {
            if (!empty($cfg['required'])) {
                $rules[$field] = 'require';
                $messages[$field . '.require'] = ($cfg['label'] ?? $field) . '不能为空';
            }
        }
        if (!$rules) {
            return;
        }
        $validator = Validate::rule($rules)->message($messages);
        if (!$validator->check($data)) {
            throw new \InvalidArgumentException((string)$validator->getError());
        }
    }

    private function updateByKey(string $table, array $key, array $data): void
    {
        $sets = [];
        foreach ($data as $field => $value) {
            $sets[] = $field . ' = ' . $this->clickhouseValue($value);
        }
        if ($sets) {
            $this->clickhouseExec(sprintf(
                'ALTER TABLE %s UPDATE %s WHERE %s SETTINGS mutations_sync = 1',
                $table,
                implode(', ', $sets),
                $this->keyWhere($key)
            ));
        }
    }

    private function tableName(): string
    {
        $table = (string)($this->request->param('table') ?: 'platform_config');
        $this->meta($table);
        return $table;
    }

    private function meta(string $table): array
    {
        if (!isset($this->tableMetas[$table])) {
            throw new \InvalidArgumentException('不支持的配置表');
        }
        return $this->tableMetas[$table];
    }

    private function tableOptions(string $currentTable): array
    {
        $out = [];
        $groups = [
            // 与数据中心菜单分组保持一致，通用兼容入口也不能跨类切表。
            ['platform_config', 'collect_config', 'machine_collect_mapping'],
            ['sports_entity', 'sports_entity_source', 'sports_market_type_source', 'sports_event', 'sports_event_source', 'entity_mapping'],
            ['mapping_config', 'machine_mapping_mapping', 'mapping_target_event', 'mapping_target_market'],
        ];
        $order = [$currentTable];
        foreach ($groups as $group) {
            if (in_array($currentTable, $group, true)) {
                $order = $group;
                break;
            }
        }
        foreach ($order as $table) {
            if (!isset($this->tableMetas[$table])) {
                continue;
            }
            $meta = $this->tableMetas[$table];
            $out[$table] = ['title' => $meta['title'], 'path' => $this->pathFor($table)];
        }
        return $out;
    }

    private function pathFor(string $table): string
    {
        $paths = [
            'platform_config' => '/data/config_center/platform.html',
            'collect_config' => '/data/config_center/collect.html',
            'machine_collect_mapping' => '/data/config_center/collectMachine.html',
            'mapping_config' => '/data/config_center/mapping.html',
            'machine_mapping_mapping' => '/data/config_center/mappingMachine.html',
            'sports_entity' => '/data/config_center/sportsEntity.html',
            'sports_entity_source' => '/data/config_center/sportsEntitySource.html',
            'sports_market_type_source' => '/data/config_center/sportsMarketType.html',
            'sports_event' => '/data/config_center/sportsEvent.html',
            'sports_event_source' => '/data/config_center/sportsEventSource.html',
            'entity_mapping' => '/data/config_center/entity.html',
            'mapping_target_event' => '/data/config_center/targetEvent.html',
            'mapping_target_market' => '/data/config_center/targetMarket.html',
        ];
        return $paths[$table] ?? '/data/config_center/index.html?table=' . rawurlencode($table);
    }

    private function normalizeSportsMappingData(array &$data): void
    {
        foreach (['sport', 'entity_type'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = strtolower(trim($data[$field]));
            }
        }
        if (isset($data['source_platform']) && is_string($data['source_platform'])) {
            $data['source_platform'] = strtoupper(trim($data['source_platform']));
        }
        if (array_key_exists('source_entity_key', $data)) {
            $name = (string)($data['source_entity_key'] !== '' ? $data['source_entity_key'] : ($data['source_entity_name'] ?? ''));
            $data['source_entity_key'] = $this->sourceEntityKey($name);
        }
        if (array_key_exists('league_scope', $data)) {
            $data['league_scope'] = $this->sourceEntityKey((string)$data['league_scope']);
        }
        if (array_key_exists('country_scope', $data) && is_string($data['country_scope'])) {
            $data['country_scope'] = strtoupper(trim($data['country_scope']));
        }
        if (array_key_exists('country_code', $data) && is_string($data['country_code'])) {
            $data['country_code'] = strtoupper(trim($data['country_code']));
        }
        if (array_key_exists('source_market_type', $data)) {
            $data['source_market_type'] = $this->sourceEntityKey((string)$data['source_market_type']);
        }
        if (array_key_exists('canonical_market_type', $data) && is_string($data['canonical_market_type'])) {
            $data['canonical_market_type'] = strtoupper(trim($data['canonical_market_type']));
        }
        if (array_key_exists('canonical_period', $data) && trim((string)$data['canonical_period']) === '') {
            $data['canonical_period'] = 'full';
        }
    }

    private function sourceEntityKey(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        $name = strtr($name, [
            '&' => ' and ',
            '.' => ' ',
            ',' => ' ',
            "'" => '',
            '`' => '',
            '(' => ' ',
            ')' => ' ',
            '[' => ' ',
            ']' => ' ',
            '{' => ' ',
            '}' => ' ',
            '-' => ' ',
            '_' => ' ',
            '/' => ' ',
        ]);
        return trim(preg_replace('/\s+/', ' ', $name) ?: '');
    }

    private function sportsAssetTypes(): array
    {
        return ['football' => '足球', 'basketball' => '篮球', 'esports' => '电竞'];
    }

    private function sanitizeAssetType(string $assetType): string
    {
        return isset($this->sportsAssetTypes()[$assetType]) ? $assetType : '';
    }

    private function normalizedTable(string $assetType): string
    {
        $tables = [
            'football' => 'predict_football_normalized',
            'basketball' => 'predict_basketball_normalized',
            'esports' => 'predict_esports_normalized',
        ];
        if (!isset($tables[$assetType])) {
            throw new \InvalidArgumentException('不支持的体育类型');
        }
        return $tables[$assetType];
    }

    private function normalizedEvents(string $assetType, array $get): array
    {
        $where = [];
        foreach (['platform', 'competition_name', 'event_id', 'source_event_id'] as $field) {
            $value = trim((string)($get[$field] ?? ''));
            if ($value !== '') {
                $expr = $field === 'platform' ? 'upper(platform)' : $field;
                $where[] = sprintf("%s LIKE '%%%s%%'", $expr, $this->escapeClickHouseLike($field === 'platform' ? strtoupper($value) : $value));
            }
        }
        $startTime = trim((string)($get['start_time'] ?? ''));
        $endTime = trim((string)($get['end_time'] ?? ''));
        if ($startTime !== '' && $this->isDateTime($startTime)) {
            $where[] = "created_time >= toDateTime('" . $this->escapeClickHouseString($startTime) . "')";
        }
        if ($endTime !== '' && $this->isDateTime($endTime)) {
            $where[] = "created_time <= toDateTime('" . $this->escapeClickHouseString($endTime) . "')";
        }
        $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = max(10, min(100, (int)($get['pageSize'] ?? 30)));
        $sql = sprintf(
            "SELECT event_id, arrayStringConcat(arraySort(groupUniqArray(toString(source_event_id))), ',') AS source_event_id, any(competition_name) AS competition_name, formatDateTime(min(start_time), '%%F %%T') AS start_time, arraySort(groupUniqArray(upper(platform))) AS platforms, countDistinct(market_id) AS market_count, max(created_time) AS updated_time FROM %s FINAL%s GROUP BY event_id ORDER BY updated_time DESC LIMIT %d",
            $this->normalizedTable($assetType),
            $whereSQL,
            $limit
        );
        return $this->normalizeClickHouseRows($this->clickhouseSelect($sql));
    }

    private function normalizedMarkets(string $assetType, array $get): array
    {
        $where = [];
        foreach (['platform', 'event_id', 'market_id', 'market_type', 'source_market_id'] as $field) {
            $value = trim((string)($get[$field] ?? ''));
            if ($value !== '') {
                $expr = $field === 'platform' ? 'upper(platform)' : $field;
                $where[] = sprintf("%s LIKE '%%%s%%'", $expr, $this->escapeClickHouseLike($field === 'platform' ? strtoupper($value) : $value));
            }
        }
        $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = max(10, min(100, (int)($get['pageSize'] ?? 50)));
        $sql = sprintf(
            "SELECT event_id, market_id, arrayStringConcat(arraySort(groupUniqArray(toString(source_market_id))), ',') AS source_market_id, any(competition_name) AS competition_name, any(market_type) AS market_type, any(line) AS line, any(period) AS period, arraySort(groupUniqArray(upper(platform))) AS platforms, max(created_time) AS updated_time FROM %s FINAL%s GROUP BY event_id, market_id ORDER BY updated_time DESC LIMIT %d",
            $this->normalizedTable($assetType),
            $whereSQL,
            $limit
        );
        return $this->normalizeClickHouseRows($this->clickhouseSelect($sql));
    }

    private function findByKey(string $table, array $key): array
    {
        $rows = $this->clickhouseSelect(sprintf(
            'SELECT * FROM %s FINAL WHERE %s LIMIT 1',
            $table,
            $this->keyWhere($key)
        ));
        return $rows[0] ?? [];
    }

    private function findOneByFields(string $table, array $fields): array
    {
        $where = [];
        foreach ($fields as $field => $value) {
            $where[] = $field . ' = ' . $this->clickhouseValue($value);
        }
        $rows = $this->clickhouseSelect(sprintf(
            'SELECT * FROM %s FINAL WHERE %s LIMIT 1',
            $table,
            implode(' AND ', $where)
        ));
        return $rows[0] ?? [];
    }

    private function insertRow(string $table, array $data): void
    {
        $cols = array_keys($data);
        $values = array_map(fn($value) => $this->clickhouseValue($value), array_values($data));
        $this->clickhouseExec(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $values)
        ));
    }

    private function deleteByKey(string $table, array $key): void
    {
        $this->clickhouseExec(sprintf(
            'ALTER TABLE %s DELETE WHERE %s SETTINGS mutations_sync = 1',
            $table,
            $this->keyWhere($key)
        ));
    }

    /**
     * 复合业务键 → WHERE 条件。键的列名只来自 tableMetas['pk'] 白名单，值统一转义。
     */
    private function keyWhere(array $key): string
    {
        $parts = [];
        foreach ($key as $col => $value) {
            $parts[] = $col . ' = ' . $this->clickhouseValue($value);
        }
        return implode(' AND ', $parts);
    }

    /**
     * 行数据 → 复合键值数组（定位已查到的一行）。
     */
    private function keyFromRow(array $pk, array $row): array
    {
        $out = [];
        foreach ($pk as $col) {
            if (!array_key_exists($col, $row)) {
                return [];
            }
            $out[$col] = (string)$row[$col];
        }
        return $out;
    }

    /**
     * 行数据 → URL 安全的复合键（base64url）。
     */
    private function rowKey(array $pk, array $row): string
    {
        $pair = [];
        foreach ($pk as $col) {
            $pair[$col] = (string)($row[$col] ?? '');
        }
        return rtrim(strtr(base64_encode((string)json_encode($pair, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * 展示用的主键摘要，多列以顿号拼接后截断。
     */
    private function rowKeyLabel(array $pk, array $row): string
    {
        $parts = [];
        foreach ($pk as $col) {
            $value = trim((string)($row[$col] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $label = implode('、', $parts);
        if ($label === '') {
            return '-';
        }
        return mb_strlen($label) > 28 ? mb_substr($label, 0, 28) . '…' : $label;
    }

    /**
     * 解析复合键，只接受该表 pk 白名单内的列；缺列返回空数组。
     */
    private function decodeRowKey(string $table, string $key): array
    {
        if ($key === '') {
            return [];
        }
        $meta = $this->meta($table);
        $pk = $meta['pk'] ?? [];
        if (!$pk) {
            return [];
        }
        $raw = base64_decode(strtr($key, '-_', '+/'), true);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($pk as $col) {
            if (!array_key_exists($col, $data) || !is_scalar($data[$col])) {
                return [];
            }
            $out[$col] = (string)$data[$col];
        }
        return $out;
    }

    private function clickhouseValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . $this->escapeClickHouseString((string)$value) . "'";
    }

    private function clickhouseExec(string $sql): void
    {
        $this->clickhouseRequest($sql);
    }

    private function normalizeClickHouseRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (isset($row['platforms']) && is_array($row['platforms'])) {
                $row['platforms_text'] = implode(',', $row['platforms']);
            } else {
                $row['platforms_text'] = (string)($row['platforms'] ?? '');
            }
        }
        return $rows;
    }

    private function clickhouseSelect(string $sql): array
    {
        $cfg = config('database.clickhouse', []);
        [$addrHost, $addrPort] = $this->parseClickHouseAddr((string)($cfg['addr'] ?? ''));
        $host = (string)($cfg['host'] ?? ($addrHost ?: '127.0.0.1'));
        $httpPort = (int)($cfg['http_port'] ?? 0);
        if ($httpPort <= 0) {
            $nativePort = (int)($cfg['port'] ?? ($addrPort ?: 9000));
            $httpPort = $nativePort === 9000 ? 8123 : $nativePort;
        }
        $headers = ['Content-Type: text/plain; charset=utf-8'];
        $user = (string)($cfg['username'] ?? $cfg['user'] ?? '');
        if ($user !== '') {
            $headers[] = 'X-ClickHouse-User: ' . $user;
            $headers[] = 'X-ClickHouse-Key: ' . ($cfg['password'] ?? '');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $sql . ' FORMAT JSON',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $url = sprintf('http://%s:%d/?database=%s', $host, $httpPort, rawurlencode((string)($cfg['database'] ?? 'default')));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('ClickHouse 查询失败，请检查连接配置');
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('ClickHouse 返回非 JSON: ' . substr($body, 0, 200));
        }
        return $json['data'] ?? [];
    }

    private function clickhouseRequest(string $sql): string
    {
        $cfg = config('database.clickhouse', []);
        [$addrHost, $addrPort] = $this->parseClickHouseAddr((string)($cfg['addr'] ?? ''));
        $host = (string)($cfg['host'] ?? ($addrHost ?: '127.0.0.1'));
        $httpPort = (int)($cfg['http_port'] ?? 0);
        if ($httpPort <= 0) {
            $nativePort = (int)($cfg['port'] ?? ($addrPort ?: 9000));
            $httpPort = $nativePort === 9000 ? 8123 : $nativePort;
        }
        $headers = ['Content-Type: text/plain; charset=utf-8'];
        $user = (string)($cfg['username'] ?? $cfg['user'] ?? '');
        if ($user !== '') {
            $headers[] = 'X-ClickHouse-User: ' . $user;
            $headers[] = 'X-ClickHouse-Key: ' . ($cfg['password'] ?? '');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $sql,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $url = sprintf('http://%s:%d/?database=%s', $host, $httpPort, rawurlencode((string)($cfg['database'] ?? 'default')));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('ClickHouse request failed');
        }
        return $body;
    }

    private function parseClickHouseAddr(string $addr): array
    {
        $addr = trim($addr);
        if ($addr === '') {
            return ['', 0];
        }
        $parts = explode(':', $addr, 2);
        return [$parts[0], isset($parts[1]) ? (int)$parts[1] : 0];
    }

    private function escapeClickHouseLike(string $value): string
    {
        return str_replace(["\\", "'", "%", "_"], ["\\\\", "\\'", "\\%", "\\_"], substr($value, 0, 128));
    }

    private function escapeClickHouseString(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private function isDateTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1;
    }

    /** @return array<int|string> */
    private function paginationItems(int $page, int $pages): array
    {
        if ($pages <= 7) return range(1, $pages);
        $start = max(2, $page - 2);
        $end = min($pages - 1, $page + 2);
        $items = [1];
        if ($start > 2) $items[] = '…';
        for ($i = $start; $i <= $end; $i++) $items[] = $i;
        if ($end < $pages - 1) $items[] = '…';
        $items[] = $pages;
        return $items;
    }

    private function pageHtml(string $table, array $get, int $page, int $pageSize, int $total): string
    {
        if ($total <= $pageSize) return '';
        $pages = (int)ceil($total / $pageSize);
        $page = min(max(1, $page), $pages);
        $html = '<ul class="pagination">';
        if ($page > 1) $html .= '<li><a href="' . $this->pageUrl($table, $get, $page - 1, $pageSize) . '">上一页</a></li>';
        foreach ($this->paginationItems($page, $pages) as $item) {
            if ($item === '…') $html .= '<li class="disabled"><span>…</span></li>';
            elseif ($item === $page) $html .= '<li class="active"><span>' . $item . '</span></li>';
            else $html .= '<li><a href="' . $this->pageUrl($table, $get, $item, $pageSize) . '">' . $item . '</a></li>';
        }
        if ($page < $pages) $html .= '<li><a href="' . $this->pageUrl($table, $get, $page + 1, $pageSize) . '">下一页</a></li>';
        return $html . '</ul>';
    }

    private function pageUrl(string $table, array $get, int $page, int $pageSize): string
    {
        $get['table'] = $table;
        $get['page'] = $page;
        $get['pageSize'] = $pageSize;
        return '/admin.html#' . $this->pathFor($table) . '?' . http_build_query($get);
    }

    private function nullDateTime(string $value): ?string
    {
        $value = trim($value);
        return $this->isDateTime($value) ? $value : null;
    }

    private function csvValues(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''));
    }

    private function csvPlatformValues(string $value): array
    {
        return array_values(array_unique(array_map('strtoupper', $this->csvValues($value))));
    }

    private function searchLabels(array $meta): array
    {
        $labels = [
            'pageSize' => '每页条数',
        ];
        foreach ($meta['search'] as $field) {
            $labels[$field] = $meta['fields'][$field]['label'] ?? $this->fallbackSearchLabel($field);
        }
        return $labels;
    }

    private function fallbackSearchLabel(string $field): string
    {
        return [
            'collector_name' => '采集名称',
            'source_platforms' => '源平台',
            'output_normalized_table' => '输出宽表',
            'strategy_index' => '分片序号',
            'total_strategies' => '总分片数',
        ][$field] ?? $field;
    }

    private function normalizeJson(string $value, bool $required = false): string
    {
        $value = trim($value);
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException('JSON 配置不能为空');
            }
            return '{}';
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON 格式错误: ' . json_last_error_msg());
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function shortJson(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        if (strlen($value) <= 80) {
            return $value;
        }
        return substr($value, 0, 80) . '...';
    }

    private function sanitizeLike(string $value, int $maxLength = 100): string
    {
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

}
