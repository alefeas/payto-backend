#!/bin/bash

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}=== Payto Backend Test Suite ===${NC}\n"

# Check if argument is provided
if [ -z "$1" ]; then
    echo -e "${YELLOW}Usage:${NC}"
    echo "  ./run-tests.sh all          - Run all tests"
    echo "  ./run-tests.sh unit         - Run unit tests only"
    echo "  ./run-tests.sh feature      - Run feature tests only"
    echo "  ./run-tests.sh repositories - Run repository tests only"
    echo "  ./run-tests.sh controllers  - Run controller tests only"
    echo "  ./run-tests.sh coverage     - Run tests with coverage"
    echo "  ./run-tests.sh [file]       - Run specific test file"
    echo ""
    exit 0
fi

case "$1" in
    all)
        echo -e "${BLUE}Running all tests...${NC}\n"
        php artisan test
        ;;
    unit)
        echo -e "${BLUE}Running unit tests...${NC}\n"
        php artisan test tests/Unit
        ;;
    feature)
        echo -e "${BLUE}Running feature tests...${NC}\n"
        php artisan test tests/Feature
        ;;
    repositories)
        echo -e "${BLUE}Running repository tests...${NC}\n"
        php artisan test tests/Unit/Repositories
        ;;
    controllers)
        echo -e "${BLUE}Running controller tests...${NC}\n"
        php artisan test tests/Feature
        ;;
    coverage)
        echo -e "${BLUE}Running tests with coverage...${NC}\n"
        php artisan test --coverage
        ;;
    *)
        echo -e "${BLUE}Running tests for: $1${NC}\n"
        php artisan test "$1"
        ;;
esac

echo -e "\n${GREEN}✓ Test run completed${NC}"
