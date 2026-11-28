# Pinned DCAT-AP 3.0.1 assets

Retrieved 2026-11-09. `manifest.json` records exact sources, upstream revision and SHA-256 checksums. The original SEMIC SHACL files are unchanged apart from normalized line endings. Official source: https://github.com/SEMICeu/DCAT-AP/tree/4470b8ea907b7eb9ec55ab22befd1ecd2210ff32/releases/3.0.1

The test validator uses `shacl/dcat-ap-SHACL.ttl`, which contains the generated 3.0.1 constraints and class ranges. The other official shapes are retained for comparison; some upstream hand-maintained headers still mention 3.0.0. ERNIE's exchange-scoped Dataset target selection is explicit in `scripts/dcat-ap/validate.py`; it does not modify these upstream files.

EU authority tables are the 20260923-0 versions returned by the Publications Office. The IANA Media Types registry reports an update date of 2026-11-05. Their exact source bytes are in deterministic `.gz` snapshots. `vocabularies/index.json` is generated from them, including ISO language notations and only unambiguous MIME-to-EU-file-type mappings. Use `python scripts/dcat-ap/build-vocabulary-index.py` to regenerate it offline.

See `docs/dcat-ap.md` for mapping, rollout, test commands and the distinction between full-graph and exchange-scoped validation.
