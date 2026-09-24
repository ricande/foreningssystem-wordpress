.PHONY: help up down install snap restore wp test

help:
	@printf '%s\n' \
		'make up' \
		'make down' \
		'make install' \
		'make snap name=ren-install' \
		'make restore name=ren-install' \
		'make wp plugin list' \
		'make test' \
		'Mailpit: http://localhost:8025'

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
	docker run --rm --user "$(shell id -u):$(shell id -g)" -e COMPOSER_HOME=/tmp/composer -v "$(CURDIR)":/app -w /app composer:2 composer install --no-interaction
	docker run --rm --user "$(shell id -u):$(shell id -g)" -v "$(CURDIR)":/app -w /app php:8.3-cli vendor/bin/phpunit
