DRUPAL_VERSION ?= 11.3
PHP_VERSION ?= 8.3
TEST_SUITE ?=
ENABLE_MODULES ?=islandora_events
DOCKER_NETWORK ?= ci-default
CI_IMAGE ?= ghcr.io/islandora/ci:$(DRUPAL_VERSION)-php$(PHP_VERSION)

.PHONY: test test-unit test-kernel test-functional network-up network-down

test: network-up
	docker run \
		--rm \
		--name drupal \
		--hostname drupal \
		--volume "$(CURDIR):/var/www/drupal/web/modules/contrib/islandora_events:ro" \
		--env ENABLE_MODULES="$(ENABLE_MODULES)" \
		--env TEST_SUITE="$(TEST_SUITE)" \
		--network $(DOCKER_NETWORK) \
		$(CI_IMAGE)

test-unit:
	$(MAKE) test TEST_SUITE=unit

test-kernel:
	$(MAKE) test TEST_SUITE=kernel

test-functional:
	$(MAKE) test TEST_SUITE=functional

network-up:
	@docker network inspect $(DOCKER_NETWORK) >/dev/null 2>&1 || docker network create $(DOCKER_NETWORK)

network-down:
	@docker network rm $(DOCKER_NETWORK) >/dev/null 2>&1 || true
