.PHONY: help up down install snap restore wp test package release-test release-test-down \
	clean-lab-up clean-lab-down clean-lab-install clean-lab-snap clean-lab-restore

CLEAN_LAB := labs/wordpress-clean

help:
	@printf '%s\n' \
		'make up' \
		'make down' \
		'make install' \
		'make snap name=ren-install' \
		'make restore name=ren-install' \
		'make wp plugin list' \
		'make test' \
		'make package' \
		'make release-test' \
		'make release-test-down' \
		'make clean-lab-up' \
		'make clean-lab-install' \
		'make clean-lab-snap' \
		'make clean-lab-restore' \
		'Mailpit: http://localhost:8025' \
		'Release scratch site: http://localhost:8090' \
		'Clean baseline: labs/wordpress-clean (ports in its .env; default 8088/8025)'

up:
	docker compose up -d --wait

down:
	docker compose down

install:
	bash scripts/install.sh

snap:
	bash scripts/snapshot.sh snap "$(name)"

restore:
	bash scripts/snapshot.sh restore "$(name)"

ifeq (wp,$(firstword $(MAKECMDGOALS)))
  WP_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
  .PHONY: $(WP_ARGS)
  $(eval $(WP_ARGS):;@:)
endif

wp:
	docker compose run --rm wpcli $(WP_ARGS)

test:
	@if [ ! -x vendor/bin/phpunit ]; then \
		docker run --rm --user "$(shell id -u):$(shell id -g)" -e COMPOSER_HOME=/tmp/composer -v "$(CURDIR)":/app -w /app composer:2 composer install --no-interaction; \
	fi
	docker run --rm --user "$(shell id -u):$(shell id -g)" -v "$(CURDIR)":/app -w /app php:8.3-cli vendor/bin/phpunit
	bash scripts/test-lab.sh

package:
	bash scripts/build-plugin-zip.sh

release-test:
	bash scripts/test-release-install.sh

release-test-down:
	docker compose -p foreningsplugin-release-test -f docker-compose.release-test.yml down -v --remove-orphans

# Separate clean WordPress baseline (no plugin bind-mount). See labs/wordpress-clean/README.md
clean-lab-up:
	@if [ ! -f $(CLEAN_LAB)/.env ]; then cp $(CLEAN_LAB)/.env.example $(CLEAN_LAB)/.env; fi
	docker compose --project-directory $(CLEAN_LAB) --env-file $(CLEAN_LAB)/.env up -d db mailpit wordpress

clean-lab-down:
	@if [ ! -f $(CLEAN_LAB)/.env ]; then cp $(CLEAN_LAB)/.env.example $(CLEAN_LAB)/.env; fi
	docker compose --project-directory $(CLEAN_LAB) --env-file $(CLEAN_LAB)/.env down

clean-lab-install:
	bash $(CLEAN_LAB)/scripts/install.sh

clean-lab-snap:
	bash $(CLEAN_LAB)/scripts/snapshot.sh

clean-lab-restore:
	bash $(CLEAN_LAB)/scripts/restore.sh
