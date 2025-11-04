ENV ?= testing
BASE_COMPOSE = -f docker-compose.yaml
COMPOSE_FILE = $(BASE_COMPOSE) -f docker-compose.$(ENV).yaml
ENV_FILE = --env-file .env.$(ENV)

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

deploy-testing:
	@$(MAKE) up ENV=testing

deploy-prod:
	@$(MAKE) up ENV=prod

stop-testing:
	@$(MAKE) down ENV=testing

stop-prod:
	@$(MAKE) down ENV=prod
