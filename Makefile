.PHONY: setup start serve frontend install migrate seed clear docker-up docker-down docker-setup

# Default target
help:
	@echo "Available commands:"
	@echo "  make setup       - Initial setup (copy .env, install deps, key generate, migrate & seed)"
	@echo "  make start       - Start both backend (artisan serve) and frontend (vite) servers concurrently"
	@echo "  make serve       - Start backend server only (php artisan serve)"
	@echo "  make frontend    - Start frontend server only (npm run dev)"
	@echo "  make install     - Install Composer and NPM dependencies"
	@echo "  make migrate     - Run database migrations"
	@echo "  make seed        - Run database seeders"
	@echo "  make clear       - Clear application caches"
	@echo "  make docker-up   - Start services using Docker"
	@echo "  make docker-down - Stop Docker services"

# Local Development Commands
setup:
	cp -n .env.example .env || true
	composer install
	npm install
	php artisan key:generate
	php artisan migrate --seed

start:
	@echo "Starting backend and frontend services..."
	@make -j 2 serve frontend

serve:
	php artisan serve

frontend:
	npm run dev

install:
	composer install
	npm install

migrate:
	php artisan migrate

seed:
	php artisan db:seed

clear:
	php artisan optimize:clear
	php artisan cache:clear
	php artisan config:clear
	php artisan view:clear
	php artisan route:clear

# Docker Commands
docker-up:
	docker-compose up -d --build

docker-down:
	docker-compose down

docker-setup:
	docker compose exec app composer install --optimize-autoloader --no-dev
	docker compose exec app php artisan migrate --force
	docker compose exec app php artisan config:cache
	docker compose exec app php artisan route:cache
	docker compose exec app php artisan view:cache
	docker compose exec app chmod -R 775 storage bootstrap/cache
