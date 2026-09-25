.PHONY: help up down install snap restore wp test package release-test release-test-down

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
		'Mailpit: http://localhost:8025' \
		'Release scratch site: http://localhost:8090'

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
