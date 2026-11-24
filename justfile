php-bin := if env('CI', 'false') == 'true' { 'php' } else { 'php8.4' }
compose := 'docker compose'
compose-run := compose + ' run --rm --user "' + `id -u` + ':' + `id -g` + '" test-php'

[private]
default:
    just --list --unsorted

[group('Static Analysis')]
[doc('Run static checks')]
check: php-cs-fixer-check phpcs phpstan

[group('Static Analysis')]
[doc('Fix problems found by static checks')]
fix: php-cs-fixer-fix phpcbf

[group('Static Analysis')]
[doc('Run php-cs-fixer check')]
php-cs-fixer-check:
    {{php-bin}} ./vendor/bin/php-cs-fixer check -v

[group('Static Analysis')]
[doc('Run php-cs-fixer fix')]
php-cs-fixer-fix:
    {{php-bin}} ./vendor/bin/php-cs-fixer fix -v

[group('Static Analysis')]
[doc('Run phpstan')]
phpstan:
    {{php-bin}} ./vendor/bin/phpstan analyse

[group('Static Analysis')]
[doc('Generate phpstan baseline')]
phpstan-baseline:
    {{php-bin}} ./vendor/bin/phpstan analyse --generate-baseline

[group('Static Analysis')]
[doc('Run phpcs')]
phpcs:
    {{php-bin}} ./vendor/bin/phpcs

[group('Static Analysis')]
[doc('Run phpcbf')]
phpcbf:
    {{php-bin}} ./vendor/bin/phpcbf

[group('Test')]
[doc('Run phpunit')]
test *ARGS:
    {{php-bin}} ./vendor/bin/phpunit {{ ARGS }}

[group('Test')]
[doc('Run functional tests in a self-contained Neos test distribution with MariaDB. Builds the distribution first when it is missing or its composer.json changed.')]
test-functional *ARGS:
    cmp -s Tests/TestDistribution/composer.json .test-distribution/.built-from-composer.json || just build-test-distribution
    {{compose-run}} sh -c 'cd .test-distribution && ./flow doctrine:migrate --quiet && php Packages/Libraries/bin/phpunit -c phpunit-functional.xml {{ ARGS }}'

[group('Test')]
[doc('Build or refresh the throwaway Neos distribution used by the functional tests')]
build-test-distribution:
    mkdir -p .test-distribution/Configuration/Testing
    cp Tests/TestDistribution/composer.json Tests/TestDistribution/phpunit-functional.xml .test-distribution/
    cp Tests/TestDistribution/Configuration/Testing/Settings.yaml .test-distribution/Configuration/Testing/
    {{compose}} build test-php
    # update, not install: like CI, resolve from composer.json, so a dependency added there
    # (or to this package) reaches an existing distribution instead of failing on its stale lock
    {{compose-run}} composer update --working-dir=.test-distribution --no-interaction --no-progress
    # Written only after a successful update, so test-functional retries a build that failed
    cp Tests/TestDistribution/composer.json .test-distribution/.built-from-composer.json

[group('Test')]
[doc('Delete and rebuild the functional test distribution')]
rebuild-test-distribution: clean-test-distribution build-test-distribution

[group('Test')]
[doc('Start the containers of the functional test setup')]
test-distribution-up:
    {{compose}} up -d

[group('Test')]
[doc('Stop the containers of the functional test setup')]
test-distribution-down:
    {{compose}} down

[private]
clean-test-distribution:
    rm -rf .test-distribution
