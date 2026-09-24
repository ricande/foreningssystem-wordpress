.PHONY: help up down install snap restore wp

help:
	@printf '%s\n' \
		'make up' \
		'make down' \
		'make install' \
		'make snap name=ren-install' \
		'make restore name=ren-install' \
		'make wp plugin list'

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
