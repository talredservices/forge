# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.4] - 2026-09-28
### Added
- Added additive `Talred\...` namespace aliases backed by the existing `Zolta\...` implementation.
- Renamed the public Composer package coordinate to `talred/forge` while declaring `zolta/forge` as a replaced package for dependency compatibility.

### Changed
- Updated public README and documentation examples to use the Talred package coordinate and namespaces.
- Retained Zolta technical namespaces, framework metadata keys, and existing imports for staged migration compatibility.

---

## [1.0.2] - 2026-07-06
### Fixed
- Prevented infinite recursion when value objects serialize string-named getters like `value`
- Aligned the specification base contract with abstract specification implementations

---

## [1.0.1] - 2026-04-28
### Fixed
- Pagination items type hint and property accessor visibility

---
