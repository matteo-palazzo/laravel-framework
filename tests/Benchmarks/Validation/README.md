# Validation Benchmarks

These benchmarks compare validation rule compilation and execution across a
clean `master` baseline and the stateful parser/compiler prototype.

## Environment

```text
PHP 8.4.24
Baseline: upstream/master a7332b9fdc + #60908
Prototype: stateful parser/compiler + per-validator parser cache + #60908
Samples: three separate processes per dataset size; five for wildcard-expansion
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

## Results

The baseline is `upstream/master` at `a7332b9fdc` with the local port of
#60908. The new implementation includes the stateful parser/compiler split,
the per-validator parsing cache, and local copy-on-write sharing of prepared
wildcard rules.

Memory is the allocator memory retained after validator construction, measured
with `memory_get_usage(true)`. Negative deltas represent reductions.

### Large validator without wildcards

| Rows | Baseline build | New build | Δ build | Baseline total | New total | Δ total | Baseline memory | New memory | Δ memory |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 500 | 6.95 ms | 7.00 ms | +0.7% | 58.26 ms | 34.84 ms | -40.2% | 10 MB | 10 MB | 0.0% |
| 1,000 | 14.74 ms | 15.04 ms | +2.0% | 114.01 ms | 71.00 ms | -37.7% | 12 MB | 12 MB | 0.0% |
| 3,000 | 47.86 ms | 44.72 ms | -6.6% | 370.91 ms | 208.13 ms | -43.9% | 18 MB | 18 MB | 0.0% |
| 7,000 | 122.19 ms | 110.70 ms | -9.4% | 1,026.27 ms | 481.55 ms | -53.1% | 33.5 MB | 33.5 MB | 0.0% |

This scenario does not use wildcard rules, so the compiler cache does not
change memory usage. The total-time reduction comes from the per-validator
cache used while executing repeated string rules.

### Five realistic wildcard fields

| Rows | Baseline build | New build | Δ build | Baseline total | New total | Δ total | Baseline memory | New memory | Δ memory |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 500 | 11.20 ms | 9.60 ms | -14.2% | 62.69 ms | 36.12 ms | -42.4% | 8 MB | 8 MB | 0.0% |
| 1,000 | 20.82 ms | 18.12 ms | -13.0% | 117.34 ms | 69.09 ms | -41.1% | 10 MB | 10 MB | 0.0% |
| 3,000 | 62.84 ms | 54.38 ms | -13.5% | 355.95 ms | 206.77 ms | -41.9% | 16 MB | 14 MB | -12.5% |
| 7,000 | 171.67 ms | 146.59 ms | -14.6% | 852.50 ms | 508.72 ms | -40.3% | 26.5 MB | 18.5 MB | -30.2% |

### Wildcard expansion with 17 fields

| Rows | Baseline build | New build | Δ build | Baseline total | New total | Δ total | Baseline memory | New memory | Δ memory |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 500 | 22.97 ms | 20.34 ms | -11.5% | 87.69 ms | 71.55 ms | -18.4% | 12 MB | 10 MB | -16.7% |
| 1,000 | 48.49 ms | 38.45 ms | -20.7% | 185.38 ms | 141.49 ms | -23.7% | 14 MB | 12 MB | -14.3% |
| 3,000 | 230.08 ms | 124.48 ms | -45.9% | 679.36 ms | 430.24 ms | -36.7% | 26.5 MB | 16.5 MB | -37.7% |
| 7,000 | 428.76 ms | 309.34 ms | -27.9% | 1,391.87 ms | 1,037.83 ms | -25.4% | 51 MB | 25 MB | -51.0% |

The 17-field scenario uses five processes per size because an initial
three-process run contained a build-time outlier at 3,000 rows. At 7,000 rows,
the new implementation halves retained memory and reduces build time by 27.9
percent. The build peak also decreases from 58.5 MB to 32.5 MB.
