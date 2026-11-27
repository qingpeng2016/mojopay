#!/bin/bash

# CoolPay 定时任务设置脚本
# 使用方法: bash setup_cron.sh

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 获取当前脚本所在目录的上级目录（项目根目录）
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN=$(which php)

echo -e "${BLUE}=== CoolPay 定时任务设置 ===${NC}"
echo "项目根目录: $PROJECT_ROOT"
echo "PHP路径: $PHP_BIN"
echo ""

# 检查PHP是否存在
if [ ! -f "$PHP_BIN" ]; then
    echo -e "${RED}错误: 找不到PHP可执行文件${NC}"
    exit 1
fi

# 检查think命令是否存在
if [ ! -f "$PROJECT_ROOT/think" ]; then
    echo -e "${RED}错误: 找不到think命令文件${NC}"
    exit 1
fi

# 定义定时任务
declare -A CRON_JOBS=(
    # 每天凌晨12:01执行主机服务数据获取
    ["hosting"]="1 0 * * * cd $PROJECT_ROOT && $PHP_BIN think coolpay:scheduled hosting >> $PROJECT_ROOT/runtime/log/cron_hosting.log 2>&1"
    # 每分钟执行测试任务（方便测试）
    ["test"]="* * * * * cd $PROJECT_ROOT && $PHP_BIN think coolpay:scheduled test >> $PROJECT_ROOT/runtime/log/cron_test.log 2>&1"
)

# 创建日志目录
mkdir -p "$PROJECT_ROOT/runtime/log"

# 显示菜单
show_menu() {
    echo -e "${YELLOW}请选择操作:${NC}"
    echo "1. 添加主机服务任务 (每天00:01执行)"
    echo "2. 添加测试任务 (每分钟执行)"
    echo "3. 查看当前定时任务"
    echo "4. 删除CoolPay定时任务"
    echo "5. 手动执行主机服务任务"
    echo "6. 手动执行测试任务"
    echo "0. 退出"
    echo ""
}

# 添加定时任务
add_cron_job() {
    local job_name=$1
    local cron_command="${CRON_JOBS[$job_name]}"
    
    if [ -z "$cron_command" ]; then
        echo -e "${RED}错误: 未知的任务类型 $job_name${NC}"
        return 1
    fi
    
    # 检查是否已存在
    if crontab -l 2>/dev/null | grep -q "coolpay:scheduled $job_name"; then
        echo -e "${YELLOW}任务 $job_name 已存在，跳过...${NC}"
        return 0
    fi
    
    # 添加到crontab
    (crontab -l 2>/dev/null; echo "$cron_command") | crontab -
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ 成功添加定时任务: $job_name${NC}"
    else
        echo -e "${RED}✗ 添加定时任务失败: $job_name${NC}"
    fi
}

# 查看当前定时任务
show_current_cron() {
    echo -e "${BLUE}=== 当前的CoolPay定时任务 ===${NC}"
    crontab -l 2>/dev/null | grep "coolpay:scheduled\|xadmin:queue" || echo "没有找到CoolPay相关的定时任务"
    echo ""
}

# 删除所有CoolPay定时任务
remove_all_cron() {
    echo -e "${YELLOW}正在删除所有CoolPay定时任务...${NC}"
    crontab -l 2>/dev/null | grep -v "coolpay:scheduled\|xadmin:queue" | crontab -
    echo -e "${GREEN}✓ 已删除所有CoolPay定时任务${NC}"
}

# 手动执行主机服务任务
run_hosting() {
    echo -e "${BLUE}手动执行主机服务任务...${NC}"
    cd "$PROJECT_ROOT"
    php think coolpay:scheduled hosting
    echo ""
}

# 手动执行测试
run_test() {
    echo -e "${BLUE}手动执行定时任务测试...${NC}"
    cd "$PROJECT_ROOT"
    php think coolpay:scheduled test
    echo ""
}

# 主循环
while true; do
    show_menu
    read -p "请输入选项 (0-6): " choice
    
    case $choice in
        1)
            add_cron_job "hosting"
            ;;
        2)
            add_cron_job "test"
            ;;
        3)
            show_current_cron
            ;;
        4)
            read -p "确定要删除所有CoolPay定时任务吗? (y/N): " confirm
            if [[ $confirm =~ ^[Yy]$ ]]; then
                remove_all_cron
            fi
            ;;
        5)
            run_hosting
            ;;
        6)
            run_test
            ;;
        0)
            echo -e "${BLUE}退出设置${NC}"
            break
            ;;
        *)
            echo -e "${RED}无效选项，请重新输入${NC}"
            ;;
    esac
    
    echo ""
    read -p "按Enter键继续..." dummy
    clear
done

echo -e "${GREEN}=== 设置完成 ===${NC}"
echo "日志文件位置: $PROJECT_ROOT/runtime/log/cron_test.log"
echo "手动执行命令: php think coolpay:scheduled test"
echo "查看日志: tail -f runtime/log/cron_test.log"
