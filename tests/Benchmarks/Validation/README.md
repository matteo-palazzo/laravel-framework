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
