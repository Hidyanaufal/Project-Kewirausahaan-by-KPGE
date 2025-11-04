ENV ?= testing

ifeq ($(ENV),testing)
	COMPOSE_FILE = -f docker-compose.yaml
else
	COMPOSE_FILE = -f docker-compose.$(ENV).yaml
endif

up:
	@echo "🚀 Starting $(ENV) environment..."
	docker compose $(COMPOSE_FILE) up -d --build

down:
	@echo "🧹 Stopping $(ENV) environment..."
	docker compose $(COMPOSE_FILE) down

restart:
	@echo "🔁 Restarting $(ENV) environment..."
	docker compose $(COMPOSE_FILE) down
	docker compose $(COMPOSE_FILE) up -d --build

logs:
	@echo "📜 Showing logs for $(ENV)..."
	docker compose $(COMPOSE_FILE) logs -f

ps:
	@echo "📦 Containers for $(ENV):"
	docker compose $(COMPOSE_FILE) ps

deploy-testing:
	@$(MAKE) up ENV=testing

deploy-prod:
	@$(MAKE) up ENV=prod

stop-testing:
	@$(MAKE) down ENV=testing

stop-prod:
	@$(MAKE) down ENV=prod
