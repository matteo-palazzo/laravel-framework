<?php

namespace Illuminate\Validation;

use Closure;
use Illuminate\Contracts\Validation\CompilableRules;
use Illuminate\Contracts\Validation\InvokableRule;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Validation\Rules\StringRule;
use Illuminate\Validation\Rules\Unique;
use stdClass;

class ValidationRuleCompiler
{
    /**
     * The data being validated.
     */
    public array $data;

    /**
     * The implicit attributes.
     */
    public array $implicitAttributes = [];

    /**
     * The compiled wildcard rules.
     */
    private array $compiledWildcardRules = [];

    /**
     * Create a new validation rule compiler.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Compile the human-friendly rules for the validator.
     */
    public function compile(array $rules, ?array $data = null): stdClass
    {
        return $this->explode($this->filterConditionalRules($rules, $data ?? $this->data));
    }

    /**
     * Parse the human-friendly rules into a full rules array for the validator.
     */
    public function explode(array $rules): stdClass
    {
        $this->implicitAttributes = [];

        $this->compiledWildcardRules = [];

        $rules = $this->explodeRules($rules);

        return (object) [
            'rules' => $rules,
            'implicitAttributes' => $this->implicitAttributes,
        ];
    }

    /**
     * Explode the rules into an array of explicit rules.
     */
    protected function explodeRules(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            if (str_contains($key, '*')) {
                $rules = $this->explodeWildcardRules($rules, $key, [$rule]);

                unset($rules[$key]);
            } else {
                $rules[$key] = $this->explodeExplicitRule($rule, $key);
            }
        }

        return $rules;
    }

    /**
     * Explode the explicit rule into an array if necessary.
     */
    protected function explodeExplicitRule(mixed $rule, string $attribute): array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        if (is_object($rule)) {
            if ($rule instanceof Date || $rule instanceof Numeric || $rule instanceof StringRule) {
                return explode('|', (string) $rule);
            }

            return Arr::wrap($this->prepareRule($rule, $attribute));
        }

        $rules = [];

        foreach ($rule as $value) {
            if ($value instanceof Date || $value instanceof Numeric || $value instanceof StringRule) {
                $rules = array_merge($rules, explode('|', (string) $value));
            } else {
                $rules[] = $this->prepareRule($value, $attribute);
            }
        }

        return $rules;
    }

    /**
     * Prepare the given rule for the Validator.
     */
    protected function prepareRule(mixed $rule, string $attribute): mixed
    {
        if ($rule instanceof Closure) {
            $rule = new ClosureValidationRule($rule);
        }

        if ($rule instanceof InvokableRule || $rule instanceof ValidationRule) {
            $rule = InvokableValidationRule::make($rule);
        }

        if (! is_object($rule) ||
            $rule instanceof RuleContract ||
            ($rule instanceof Exists && $rule->queryCallbacks()) ||
            ($rule instanceof Unique && $rule->queryCallbacks())) {
            return $rule;
        }

        if ($rule instanceof CompilableRules) {
            return $rule->compile(
                $attribute, $this->data[$attribute] ?? null, Arr::dot($this->data), $this->data
            )->rules[$attribute];
        }

        return (string) $rule;
    }

    /**
     * Define a set of rules that apply to each element in an array attribute.
     */
    protected function explodeWildcardRules(array $results, string $attribute, string|array $rules): array
    {
        $pattern = str_replace('\*', '[^\.]*', preg_quote($attribute, '/'));

        $data = ValidationData::initializeAndGatherData($attribute, $this->data);

        foreach ((array) $rules as $index => $rule) {
            if ($this->isCacheableRule($rule)) {
                $this->compiledWildcardRules[$attribute][$index] = head($this->explodeRules([$rule]));
            }
        }

        foreach ($data as $key => $value) {
            if (Str::startsWith($key, $attribute) || (bool) preg_match('/^'.$pattern.'\z/', $key)) {
                foreach ((array) $rules as $index => $rule) {
                    if ($rule instanceof CompilableRules) {
                        $context = Arr::get($this->data, Str::beforeLast($key, '.'));

                        $compiled = $rule->compile($key, $value, $data, $context);

                        $this->implicitAttributes = array_merge_recursive(
                            $compiled->implicitAttributes,
                            $this->implicitAttributes,
                            [$attribute => [$key]]
                        );

                        foreach ($compiled->rules as $compiledAttribute => $compiledRules) {
                            $this->mergeRulesForAttributeInto($results, $compiledAttribute, $compiledRules);
                        }
                    } else {
                        $this->implicitAttributes[$attribute][] = $key;

                        $this->mergeRulesForAttributeInto(
                            $results, $key, $rule, $this->compiledWildcardRules[$attribute][$index] ?? null
                        );
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Merge additional rules into a given attribute by reference.
     */
    private function mergeRulesForAttributeInto(
        array &$results,
        string $attribute,
        string|array $rules,
        ?array $merge = null,
    ): void {
        if ($merge !== null && ! isset($results[$attribute])) {
            $results[$attribute] = $merge;

            return;
        }

        $merge ??= head($this->explodeRules([$rules]));

        $results[$attribute] = array_merge(
            isset($results[$attribute]) ? $this->explodeExplicitRule($results[$attribute], $attribute) : [],
            $merge,
        );
    }

    /**
     * Determine if the rule can be compiled once for every wildcard match.
     */
    private function isCacheableRule(mixed $rule): bool
    {
        if (is_string($rule)) {
            return true;
        }

        if (! is_array($rule)) {
            return false;
        }

        foreach ($rule as $value) {
            if (! is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Expand the conditional rules in the given array of rules.
     */
    public function filterConditionalRules(array $rules, array $data = []): array
    {
        return (new Collection($rules))->mapWithKeys(function ($attributeRules, $attribute) use ($data) {
            if (! is_array($attributeRules) &&
                ! $attributeRules instanceof ConditionalRules) {
                return [$attribute => $attributeRules];
            }

            if ($attributeRules instanceof ConditionalRules) {
                return [$attribute => $attributeRules->passes($data)
                    ? array_filter($attributeRules->rules($data))
                    : array_filter($attributeRules->defaultRules($data)), ];
            }

            return [$attribute => (new Collection($attributeRules))->map(function ($rule) use ($data) {
                if (! $rule instanceof ConditionalRules) {
                    return [$rule];
                }

                return $rule->passes($data) ? $rule->rules($data) : $rule->defaultRules($data);
            })->filter()->flatten(1)->values()->all()];
        })->all();
    }
}
