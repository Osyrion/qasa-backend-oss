.PHONY: help up down build restart shell composer artisan tinker test phpstan psalm fresh logs

# Default target
help:
	@echo "Laravel Docker - Available commands:"
	@echo ""
	@echo "  Docker:"
	@echo "    make up          - Start all containers"
	@echo "    make down        - Stop all containers"
	@echo "    make build       - Build containers"
	@echo "    make restart     - Restart all containers"
	@echo "    make logs        - Show container logs"
	@echo ""
	@echo "  App:"
	@echo "    make shell       - Open shell in app container"
	@echo "    make composer    - Run composer (e.g. make composer cmd='require package')"
	@echo "    make artisan     - Run artisan (e.g. make artisan cmd='migrate')"
	@echo "    make tinker      - Open Laravel Tinker"
	@echo "    make fresh       - Fresh migration with seeders"
	@echo ""
	@echo "  Testing & Analysis:"
	@echo "    make test        - Run Pest tests"
	@echo "    make test-cover  - Run tests with coverage"
	@echo "    make phpstan     - Run PHPStan analysis"
	@echo "    make psalm       - Run Psalm analysis"
	@echo "    make analyse     - Run all static analysis"

# Docker commands
up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build --no-cache

restart:
	docker compose restart

logs:
	docker compose logs -f

# App commands
shell:
	docker compose exec app bash

composer:
	docker compose exec app composer $(cmd)

artisan:
	docker compose exec app php artisan $(cmd)

tinker:
	docker compose exec app php artisan tinker

fresh:
	docker compose exec app php artisan migrate:fresh --seed

# Install Laravel fresh project
install:
	docker compose exec app composer install
	docker compose exec app cp .env.example .env
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate

# Testing
test:
	docker compose exec app php artisan test

pest:
	docker compose exec app ./vendor/bin/pest

test-cover:
	docker compose exec app php artisan test --coverage

test-filter:
	docker compose exec app php artisan test --filter=$(filter)

# Static analysis
phpstan:
	docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=512M

psalm:
	docker compose exec app ./vendor/bin/psalm

analyse: phpstan psalm
	@echo "Static analysis complete!"
