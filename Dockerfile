ARG \
  DOCKER_REPOSITORY \
  TAG

FROM ${DOCKER_REPOSITORY}/drupal:${TAG}

ARG TARGETARCH

COPY --chown=nginx:nginx assets /var/www/drupal/assets
COPY --chown=nginx:nginx recipes /var/www/drupal/recipes
COPY --chown=nginx:nginx web /var/www/drupal/web
COPY --chown=nginx:nginx composer.json composer.lock /var/www/drupal/

RUN --mount=type=cache,id=custom-drupal-composer-${TARGETARCH},sharing=locked,target=/root/.composer/cache \
    composer install && \
    chown -R nginx:nginx . && \
    cleanup.sh

# now copy in files that don't effect composer
COPY config /var/www/drupal/config
COPY --link rootfs /
