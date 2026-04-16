# Islandora Events PoC

> [!NOTE]
> This repository is a copy of [ISLE Site Template](https://github.com/islandora-devops/isle-site-template)
> Modified to test a proposed implementation that could satisfy [islandora/documentation#2395](https://github.com/Islandora/documentation/issues/2395) and [islandora/documentation#2390](https://github.com/Islandora/documentation/issues/2390)


## Quick Start

```bash
git clone git@github.com/Islandora-Labs/islandora-events
cd islandora-events
make up
```

If your making code changes to [./web/modules/custom/islandora_events](./web/modules/custom/islandora_events) changes made on your host machine will reflect on the website immediately. You can run `drush` commands with

```
make drush cr
```

## What's Changed?

Notable changes to this copy of ISLE Site Template:

1. A Drupal module named [islandora_events](./web/modules/custom/islandora_events) is included as custom module in this repo. If this approach is adopted, this will become a Drupal module at https://www.drupal.org/project/islandora_events and https://github.com/islandora/islandora_events
2. `islandora_events` requires three Symfony Messenger Drupal Modules: [drupal/sm](https://www.drupal.org/project/sm) and two modules that will eventually become Drupal modules should this approach be adopted [drupal/sm_ledger](https://github.com/lehigh-university-libraries/sm_ledger) and [drupal/sm_workers](https://github.com/lehigh-university-libraries/sm_workers)
3. ActiveMQ, Alpaca, Milliner, and all the scyllaridae microservices have been removed from the [docker-compose.yml](./docker-compose.yml). Instead we're using s6-overlay to create symfony messenger workers that make calls to scyllaridae definitions baked into the drupal container. This allows us to ship what was once a set of scyllaridae microservices, activemq, and alpaca and instead ship a single drupal container. In this repo this is done with `ISLANDORA_TAG=php-pecl-amp` which is built from this PR: https://github.com/Islandora-Devops/isle-buildkit/pull/560


## Next Steps

- Community Feedback
- Testing
- Stress Testing
    - Tooling/guidance on swapping database transport with activemq
- PRs for `islandora_events` can go on this repo
- PRs for the new monolith drupal container can go onto https://github.com/Islandora-Devops/isle-buildkit/pull/560 // the ISLE Buildkit branch `php-pecl-amp`
- PRs for `sm_workers` and `sm_ledger` are welcome but these don't necessarily need to be taken on by the Islandora Community. If there is a desire, we can move those over, too. But those were written so other Drupal projects could build off them if they desire (and that's what `islandora_events` does)
- Build out documentation


## Where to discuss this?

- [Weekly Tech Call](https://github.com/Islandora/islandora-community/wiki/Weekly-Open-Tech-Call)
- Islandora Slack `#islandora-events`
- GitHub PRs or Issues on this repo https://github.com/Islandora-Labs/islandora-events

## Composer overrides

Bring in the two drupal modules that are not proper Drupal Modules yet (to mimic how it would be done if this was a drupal module at `composer require drupal/sm_ledger drupal/sm_workers`)
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/lehigh-university-libraries/sm_ledger"
        },
        {
            "type": "vcs",
            "url": "https://github.com/lehigh-university-libraries/sm_workers"
        },
```

Bring in the composer.json defined in islandora_events (how it would be done if this was a drupal module at `composer require drupal/islandora_events`)

```json
        {
            "type": "path",
            "url": "web/modules/custom/*"
        },
    ],
    "require": {
        "drupal/islandora_events": "1.x-dev",
```

A hack to avoid Islandora from emitting to ActiveMQ (which is not being shipped here) when events are created by Drupal Actions

```json
    "extra": {
        "patches": {
            "drupal/islandora": {
                "events": "assets/patches/drupal/islandora/1100.patch"
            },
```
