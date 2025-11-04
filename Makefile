# ======================================================
# 🐳 Docker Environment Management
# ======================================================

# Default environment (bisa dioverride, ex: make deploy ENV=stag)
ENV ?= dev

# Compose files
BASE_COMPOSE = -f docker-compose.yml
COMPOSE_FILE = $(BASE_COMPOSE) -f docker-compose.$(ENV).yml
ENV_FILE = --env-file .env.$(ENV)

# ======================================================
# 🧱 Basic Commands
# ======================================================

up:
	@echo "🚀 Starting $(ENV) environment..."
	docker compose $(COMPOSE_FILE) $(ENV_FILE) up -d --build

down:
	@echo "🧹 Stopping $(ENV) environment..."
	docker compose $(COMPOSE_FILE) $(ENV_FILE) down

restart:
	@echo "🔁 Restarting $(ENV) environment..."
	docker compose $(COMPOSE_FILE) $(ENV_FILE) down
	docker compose $(COMPOSE_FILE) $(ENV_FILE) up -d --build

logs:
	@echo "📜 Showing logs for $(ENV)..."
	docker compose $(COMPOSE_FILE) $(ENV_FILE) logs -f

ps:
	@echo "📦 Containers for $(ENV):"
	docker compose $(COMPOSE_FILE) $(ENV_FILE) ps

# ======================================================
# 🚀 Quick Deploy Commands
# ======================================================

deploy-dev:
	@$(MAKE) up ENV=dev

deploy-stag:
	@$(MAKE) up ENV=stag

deploy-prod:
	@$(MAKE) up ENV=prod

stop-dev:
	@$(MAKE) down ENV=dev

stop-stag:
	@$(MAKE) down ENV=stag

stop-prod:
	@$(MAKE) down ENV=prod

# ======================================================
# 🧹 Cleanup
# ======================================================

clean:
	@echo "🧨 Removing all containers, networks, and volumes..."
	docker-compose down -v --remove-orphans
	docker system prune -f
