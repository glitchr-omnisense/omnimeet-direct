# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnimeet Direct: a video meeting from browser to browser (WebRTC) - two
people, encrypted end to end, no third party, nothing recorded. The handshake's rules on a store the
application provides, temporary TURN credentials for one's own relay (coturn's use-auth-secret), and the
JavaScript engine, with no dependency.

### Release

- Requires `omnisense/omnimeet: ^1.0`: stable versions, so `composer require omnimeet/direct` installs
  in a project at Composer's default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the
  CI (`.github/workflows/ci.yml`, glitchr-studio/ci's php-package.yml) is callable, and `release.yml`
  runs it on a tag before glitchr-studio/ci's php-release.yml.
