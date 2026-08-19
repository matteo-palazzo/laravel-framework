# Validation Benchmarks

These benchmarks compare validation rule compilation and execution across a
clean `master` baseline and the stateful parser/compiler prototype.

## Environment

```text
PHP 8.4.24
Baseline: upstream/master a7332b9fdc + #60908
Prototype: stateful parser/compiler + per-validator parser cache + #60908
Samples: three separate processes per dataset size
```

The local port of #60908 is applied to both implementations so wildcard
compilation does not dominate the comparison.

## Running the benchmarks

Run a scenario against a framework checkout and a Laravel application vendor
directory:

```shell
php tests/Benchmarks/Validation/benchmark.php \
    --framework=/path/to/laravel-framework \
    --vendor=/path/to/laravel/vendor \
    --scenario=wildcard-expansion \
    --rows=500,1000,3000,7000 \
    --iterations=3
```

Available scenarios:

- `flat-rule-parsing`: a large validator with concrete attributes and no
  wildcard rules.
- `rule-parsing`: five realistic wildcard attributes per row.
- `wildcard-expansion`: a configurable number of wildcard attributes, using
  `--rules` to set the count.

## Baseline results before compiler caching

### Large validator without wildcards

| Rows | Baseline build | Prototype build | Baseline run | Prototype run | Baseline total | Prototype total |
|---:|---:|---:|---:|---:|---:|---:|
| 500 | 8.56 ms | 7.24 ms | 60.10 ms | 28.85 ms | 68.67 ms | 36.10 ms |
| 1,000 | 14.68 ms | 16.82 ms | 106.98 ms | 58.13 ms | 121.65 ms | 74.95 ms |
| 3,000 | 45.95 ms | 53.56 ms | 314.52 ms | 179.47 ms | 360.47 ms | 233.03 ms |
| 7,000 | 118.93 ms | 166.31 ms | 713.49 ms | 430.01 ms | 832.42 ms | 596.32 ms |

The build measurements in this scenario showed more variability than the
wildcard scenario. At 7,000 rows, memory usage was 33.5 MB during execution and
the build peak was 38 MB for both implementations.

### Realistic wildcard validation

| Rows | Baseline build | Prototype build | Baseline run | Prototype run | Baseline total | Prototype total |
|---:|---:|---:|---:|---:|---:|---:|
| 500 | 10.23 ms | 10.94 ms | 49.59 ms | 27.01 ms | 59.82 ms | 37.95 ms |
| 1,000 | 21.28 ms | 21.80 ms | 98.95 ms | 53.66 ms | 120.23 ms | 75.45 ms |
| 3,000 | 65.37 ms | 63.71 ms | 298.71 ms | 153.65 ms | 364.08 ms | 217.36 ms |
| 7,000 | 171.67 ms | 172.54 ms | 677.33 ms | 358.60 ms | 849.00 ms | 531.14 ms |

At 7,000 rows, the prototype reduced execution time by 47.1 percent and total
time by 37.4 percent. Build time increased by 0.5 percent. Both implementations
used 26.5 MB during execution with a 31 MB build peak.

These results are the pre-compiler-cache reference for subsequent experiments.

## Stateful compiler cache

The compiler caches the expanded form of wildcard rule sets containing only
strings. The cache is reset for each `explode()` call. When an attribute does
not already have rules, the cached array is assigned directly and shared using
PHP copy-on-write. Overlapping rules still use the original merge path. Rule
objects, closures, and compilable rules continue to be prepared for every
concrete attribute.

### Parsing cache without shared assignment

The following A/B comparison was run in the same environment and session. The
first cached variant avoided repeated normalization but still called
`array_merge([], $merge)` for every expanded attribute.

| Rows | Uncached build | Cached build | Uncached run | Cached run | Uncached total | Cached total |
|---:|---:|---:|---:|---:|---:|---:|
| 500 | 10.43 ms | 9.27 ms | 27.25 ms | 26.37 ms | 37.68 ms | 35.65 ms |
| 1,000 | 21.54 ms | 17.92 ms | 53.02 ms | 51.27 ms | 74.56 ms | 69.18 ms |
| 3,000 | 63.80 ms | 54.03 ms | 154.69 ms | 152.81 ms | 218.49 ms | 206.84 ms |
| 7,000 | 175.46 ms | 162.90 ms | 431.85 ms | 386.79 ms | 607.30 ms | 549.68 ms |

At 7,000 rows, memory after the build decreased from 26.5 MB to 18.5 MB and
build peak decreased from 31 MB to 25 MB.

The 7,000-row result was repeated in reverse order with five processes per
variant to check the unexpected run-time difference:

| Variant | Build | Run | Total | Build memory | Build peak |
|---|---:|---:|---:|---:|---:|
| Uncached | 170.06 ms | 358.40 ms | 528.47 ms | 26.5 MB | 31 MB |
| Cached | 149.50 ms | 362.74 ms | 512.24 ms | 18.5 MB | 25 MB |

The repeated measurement reduced build time by 12.1 percent and total time by
3.1 percent. It revealed that avoiding normalization alone did not implement
the copy-on-write sharing measured in the earlier prototype.

### Final cache with copy-on-write sharing

The final implementation assigns the cached array directly when no rules have
already been compiled for the concrete attribute. Measurements use five
separate processes per variant at 7,000 rows.

| Scenario | Variant | Build | Run | Total | Build memory | Build peak |
|---|---|---:|---:|---:|---:|---:|
| Five realistic wildcard fields | Uncached | 170.06 ms | 358.40 ms | 528.47 ms | 26.5 MB | 31 MB |
| Five realistic wildcard fields | Cached | 142.32 ms | 353.24 ms | 495.56 ms | 18.5 MB | 25 MB |
| 17 wildcard fields | Uncached | 437.31 ms | 741.39 ms | 1,178.70 ms | 51 MB | 58.5 MB |
| 17 wildcard fields | Cached | 316.14 ms | 730.28 ms | 1,046.42 ms | 25 MB | 32.5 MB |

In the 17-wildcard stress scenario, retained memory decreased by 51.0 percent
and build peak decreased by 44.4 percent. Build time decreased by 27.7 percent.
The realistic scenario retained fewer allocator blocks as well, decreasing
from 26.5 MB to 18.5 MB. Run time remained effectively unchanged in both
scenarios, as expected for an optimization confined to compilation.
